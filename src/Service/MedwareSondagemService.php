<?php

namespace App\Service;

/**
 * Sondagem somente leitura da API Medware para o painel de atendimentos.
 * Responde às pendências de docs/integracao-medware-atendimentos.md (seção B.12):
 * estrutura real do retorno, pageSize aceito, cancelados, profundidade do histórico,
 * volume por ano, semântica de ultimaDataHora e catálogo de procedimentos.
 *
 * O relatório NUNCA contém dados pessoais: dos itens só saem nomes de campos, tipos, formatos
 * (dígitos mascarados) e valores de uma lista fechada de campos não identificáveis (CAMPOS_SEGUROS).
 */
class MedwareSondagemService
{
    /** Campos cujo valor pode aparecer no relatório (não identificam paciente). */
    public const CAMPOS_SEGUROS = [
        'codStatusAgendamento', 'status', 'encaixe', 'retorno', 'resultadoPronto', 'codUnidade', 'intervalo',
        'procedimentoPlanoOperadora.codProcedimento', 'procedimentoPlanoOperadora.descricaoProcedimento',
        'procedimentoPlanoOperadora.codigoTuss', 'procedimentoPlanoOperadora.codigoProcedimento',
        'procedimentoPlanoOperadora.consulta', 'procedimentoPlanoOperadora.particular',
        'procedimentoPlanoOperadora.tipoProcedimento', 'procedimentoPlanoOperadora.descricaoPlano',
        'procedimentoPlanoOperadora.descricaoOperadora', 'procedimentoPlanoOperadora.descricaoPlanoOperadora',
        'medico.especialidade', 'paciente.sexo',
    ];

    private const PAGE_SIZE_GRANDE = 20000;

    /** @var callable(string): void|null */
    private $logger = null;

    public function __construct(private MedwareAgendamentoApiClient $api)
    {
    }

    /**
     * @param array{anoInicial?: int, comVolume?: bool, hoje?: \DateTimeInterface} $opcoes
     * @param callable(string): void|null $logger
     */
    public function executar(array $opcoes = [], ?callable $logger = null): array
    {
        $this->logger = $logger;
        $hoje = \DateTimeImmutable::createFromInterface($opcoes['hoje'] ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $ontem = $hoje->modify('-1 day');

        if ($this->api->isModoSimulacao()) {
            return ['erro' => 'Integração em modo simulação: a sondagem precisa da API real (rodar em produção).'];
        }

        $relatorio = ['executadoEm' => (new \DateTime())->format('Y-m-d H:i:s'), 'hoje' => $hoje->format('Y-m-d')];

        $this->log('1/7 Amostra dos últimos 7 dias (estrutura e valores seguros)...');
        $r = $this->api->listar($hoje->modify('-7 days'), $ontem, self::PAGE_SIZE_GRANDE);
        $itensAmostra = $r['ok'] && is_array($r['dados']) && array_is_list($r['dados']) ? $r['dados'] : [];
        $relatorio['amostra'] = [
            'periodo' => $hoje->modify('-7 days')->format('d/m/Y') . ' a ' . $ontem->format('d/m/Y'),
            'ok' => $r['ok'], 'erro' => $r['erro'], 'tempoMs' => $r['tempoMs'],
            'nivelSuperior' => $r['ok'] ? (array_is_list((array) $r['dados']) ? 'lista' : 'objeto: ' . implode(', ', array_keys((array) $r['dados']))) : null,
            'qtd' => count($itensAmostra),
            'porDia' => $this->contarPorDia($itensAmostra),
            'estrutura' => self::descreverEstrutura($itensAmostra),
            'valores' => self::valoresSeguros($itensAmostra),
        ];

        $this->log('2/7 pageSize: últimos 30 dias com 500 e com ' . self::PAGE_SIZE_GRANDE . '...');
        $a = $this->api->listar($hoje->modify('-30 days'), $ontem, MedwareAgendamentoApiClient::PAGE_SIZE_PADRAO_API);
        $b = $this->api->listar($hoje->modify('-30 days'), $ontem, self::PAGE_SIZE_GRANDE);
        $qa = $this->qtd($a);
        $qb = $this->qtd($b);
        $relatorio['pageSize'] = [
            'qtdCom500' => $qa, 'qtdComGrande' => $qb, 'pageSizeGrande' => self::PAGE_SIZE_GRANDE,
            'erros' => array_filter([$a['erro'], $b['erro']]),
            'tempoMsGrande' => $b['tempoMs'],
            'conclusao' => match (true) {
                $qa === null || $qb === null => 'inconclusivo (erro na consulta)',
                $qb > MedwareAgendamentoApiClient::PAGE_SIZE_PADRAO_API => 'pageSize maior que 500 é respeitado',
                $qa < MedwareAgendamentoApiClient::PAGE_SIZE_PADRAO_API => 'inconclusivo: o período tem menos de 500 registros',
                default => 'ATENÇÃO: pageSize maior parece ignorado (limite de 500)',
            },
            'maxPorDia' => $b['ok'] ? max([0, ...array_values($this->contarPorDia($b['dados']))]) : null,
        ];

        $this->log('3/7 Ativos x cancelados (ontem)...');
        $relatorio['cancelados'] = [
            'dia' => $ontem->format('d/m/Y'),
            'listarSemStatus' => $this->qtd($this->api->listar($ontem, $ontem, self::PAGE_SIZE_GRANDE)),
            'listarAtivos' => $this->qtd($this->api->listar($ontem, $ontem, self::PAGE_SIZE_GRANDE, ['status' => -1])),
            'listarCancelados' => $this->qtd($this->api->listar($ontem, $ontem, self::PAGE_SIZE_GRANDE, ['status' => 0])),
            'resumidoSemStatus' => $this->qtd($this->api->get(MedwareAgendamentoApiClient::ENDPOINT_LISTAR_RESUMIDO, [
                'dataInicio' => $ontem->format('d/m/Y'), 'dataFim' => $ontem->format('d/m/Y'), 'pageSize' => self::PAGE_SIZE_GRANDE,
            ])),
        ];

        $this->log('4/7 Profundidade do histórico (um pedido por ano)...');
        $anos = [];
        $primeiroAno = null;
        for ($ano = $opcoes['anoInicial'] ?? 2000; $ano <= (int) $hoje->format('Y'); $ano++) {
            $r = $this->api->listar(new \DateTimeImmutable("$ano-01-01"), new \DateTimeImmutable("$ano-12-31"), 1);
            $anos[$ano] = $r['ok'] ? ($this->qtd($r) > 0 ? 'com dados' : 'vazio') : 'erro: ' . mb_substr((string) $r['erro'], 0, 200);
            if ($primeiroAno === null && $this->qtd($r) > 0) {
                $primeiroAno = $ano;
            }
        }
        $primeiroMes = null;
        if ($primeiroAno !== null) {
            for ($mes = 1; $mes <= 12 && $primeiroMes === null; $mes++) {
                $ini = new \DateTimeImmutable(sprintf('%d-%02d-01', $primeiroAno, $mes));
                if ($this->qtd($this->api->listar($ini, $ini->modify('last day of this month'), 1)) > 0) {
                    $primeiroMes = $ini->format('m/Y');
                }
            }
        }
        $relatorio['profundidade'] = ['porAno' => $anos, 'primeiroAno' => $primeiroAno, 'primeiroMes' => $primeiroMes];

        if ($opcoes['comVolume'] ?? true) {
            $this->log('5/7 Volume por ano (ListarResumido)...');
            $volume = [];
            for ($ano = $primeiroAno ?? (int) $hoje->format('Y'); $ano <= (int) $hoje->format('Y'); $ano++) {
                $fim = min(new \DateTimeImmutable("$ano-12-31"), $hoje);
                $r = $this->api->get(MedwareAgendamentoApiClient::ENDPOINT_LISTAR_RESUMIDO, [
                    'dataInicio' => "01/01/$ano", 'dataFim' => $fim->format('d/m/Y'), 'pageSize' => 500000,
                ], 2, 300.0);
                $volume[$ano] = $r['ok'] && is_array($r['dados']) ? self::volumeResumido($r['dados']) : ['erro' => mb_substr((string) $r['erro'], 0, 200)];
            }
            $relatorio['volumePorAno'] = $volume;
        }

        $this->log('6/7 ultimaDataHora (alterados desde ontem)...');
        $r = $this->api->get(MedwareAgendamentoApiClient::ENDPOINT_LISTAR, ['ultimaDataHora' => $ontem->format('d/m/Y'), 'pageSize' => self::PAGE_SIZE_GRANDE]);
        $relatorio['ultimaDataHora'] = [
            'parametro' => $ontem->format('d/m/Y'),
            'ok' => $r['ok'], 'erro' => $r['erro'], 'qtd' => $this->qtd($r),
            'porMesAgendado' => $r['ok'] && is_array($r['dados']) ? $this->contarPorMes($r['dados']) : [],
        ];

        $this->log('7/7 Catálogo de procedimentos (ProcedPlanoOp/Listar)...');
        $r = $this->api->get(MedwareAgendamentoApiClient::ENDPOINT_PROCEDIMENTOS, [], 2, 300.0);
        $relatorio['procedimentos'] = $r['ok'] && is_array($r['dados']) ? self::catalogoProcedimentos($r['dados']) : ['erro' => $r['erro']];

        return $relatorio;
    }

    /**
     * Mapa caminho → tipos encontrados, quantos itens preenchem o campo e, para datas, o formato mascarado.
     */
    public static function descreverEstrutura(array $itens): array
    {
        $campos = [];
        foreach ($itens as $item) {
            if (is_array($item)) {
                self::percorrer($item, '', $campos);
            }
        }
        ksort($campos);
        $total = count($itens);
        foreach ($campos as &$c) {
            $c['tipos'] = array_keys($c['tipos']);
            $c['formatos'] = array_slice(array_keys($c['formatos']), 0, 5);
            $c['preenchidos'] = $c['preenchidos'] . '/' . $total;
        }

        return $campos;
    }

    /** Distribuição de valores dos campos seguros (até 60 valores distintos por campo). */
    public static function valoresSeguros(array $itens): array
    {
        $valores = [];
        foreach ($itens as $item) {
            foreach (self::CAMPOS_SEGUROS as $caminho) {
                $v = self::valor($item, $caminho);
                if ($v === '__ausente__') {
                    continue;
                }
                $chave = is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v);
                $valores[$caminho][$chave] = ($valores[$caminho][$chave] ?? 0) + 1;
            }
        }
        foreach ($valores as &$v) {
            arsort($v);
            $v = array_slice($v, 0, 60, true);
        }

        return $valores;
    }

    public static function volumeResumido(array $itens): array
    {
        $porStatus = [];
        $ativosRealizados = 0;
        foreach ($itens as $i) {
            $cod = $i['codStatusAgendamento'] ?? 'null';
            $sit = ($i['status'] ?? null) === 0 ? 'cancelado' : 'ativo';
            $porStatus["$sit:$cod"] = ($porStatus["$sit:$cod"] ?? 0) + 1;
            if ($sit === 'ativo' && in_array($cod, [4, 5], true)) {
                $ativosRealizados++;
            }
        }
        ksort($porStatus);

        return ['total' => count($itens), 'ativosAtendidosOuLiberados' => $ativosRealizados, 'porSituacaoEstagio' => $porStatus];
    }

    public static function catalogoProcedimentos(array $itens): array
    {
        $procs = [];
        foreach ($itens as $p) {
            $cod = $p['codProcedimento'] ?? null;
            if ($cod === null) {
                continue;
            }
            $procs[$cod] ??= [
                'codProcedimento' => $cod,
                'codigoProcedimento' => $p['codigoProcedimento'] ?? null,
                'descricao' => $p['descricaoProcedimento'] ?? null,
                'consulta' => $p['consulta'] ?? null,
                'tipoProcedimento' => $p['tipoProcedimento'] ?? null,
                'planos' => 0,
            ];
            $procs[$cod]['planos']++;
        }
        ksort($procs);

        return ['registros' => count($itens), 'distintos' => count($procs), 'lista' => array_values($procs)];
    }

    private static function percorrer(array $dados, string $prefixo, array &$campos): void
    {
        foreach ($dados as $k => $v) {
            $caminho = $prefixo === '' ? (string) $k : $prefixo . '.' . $k;
            if (is_array($v) && !array_is_list($v)) {
                self::percorrer($v, $caminho, $campos);
                continue;
            }
            if (is_array($v)) {
                $campos[$caminho]['tipos']['lista'] = true;
                $campos[$caminho]['preenchidos'] = ($campos[$caminho]['preenchidos'] ?? 0) + ($v ? 1 : 0);
                $campos[$caminho]['formatos'] ??= [];
                if (isset($v[0]) && is_array($v[0])) {
                    self::percorrer($v[0], $caminho . '[]', $campos);
                }
                continue;
            }
            $campos[$caminho]['tipos'][get_debug_type($v)] = true;
            $campos[$caminho]['formatos'] ??= [];
            $preenchido = $v !== null && $v !== '';
            $campos[$caminho]['preenchidos'] = ($campos[$caminho]['preenchidos'] ?? 0) + ($preenchido ? 1 : 0);
            if ($preenchido && is_string($v) && preg_match('/data|hora|nasc/i', (string) $k)) {
                $campos[$caminho]['formatos'][preg_replace('/\d/', '9', mb_substr($v, 0, 25))] = true;
            }
        }
    }

    private static function valor(mixed $item, string $caminho): mixed
    {
        foreach (explode('.', $caminho) as $parte) {
            if (!is_array($item) || !array_key_exists($parte, $item)) {
                return '__ausente__';
            }
            $item = $item[$parte];
        }

        return $item;
    }

    private function contarPorDia(mixed $itens): array
    {
        return $this->contarPorData($itens, 10);
    }

    private function contarPorMes(mixed $itens): array
    {
        return $this->contarPorData($itens, 7);
    }

    /** Agrupa por dataHoraAgendada (dd/MM/yyyy...) truncada em Y-m-d (10) ou Y-m (7). */
    private function contarPorData(mixed $itens, int $tamanho): array
    {
        $contagem = [];
        foreach (is_array($itens) && array_is_list($itens) ? $itens : [] as $i) {
            $d = is_array($i) ? (string) ($i['dataHoraAgendada'] ?? '') : '';
            $chave = preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $d, $m) ? substr("$m[3]-$m[2]-$m[1]", 0, $tamanho) : 'sem data';
            $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
        }
        ksort($contagem);

        return $contagem;
    }

    private function qtd(array $r): ?int
    {
        if (!$r['ok'] || !is_array($r['dados'])) {
            return null;
        }

        return array_is_list($r['dados']) ? count($r['dados']) : null;
    }

    private function log(string $msg): void
    {
        if ($this->logger) {
            ($this->logger)($msg);
        }
    }
}
