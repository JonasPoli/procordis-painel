<?php

namespace App\Service;

use App\Repository\AtendimentoCapturaDiaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Carga histórica bruta de /Medware/Agendamento/Listar, dia a dia, para atendimento_captura_dia.
 *
 * Regras (docs/integracao-medware-atendimentos.md, seção 5.1):
 * - A API não pagina: se o retorno atinge o pageSize, o dia é tratado como truncado e refeito com pageSize maior.
 * - Retorno de exatamente PAGE_SIZE_PADRAO_API registros com pageSize maior também é suspeito
 *   (o servidor pode estar ignorando o pageSize pedido).
 * - Dia só fica "completo" sem truncamento; dias completos são pulados nas próximas execuções (retomada).
 * - Falha nunca apaga um dia já capturado por completo.
 *
 * Grava via DBAL para não acumular payloads grandes no EntityManager.
 */
class AtendimentoHistoricoService
{
    public function __construct(
        private MedwareAgendamentoApiClient $api,
        private AtendimentoCapturaDiaRepository $repo,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{data: string, qtd: int, pageSize: int, completo: bool, erro: ?string, tempoMs: int}
     */
    public function capturarDia(\DateTimeInterface $dia, int $pageSize = 1000, int $pageSizeMax = 20000): array
    {
        $data = $dia->format('Y-m-d');
        $ps = max(1, $pageSize);
        $tentativas = 0;
        $tempoMs = 0;

        while (true) {
            $r = $this->api->listar($dia, $dia, $ps);
            $tentativas += max(1, $r['tentativas']);
            $tempoMs += $r['tempoMs'];

            if (!$r['ok']) {
                $this->gravarErro($data, $ps, $r['status'], $r['erro'] ?? 'Erro desconhecido', $tempoMs, $tentativas);

                return ['data' => $data, 'qtd' => 0, 'pageSize' => $ps, 'completo' => false, 'erro' => $r['erro'], 'tempoMs' => $tempoMs];
            }

            if (!array_is_list($r['dados'])) {
                $erro = 'Formato inesperado: a resposta não é uma lista';
                $this->gravar($data, $r['corpo'], 0, $ps, false, 200, $erro, $tempoMs, $tentativas);

                return ['data' => $data, 'qtd' => 0, 'pageSize' => $ps, 'completo' => false, 'erro' => $erro, 'tempoMs' => $tempoMs];
            }

            $qtd = count($r['dados']);
            $truncado = self::retornoTruncado($qtd, $ps);
            if ($truncado && $ps < $pageSizeMax) {
                $ps = min($ps * 4, $pageSizeMax);
                continue;
            }

            $erro = $truncado ? sprintf('Retorno truncado: %d registros com pageSize %d (limite %d atingido)', $qtd, $ps, $pageSizeMax) : null;
            $this->gravar($data, $r['corpo'], $qtd, $ps, !$truncado, 200, $erro, $tempoMs, $tentativas);

            return ['data' => $data, 'qtd' => $qtd, 'pageSize' => $ps, 'completo' => !$truncado, 'erro' => $erro, 'tempoMs' => $tempoMs];
        }
    }

    /**
     * Captura todos os dias do intervalo, pulando os já completos (exceto com refazer).
     *
     * @param array{pageSize?: int, pageSizeMax?: int, refazer?: bool, pausaMs?: int, limiteDias?: ?int} $opcoes
     * @param callable(array $resultadoDia, int $indice, int $total): void|null $progresso
     */
    public function capturarPeriodo(\DateTimeInterface $de, \DateTimeInterface $ate, array $opcoes = [], ?callable $progresso = null): array
    {
        $pageSize = $opcoes['pageSize'] ?? 1000;
        $pageSizeMax = $opcoes['pageSizeMax'] ?? 20000;
        $refazer = $opcoes['refazer'] ?? false;
        $pausaMs = $opcoes['pausaMs'] ?? 200;
        $limiteDias = $opcoes['limiteDias'] ?? null;

        $dias = self::dias($de, $ate);
        $completos = $refazer ? [] : $this->repo->datasCompletas($de, $ate);
        $pendentes = array_values(array_filter($dias, fn (\DateTimeImmutable $d) => !isset($completos[$d->format('Y-m-d')])));
        $resumo = ['dias' => count($dias), 'pulados' => count($dias) - count($pendentes), 'capturados' => 0, 'completos' => 0, 'incompletos' => [], 'erros' => [], 'registros' => 0];
        if ($limiteDias !== null) {
            $pendentes = array_slice($pendentes, 0, max(0, $limiteDias));
        }

        foreach ($pendentes as $i => $dia) {
            $r = $this->capturarDia($dia, $pageSize, $pageSizeMax);
            $resumo['capturados']++;
            $resumo['registros'] += $r['qtd'];
            if ($r['completo']) {
                $resumo['completos']++;
            } elseif (str_starts_with((string) $r['erro'], 'Retorno truncado')) {
                $resumo['incompletos'][] = $r['data'];
            } else {
                $resumo['erros'][] = $r['data'] . ': ' . $r['erro'];
            }

            if ($progresso) {
                $progresso($r, $i + 1, count($pendentes));
            }
            if ($pausaMs > 0 && $i < count($pendentes) - 1) {
                usleep($pausaMs * 1000);
            }
        }

        return $resumo;
    }

    public static function retornoTruncado(int $qtd, int $pageSize): bool
    {
        return $qtd >= $pageSize
            || ($pageSize > MedwareAgendamentoApiClient::PAGE_SIZE_PADRAO_API && $qtd === MedwareAgendamentoApiClient::PAGE_SIZE_PADRAO_API);
    }

    /** @return \DateTimeImmutable[] */
    public static function dias(\DateTimeInterface $de, \DateTimeInterface $ate): array
    {
        $dias = [];
        $cursor = \DateTimeImmutable::createFromInterface($de)->setTime(0, 0);
        $fim = \DateTimeImmutable::createFromInterface($ate)->setTime(0, 0);
        while ($cursor <= $fim) {
            $dias[] = $cursor;
            $cursor = $cursor->modify('+1 day');
        }

        return $dias;
    }

    private function gravar(string $data, ?string $corpo, int $qtd, int $pageSize, bool $completo, int $status, ?string $erro, int $tempoMs, int $tentativas): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO atendimento_captura_dia (data, payload, hash_conteudo, qtd_registros, page_size, completo, http_status, erro, tempo_ms, tentativas, capturado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), hash_conteudo = VALUES(hash_conteudo), qtd_registros = VALUES(qtd_registros),
                page_size = VALUES(page_size), completo = VALUES(completo), http_status = VALUES(http_status), erro = VALUES(erro),
                tempo_ms = VALUES(tempo_ms), tentativas = VALUES(tentativas), capturado_em = VALUES(capturado_em)',
            [$data, $corpo, $corpo !== null ? hash('sha256', $corpo) : null, $qtd, $pageSize, (int) $completo, $status, $erro, $tempoMs, $tentativas, (new \DateTime())->format('Y-m-d H:i:s')]
        );
    }

    /** Registra a falha sem sobrescrever um dia que já estava completo. */
    private function gravarErro(string $data, int $pageSize, int $status, string $erro, int $tempoMs, int $tentativas): void
    {
        $conn = $this->em->getConnection();
        $completo = $conn->fetchOne('SELECT completo FROM atendimento_captura_dia WHERE data = ?', [$data]);
        if ((int) $completo === 1) {
            return;
        }
        if ($completo === false) {
            $this->gravar($data, null, 0, $pageSize, false, $status, mb_substr($erro, 0, 2000), $tempoMs, $tentativas);

            return;
        }
        $conn->executeStatement(
            'UPDATE atendimento_captura_dia SET http_status = ?, erro = ?, tempo_ms = ?, tentativas = tentativas + ?, capturado_em = ? WHERE data = ?',
            [$status, mb_substr($erro, 0, 2000), $tempoMs, $tentativas, (new \DateTime())->format('Y-m-d H:i:s'), $data]
        );
    }
}
