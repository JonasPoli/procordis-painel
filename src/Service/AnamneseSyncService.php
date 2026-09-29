<?php

namespace App\Service;

use App\Entity\AnamneseSyncExecucao;
use App\Entity\ClassificacaoEstudo;
use App\Repository\AnamneseSyncExecucaoRepository;
use App\Repository\ClassificacaoEstudoRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sincronização da anamnese (ClassificacaoEstudo) com a base local.
 *
 * - incremental: catálogo + janela dos últimos N dias (padrão 7). Rápido; roda 7h, 12h e 18h.
 * - completo:    catálogo + todo o histórico (janelas anuais), reconciliação de remoções,
 *                atualização dos agendamentos recentes e carga dos agendamentos que faltam
 *                para ter sexo/idade/procedimento dos pacientes. Roda às 2h.
 *
 * Nada é apagado: o que some da API recebe removido_em; se voltar, removido_em é limpo.
 */
class AnamneseSyncService
{
    public const ORIGEM = '/Paciente/ClassificacoesEstudo';

    private const HORAS_LOCK = 6;

    /** @var callable|null */
    private $logger = null;

    /** codPaciente => true: nome já aplicado nesta execução (evita alternar nomes divergentes na mesma resposta) */
    private array $nomesAplicados = [];

    /** @var array<int, int> codClassificacao => id */
    private array $mapaClassificacoes = [];

    private array $erros = [];

    public function __construct(
        private EntityManagerInterface $em,
        private ProcordisAnamneseApiClient $api,
        private PacienteRegistroService $pacienteRegistro,
        private MedwareApiClientService $medwareClient,
        private ClassificacaoEstudoRepository $classificacaoRepo,
        private AnamneseSyncExecucaoRepository $execucaoRepo,
        private AnamneseSimuladorService $simulador,
    ) {
    }

    /**
     * @param array{dias?: int, desde?: string, diasAgendamentos?: int, maxDiasBackfill?: int, forcar?: bool} $opcoes
     */
    public function executar(string $modo, string $origem = 'cli', array $opcoes = [], ?callable $logger = null): AnamneseSyncExecucao
    {
        $this->logger = $logger;
        $this->erros = [];
        $this->nomesAplicados = [];
        $this->mapaClassificacoes = [];

        if (!in_array($modo, ['incremental', 'completo'], true)) {
            throw new \InvalidArgumentException('Modo inválido: use incremental ou completo.');
        }

        $emAndamento = $this->execucaoEmAndamento();
        if ($emAndamento && empty($opcoes['forcar'])) {
            throw new \RuntimeException(sprintf(
                'Já existe uma sincronização em andamento (#%d, iniciada em %s). Use --forcar se ela travou.',
                $emAndamento->getId(),
                $emAndamento->getIniciadoEm()->format('d/m/Y H:i')
            ));
        }

        $execucao = new AnamneseSyncExecucao();
        $execucao->setModo($this->api->isModoSimulacao() ? 'simulacao' : $modo);
        $execucao->setOrigem($origem);
        $this->em->persist($execucao);
        $this->em->flush();
        $execucaoId = $execucao->getId();
        $inicio = $execucao->getIniciadoEm();

        $resumo = ['modo' => $modo];
        try {
            if ($this->api->isModoSimulacao()) {
                $this->log('Integração em modo simulação: gerando dados simulados de anamnese.');
                $resumo['simulacao'] = $modo === 'completo'
                    ? $this->simulador->garantirBaseSimulada()
                    : $this->simulador->gerarExamesDoDia(new \DateTime());
            } elseif ($modo === 'incremental') {
                $resumo['catalogo'] = $this->sincronizarCatalogo();
                $dias = max(1, (int) ($opcoes['dias'] ?? 7));
                $fim = (new \DateTime())->setTime(23, 59, 59);
                $ini = (new \DateTime())->modify('-' . ($dias - 1) . ' days')->setTime(0, 0, 0);
                $resumo['janelas'][] = $this->sincronizarJanela($ini, $fim, $inicio);
                $resumo['vinculados'] = $this->vincularAgendamentos();
            } else {
                $resumo['catalogo'] = $this->sincronizarCatalogo();
                $desde = new \DateTime($opcoes['desde'] ?? '2000-01-01');
                $hoje = (new \DateTime())->setTime(23, 59, 59);
                foreach ($this->janelasAnuais($desde, $hoje) as [$ini, $fim]) {
                    $resumo['janelas'][] = $this->sincronizarJanela($ini, $fim, $inicio);
                }
                $resumo['vinculados'] = $this->vincularAgendamentos();
                $resumo['agendamentosRecentes'] = $this->atualizarAgendamentosRecentes((int) ($opcoes['diasAgendamentos'] ?? 30));
                $resumo['backfill'] = $this->carregarAgendamentosFaltantes((int) ($opcoes['maxDiasBackfill'] ?? 300));
                $resumo['vinculados'] += $this->vincularAgendamentos();
            }

            $resumo['totais'] = $this->totaisBase();
            $status = $this->erros ? AnamneseSyncExecucao::STATUS_PARCIAL : AnamneseSyncExecucao::STATUS_SUCESSO;
        } catch (\Throwable $e) {
            $this->erros[] = 'Falha geral: ' . $e->getMessage();
            $status = AnamneseSyncExecucao::STATUS_ERRO;
        }

        // O EM pode ter sido limpo (sync de agendamentos faz clear); recarrega a execução.
        if (!$this->em->isOpen()) {
            throw new \RuntimeException('EntityManager fechado durante a sincronização: ' . implode(' | ', $this->erros));
        }
        $execucao = $this->execucaoRepo->find($execucaoId);
        $execucao->finalizar($status, $resumo, $this->erros);
        $this->em->flush();

        return $execucao;
    }

    public function execucaoEmAndamento(): ?AnamneseSyncExecucao
    {
        $limite = (new \DateTime())->modify('-' . self::HORAS_LOCK . ' hours');

        return $this->execucaoRepo->createQueryBuilder('e')
            ->where('e.status = :s')
            ->andWhere('e.iniciadoEm > :limite')
            ->setParameter('s', AnamneseSyncExecucao::STATUS_EM_ANDAMENTO)
            ->setParameter('limite', $limite)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array{total: int, novos: int, alterados: int, ausentes: int, erro?: string}
     */
    public function sincronizarCatalogo(): array
    {
        $r = $this->api->listarCatalogo();
        if (!$r['ok']) {
            $this->erros[] = 'Catálogo: ' . $r['erro'];

            return ['total' => 0, 'novos' => 0, 'alterados' => 0, 'ausentes' => 0, 'erro' => $r['erro']];
        }

        $agora = new \DateTime();
        $novos = 0;
        $alterados = 0;
        $vistos = [];

        /** @var array<int, ClassificacaoEstudo> $existentes */
        $existentes = [];
        foreach ($this->classificacaoRepo->findAll() as $c) {
            $existentes[$c->getCodClassificacao()] = $c;
        }

        foreach ($r['itens'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cod = $this->valor($item, ['codClassificacao', 'codClassificacaoEstudo', 'codigoClassificacao', 'id', 'cod']);
            $nome = $this->valor($item, ['classificacao', 'descricao', 'nome', 'descricaoClassificacao']);
            if ($cod === null || $nome === null) {
                continue;
            }
            $cod = (int) $cod;
            $vistos[$cod] = true;

            $c = $existentes[$cod] ?? null;
            if (!$c) {
                $c = new ClassificacaoEstudo();
                $c->setCodClassificacao($cod);
                $c->setCategoria(ClassificacaoEstudo::sugerirCategoria((string) $nome));
                $this->em->persist($c);
                $existentes[$cod] = $c;
                $novos++;
            } elseif ($c->getNome() !== trim((string) $nome)) {
                $alterados++;
            }

            $c->setNome((string) $nome);
            $codigo = $this->valor($item, ['codigo']);
            $c->setCodigo($codigo !== null ? (string) $codigo : null);
            $tipo = $this->valor($item, ['tipo']);
            $c->setTipo($tipo !== null && is_numeric($tipo) ? (int) $tipo : null);
            if (!$c->isCategoriaManual()) {
                $c->setCategoria(ClassificacaoEstudo::sugerirCategoria($c->getNome()));
            }
            $c->setDadosBrutos($item);
            $c->setPresenteNaApi(true);
            $c->setUltimoVistoEm($agora);
        }

        $ausentes = 0;
        if ($vistos) {
            foreach ($existentes as $cod => $c) {
                if (!isset($vistos[$cod]) && $c->isPresenteNaApi()) {
                    $c->setPresenteNaApi(false);
                    $ausentes++;
                }
            }
        }

        $this->em->flush();
        $this->log(sprintf('Catálogo: %d itens (%d novos, %d alterados, %d ausentes).', count($r['itens']), $novos, $alterados, $ausentes));

        return ['total' => count($r['itens']), 'novos' => $novos, 'alterados' => $alterados, 'ausentes' => $ausentes];
    }

    /**
     * Lê todas as páginas de um período, grava e — se a leitura foi completa — marca como removido
     * o que não veio mais na API dentro desse período.
     */
    public function sincronizarJanela(\DateTimeInterface $ini, \DateTimeInterface $fim, \DateTimeInterface $inicioExecucao): array
    {
        $stats = ['periodo' => $ini->format('d/m/Y') . ' a ' . $fim->format('d/m/Y'), 'lidos' => 0, 'novos' => 0, 'atualizados' => 0, 'reaparecidos' => 0, 'removidos' => 0, 'paginas' => 0, 'completa' => false];
        $pagina = 1;
        $total = null;
        $completa = false;

        while ($pagina <= 1000) {
            $r = $this->api->listarPorPeriodo($ini, $fim, $pagina);
            if (!$r['ok']) {
                $this->erros[] = 'Período ' . $stats['periodo'] . ' pág. ' . $pagina . ': ' . $r['erro'];
                break;
            }
            $stats['paginas']++;
            $total = $r['total'] ?? $total;
            $itens = $r['itens'];

            if ($itens) {
                $this->processarItens($itens, $stats);
                $stats['lidos'] += count($itens);
            }

            $tamanho = max(1, $r['tamanhoPagina']);
            if (!$itens || count($itens) < $tamanho || ($total !== null && $pagina * $tamanho >= $total)) {
                $completa = true;
                break;
            }
            $pagina++;
        }

        $stats['total'] = $total;

        // Leitura incompleta (erro no meio ou total informado maior que o lido) => não reconcilia.
        if ($completa && $total !== null && $stats['lidos'] < $total) {
            $completa = false;
            $this->erros[] = sprintf('Período %s: API informou %d registros mas foram lidos %d.', $stats['periodo'], $total, $stats['lidos']);
        }

        if ($completa) {
            $stats['removidos'] = $this->reconciliar($ini, $fim, $inicioExecucao, $stats['lidos']);
        }
        $stats['completa'] = $completa;

        if ($stats['lidos'] > 0 || $stats['removidos'] > 0) {
            $this->log(sprintf(
                'Período %s: %d lidos, %d novos, %d atualizados, %d reapareceram, %d removidos.',
                $stats['periodo'], $stats['lidos'], $stats['novos'], $stats['atualizados'], $stats['reaparecidos'], $stats['removidos']
            ));
        }

        return $stats;
    }

    /**
     * Grava um lote de itens da API.
     */
    public function processarItens(array $itens, array &$stats): void
    {
        $conn = $this->em->getConnection();
        $agora = (new \DateTime())->format('Y-m-d H:i:s');

        // 1. Normaliza e deduplica
        $linhas = [];
        $nomes = [];
        foreach ($itens as $item) {
            if (!is_array($item)) {
                continue;
            }
            $codPac = (int) ($item['codPaciente'] ?? 0);
            $codAg = trim((string) ($item['codAgendamento'] ?? ''));
            $codClass = (int) ($item['codClassificacao'] ?? 0);
            $data = $this->parseData($item['dataExame'] ?? null);
            if ($codPac <= 0 || $codAg === '' || $codClass <= 0 || !$data) {
                continue;
            }
            $linhas[$codAg . ':' . $codClass] = [
                'codPaciente' => $codPac,
                'codAgendamento' => $codAg,
                'codClassificacao' => $codClass,
                'dataExame' => $data->format('Y-m-d H:i:s'),
                'nome' => isset($item['nomePaciente']) ? mb_substr(trim((string) $item['nomePaciente']), 0, 255) : null,
                'item' => $item,
            ];
            if (!array_key_exists($codPac, $nomes)) {
                $nomes[$codPac] = isset($this->nomesAplicados[$codPac]) ? null : ($linhas[$codAg . ':' . $codClass]['nome'] ?: null);
            }
        }
        if (!$linhas) {
            return;
        }

        // 2. Pacientes (ORM, com histórico)
        $pacientes = $this->pacienteRegistro->garantirVarios($nomes, self::ORIGEM);
        $this->em->flush();
        $idsPacientes = [];
        foreach ($pacientes as $cod => $p) {
            $idsPacientes[$cod] = $p->getId();
            $this->nomesAplicados[$cod] = true;
        }

        // 3. Classificações (cria as que não estão no catálogo)
        $this->garantirClassificacoes(array_column($linhas, 'item'));

        // 4. Upsert do fato
        $codsAg = array_values(array_unique(array_column($linhas, 'codAgendamento')));
        $existentes = [];
        foreach (array_chunk($codsAg, 1000) as $lote) {
            $rows = $conn->fetchAllAssociative(
                'SELECT id, cod_agendamento, classificacao_id, paciente_id, data_exame, removido_em FROM exame_classificacao WHERE cod_agendamento IN (?)',
                [$lote],
                [ArrayParameterType::STRING]
            );
            foreach ($rows as $row) {
                $existentes[$row['cod_agendamento'] . ':' . $row['classificacao_id']] = $row;
            }
        }

        $conn->beginTransaction();
        try {
            $idsVistos = [];
            foreach ($linhas as $l) {
                $classId = $this->mapaClassificacoes[$l['codClassificacao']] ?? null;
                $pacId = $idsPacientes[$l['codPaciente']] ?? null;
                if (!$classId || !$pacId) {
                    continue;
                }
                $chave = $l['codAgendamento'] . ':' . $classId;
                $ex = $existentes[$chave] ?? null;

                if (!$ex) {
                    $conn->insert('exame_classificacao', [
                        'paciente_id' => $pacId,
                        'classificacao_id' => $classId,
                        'cod_agendamento' => $l['codAgendamento'],
                        'cod_paciente' => $l['codPaciente'],
                        'data_exame' => $l['dataExame'],
                        'nome_paciente_api' => $l['nome'],
                        'primeiro_visto_em' => $agora,
                        'ultimo_visto_em' => $agora,
                    ]);
                    $existentes[$chave] = ['id' => (int) $conn->lastInsertId(), 'paciente_id' => $pacId, 'data_exame' => $l['dataExame'], 'removido_em' => null];
                    $stats['novos']++;
                    continue;
                }

                $mudou = substr((string) $ex['data_exame'], 0, 19) !== $l['dataExame'] || (int) $ex['paciente_id'] !== $pacId;
                if ($mudou) {
                    $conn->update('exame_classificacao', [
                        'data_exame' => $l['dataExame'],
                        'paciente_id' => $pacId,
                        'cod_paciente' => $l['codPaciente'],
                        'nome_paciente_api' => $l['nome'],
                    ], ['id' => $ex['id']]);
                    $stats['atualizados']++;
                }
                if ($ex['removido_em'] !== null) {
                    $stats['reaparecidos']++;
                }
                $idsVistos[] = (int) $ex['id'];
            }

            foreach (array_chunk($idsVistos, 500) as $lote) {
                $conn->executeStatement(
                    'UPDATE exame_classificacao SET ultimo_visto_em = ?, removido_em = NULL WHERE id IN (?)',
                    [$agora, $lote],
                    [\Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::INTEGER]
                );
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        $this->em->clear();
    }

    /**
     * Marca como removidas as classificações do período que não vieram nesta execução.
     */
    private function reconciliar(\DateTimeInterface $ini, \DateTimeInterface $fim, \DateTimeInterface $inicioExecucao, int $lidos): int
    {
        $conn = $this->em->getConnection();
        $params = [$ini->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')];
        $ativosNoPeriodo = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM exame_classificacao WHERE removido_em IS NULL AND data_exame BETWEEN ? AND ?',
            $params
        );

        // Proteção: API "vazia" num período que tinha muitos dados = provável instabilidade, não exclusão real.
        if ($lidos === 0 && $ativosNoPeriodo > 20) {
            $this->erros[] = sprintf('Período %s–%s veio vazio da API mas havia %d registros locais; remoções não aplicadas.', $ini->format('d/m/Y'), $fim->format('d/m/Y'), $ativosNoPeriodo);

            return 0;
        }

        return (int) $conn->executeStatement(
            'UPDATE exame_classificacao SET removido_em = ? WHERE removido_em IS NULL AND data_exame BETWEEN ? AND ? AND ultimo_visto_em < ?',
            [(new \DateTime())->format('Y-m-d H:i:s'), $params[0], $params[1], $inicioExecucao->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Liga cada exame ao agendamento local de mesmo código (para sexo/idade/procedimento/convênio).
     */
    public function vincularAgendamentos(): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'UPDATE exame_classificacao SET agendamento_id = (SELECT MIN(a.id) FROM agendamento a WHERE a.codigo_agendamento = exame_classificacao.cod_agendamento)
             WHERE agendamento_id IS NULL AND EXISTS (SELECT 1 FROM agendamento a2 WHERE a2.codigo_agendamento = exame_classificacao.cod_agendamento)'
        );
    }

    private function atualizarAgendamentosRecentes(int $dias): array
    {
        if ($dias <= 0) {
            return ['dias' => 0];
        }
        $this->log("Atualizando agendamentos dos últimos {$dias} dias…");
        $res = ['dias' => $dias, 'registros' => 0];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $dia = (new \DateTime())->modify("-{$i} days");
            $r = $this->medwareClient->sincronizarAgendamentosHoje($dia, 1);
            $res['registros'] += $r['total'] ?? 0;
        }
        $this->pacienteRegistro->limparCache();

        return $res;
    }

    /**
     * Busca na API de agendamentos os dias que têm exames com anamnese mas sem agendamento local,
     * do mais recente para o mais antigo, até o limite por execução. Dias já tentados recentemente e
     * sem sucesso são pulados, para a carga avançar noite após noite.
     */
    private function carregarAgendamentosFaltantes(int $maxDias): array
    {
        if ($maxDias <= 0) {
            return ['dias' => 0];
        }
        $conn = $this->em->getConnection();
        $dias = $conn->fetchFirstColumn(
            'SELECT DISTINCT DATE(data_exame) AS d FROM exame_classificacao WHERE agendamento_id IS NULL AND removido_em IS NULL ORDER BY d DESC'
        );

        $jaTentados = $this->diasTentadosRecentemente();
        $pendentes = array_values(array_filter($dias, fn ($d) => !isset($jaTentados[$d])));
        $lote = array_slice($pendentes, 0, $maxDias);

        if ($lote) {
            $this->log(sprintf('Carregando agendamentos de %d dias sem vínculo (%d pendentes no total)…', count($lote), count($pendentes)));
        }
        $registros = 0;
        foreach ($lote as $d) {
            $r = $this->medwareClient->sincronizarAgendamentosHoje(new \DateTime($d), 1);
            $registros += $r['total'] ?? 0;
        }
        $this->pacienteRegistro->limparCache();

        return ['dias' => count($lote), 'pendentes' => count($pendentes) - count($lote), 'registros' => $registros, 'diasTentados' => $lote];
    }

    /** Dias já tentados no backfill nas últimas 3 semanas. */
    private function diasTentadosRecentemente(): array
    {
        $execs = $this->execucaoRepo->createQueryBuilder('e')
            ->where('e.iniciadoEm > :lim')
            ->andWhere('e.modo = :m')
            ->setParameter('lim', (new \DateTime())->modify('-21 days'))
            ->setParameter('m', 'completo')
            ->getQuery()
            ->getResult();

        $dias = [];
        foreach ($execs as $e) {
            foreach ($e->getResumo()['backfill']['diasTentados'] ?? [] as $d) {
                $dias[$d] = true;
            }
        }

        return $dias;
    }

    private function garantirClassificacoes(array $itens): void
    {
        $conn = $this->em->getConnection();
        if (!$this->mapaClassificacoes) {
            foreach ($conn->fetchAllAssociative('SELECT id, cod_classificacao FROM classificacao_estudo') as $r) {
                $this->mapaClassificacoes[(int) $r['cod_classificacao']] = (int) $r['id'];
            }
        }

        $agora = (new \DateTime())->format('Y-m-d H:i:s');
        foreach ($itens as $item) {
            $cod = (int) ($item['codClassificacao'] ?? 0);
            if ($cod <= 0 || isset($this->mapaClassificacoes[$cod])) {
                continue;
            }
            $nome = trim((string) ($item['classificacao'] ?? ('Classificação ' . $cod)));
            $conn->insert('classificacao_estudo', [
                'cod_classificacao' => $cod,
                'nome' => mb_substr($nome, 0, 255),
                'codigo' => isset($item['codigo']) ? mb_substr((string) $item['codigo'], 0, 50) : null,
                'tipo' => isset($item['tipo']) && is_numeric($item['tipo']) ? (int) $item['tipo'] : null,
                'categoria' => ClassificacaoEstudo::sugerirCategoria($nome),
                'categoria_manual' => 0,
                'ativo' => 1,
                'presente_na_api' => 1,
                'primeiro_visto_em' => $agora,
                'ultimo_visto_em' => $agora,
            ]);
            $this->mapaClassificacoes[$cod] = (int) $conn->lastInsertId();
        }
    }

    private function totaisBase(): array
    {
        $conn = $this->em->getConnection();

        return [
            'classificacoesAtivas' => (int) $conn->fetchOne('SELECT COUNT(*) FROM exame_classificacao WHERE removido_em IS NULL'),
            'exames' => (int) $conn->fetchOne('SELECT COUNT(DISTINCT cod_agendamento) FROM exame_classificacao WHERE removido_em IS NULL'),
            'pacientes' => (int) $conn->fetchOne('SELECT COUNT(DISTINCT paciente_id) FROM exame_classificacao WHERE removido_em IS NULL'),
            'semAgendamento' => (int) $conn->fetchOne('SELECT COUNT(DISTINCT cod_agendamento) FROM exame_classificacao WHERE removido_em IS NULL AND agendamento_id IS NULL'),
        ];
    }

    /** @return list<array{0: \DateTime, 1: \DateTime}> */
    private function janelasAnuais(\DateTimeInterface $desde, \DateTimeInterface $ate): array
    {
        $janelas = [];
        $cursor = (new \DateTime($desde->format('Y-m-d')))->setTime(0, 0, 0);
        while ($cursor <= $ate) {
            $fim = (new \DateTime($cursor->format('Y') . '-12-31'))->setTime(23, 59, 59);
            if ($fim > $ate) {
                $fim = \DateTime::createFromInterface($ate);
            }
            $janelas[] = [clone $cursor, $fim];
            $cursor = (new \DateTime(((int) $cursor->format('Y') + 1) . '-01-01'))->setTime(0, 0, 0);
        }

        return $janelas;
    }

    private function parseData(mixed $v): ?\DateTime
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        $v = trim($v);
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:s.u', 'Y-m-d H:i:s', 'Y-m-d'] as $f) {
            $d = \DateTime::createFromFormat('!' . $f, $v);
            if ($d && \DateTime::getLastErrors() === false) {
                return $d;
            }
            if ($d && (\DateTime::getLastErrors()['warning_count'] ?? 0) === 0) {
                return $d;
            }
        }

        return null;
    }

    private function valor(array $item, array $chaves): mixed
    {
        foreach ($chaves as $k) {
            if (array_key_exists($k, $item) && $item[$k] !== null && $item[$k] !== '') {
                return $item[$k];
            }
        }

        return null;
    }

    private function log(string $msg): void
    {
        if ($this->logger) {
            ($this->logger)($msg);
        }
    }
}
