<?php

namespace App\Service;

use App\Entity\ClassificacaoEstudo;

/**
 * Análises complementares da anamnese (somente agregados — nenhum nome de paciente sai daqui).
 *
 * Usa a mesma base e os mesmos filtros de AnamneseEstatisticaService. Regras:
 * - Unidade "paciente": cada paciente conta uma vez no período. Os itens são a união do que foi marcado
 *   nos exames do período; sexo e faixa etária vêm do exame mais recente.
 * - Unidade "exame": cada agendamento com anamnese. "Ocorrência de item": uma marcação num exame.
 * - Condições cardiovasculares e tipos de condição são reconhecidos pelo nome do item (AnamneseCondicoes).
 * - A ausência de uma marcação não prova a ausência da condição; nada aqui é diagnóstico nem escore clínico.
 * - Grupos com menos de MIN_GRUPO pacientes ficam sinalizados e sem percentual nos mapas de calor;
 *   meses com menos de MIN_MES pacientes não são medidos (mesma regra da evolução mensal do painel).
 */
class AnamneseAnaliseService
{
    public const MIN_GRUPO = 10;

    public const MIN_MES = 20;

    public const CLASSES_CARDIO = ['0', '1', '2', '3', '4 ou mais'];

    public const CLASSES_MULTI = ['Nenhuma', '1', '2', '3', '4', '5 ou mais'];

    public const SEXOS = ['Feminino', 'Masculino', 'Não informado'];

    /** Perfis mutuamente exclusivos: vale o primeiro critério atendido, nesta ordem. */
    public const PERFIS = [
        'alta_multimorbidade' => ['Alta multimorbidade', '5 ou mais comorbidades registradas.'],
        'cardiometabolico_historico' => ['Cardiometabólico com histórico cardiovascular', 'Hipertensão e/ou diabetes, com infarto, AVC, angioplastia ou cateterismo registrado.'],
        'cardiovascular' => ['Cardiovascular estabelecido', 'Infarto, AVC, angioplastia ou cateterismo registrado, sem hipertensão nem diabetes.'],
        'cardiometabolico' => ['Cardiometabólico', 'Hipertensão e diabetes, sem evento cardiovascular registrado.'],
        'hipertensivo' => ['Hipertensivo', 'Hipertensão, sem diabetes e sem evento cardiovascular registrado.'],
        'baixa_carga' => ['Baixa carga clínica', 'Nenhuma ou 1 comorbidade registrada, sem os critérios acima.'],
        'demais' => ['Demais perfis', '2 a 4 comorbidades registradas, sem os critérios acima.'],
    ];

    public function __construct(private AnamneseEstatisticaService $estatistica)
    {
    }

    /**
     * @param array{inicio: \DateTimeInterface, fim: \DateTimeInterface, sexo?: ?string, faixa?: ?string, tipo?: ?string, procedimento?: ?string, medico?: ?int} $f
     */
    public function obter(array $f): array
    {
        $b = $this->estatistica->base($f);

        return $this->calcular($b['catalogo'], $b['examesP'], $b['novos'], $b['meses']);
    }

    /**
     * Cálculo puro (sem banco), a partir da base de AnamneseEstatisticaService::base().
     *
     * @param array<int, array{nome: string, categoria: string, dose: ?int}> $catalogo
     * @param array<int|string, array>                                       $examesP  exames do período, em ordem cronológica
     * @param list<array{ag: int|string, cid: int}>                          $novos    novos diagnósticos do período
     * @param list<string>                                                   $meses    meses do período (Y-m)
     */
    public function calcular(array $catalogo, array $examesP, array $novos, array $meses): array
    {
        $itensPorCondicao = AnamneseCondicoes::itensPorCondicao($catalogo);
        $condicaoDoItem = [];
        foreach ($itensPorCondicao as $condicao => $cids) {
            $condicaoDoItem += array_fill_keys($cids, $condicao);
        }
        $nome = fn (int $cid) => AnamneseEstatisticaService::titulo($catalogo[$cid]['nome'] ?? '?');
        $perfil = fn (array $cids) => $this->perfil($cids, $catalogo, $condicaoDoItem);

        // ---- Pacientes do período ------------------------------------------------------
        $pacientes = [];
        foreach ($examesP as $ag => $e) {
            $p = &$pacientes[$e['pid']];
            $p ??= ['cids' => [], 'ags' => [], 'procs' => []];
            $p['cids'] += $e['cids'];
            $p['ags'][] = $ag;
            $p['procs'][$e['proc']] = true;
            $p['sexo'] = $e['sexo'];
            $p['faixa'] = $e['faixa'];
            unset($p);
        }
        foreach ($pacientes as $pid => $p) {
            $pacientes[$pid] += $perfil($p['cids']);
        }
        $nPac = count($pacientes);
        $nEx = count($examesP);

        $porFaixa = array_fill_keys(AnamneseEstatisticaService::FAIXAS, []);
        $porSexo = array_fill_keys(self::SEXOS, []);
        $porProc = [];
        $semIdade = [];
        foreach ($pacientes as $p) {
            if (isset($porFaixa[$p['faixa']])) {
                $porFaixa[$p['faixa']][] = $p;
            } else {
                $semIdade[] = $p;
            }
            $porSexo[$p['sexo']][] = $p;
            foreach ($p['procs'] as $proc => $_) {
                $porProc[$proc][] = $p;
            }
        }
        if (!$porSexo['Não informado']) {
            unset($porSexo['Não informado']);
        }
        uasort($porProc, fn ($a, $b) => count($b) <=> count($a));

        // ---- 1. Perfil cardiovascular registrado ---------------------------------------
        $contCardio = array_fill(0, 5, 0);
        $contCondicao = array_fill_keys(array_keys(AnamneseCondicoes::CONDICOES), 0);
        foreach ($pacientes as $p) {
            $contCardio[min(4, $p['c'])]++;
            foreach ($p['cond'] as $condicao => $_) {
                $contCondicao[$condicao]++;
            }
        }
        $condicoes = [];
        foreach (AnamneseCondicoes::CONDICOES as $chave => $c) {
            $condicoes[] = [
                'chave' => $chave,
                'rotulo' => $c['rotulo'],
                'itens' => array_map($nome, $itensPorCondicao[$chave]),
                'n' => $contCondicao[$chave],
                'pct' => $this->pct($contCondicao[$chave], $nPac),
            ];
        }
        $gruposProc = [];
        foreach (array_slice($porProc, 0, 8, true) as $proc => $lista) {
            $gruposProc[AnamneseEstatisticaService::titulo((string) $proc)] = $lista;
        }
        $cardio = [
            'rotulos' => self::CLASSES_CARDIO,
            'valores' => $contCardio,
            'pct' => array_map(fn ($v) => $this->pct($v, $nPac), $contCardio),
            'comAlguma' => $nPac - $contCardio[0],
            'pctComAlguma' => $this->pct($nPac - $contCardio[0], $nPac),
            'condicoes' => $condicoes,
            'semItem' => array_values(array_map(fn ($c) => $c['rotulo'], array_filter($condicoes, fn ($c) => !$c['itens']))),
            'porFaixa' => $this->distribuicao($porFaixa, 'c', self::CLASSES_CARDIO),
            'porSexo' => $this->distribuicao($porSexo, 'c', self::CLASSES_CARDIO),
            'porProcedimento' => $this->distribuicao($gruposProc, 'c', self::CLASSES_CARDIO),
        ];

        // ---- 3 e 4. Multimorbidade por faixa etária e por sexo -------------------------
        $linhaResumo = fn (string $grupo, array $lista) => ['grupo' => $grupo] + $this->resumo($lista);
        $estatFaixa = array_map($linhaResumo, array_keys($porFaixa), $porFaixa);
        if ($semIdade) {
            $estatFaixa[] = $linhaResumo('Sem idade', $semIdade);
        }
        $cruzamento = [];
        foreach ($porSexo as $sexo => $lista) {
            $celulas = [];
            foreach (AnamneseEstatisticaService::FAIXAS as $fx) {
                $celulas[] = $this->resumo(array_values(array_filter($lista, fn ($p) => $p['faixa'] === $fx)));
            }
            $cruzamento[] = ['sexo' => $sexo, 'celulas' => $celulas];
        }

        return [
            'pacientes' => $nPac,
            'exames' => $nEx,
            'minGrupo' => self::MIN_GRUPO,
            'minMes' => self::MIN_MES,
            'geral' => $this->resumo(array_values($pacientes)),
            'cardio' => $cardio,
            'evolucao' => $this->evolucao($pacientes, $examesP, $catalogo),
            'multiFaixa' => ['rotulos' => self::CLASSES_MULTI, 'estatisticas' => $estatFaixa] + $this->distribuicao($porFaixa, 'k', self::CLASSES_MULTI),
            'multiSexo' => [
                'linhas' => array_map($linhaResumo, array_keys($porSexo), $porSexo),
                'faixas' => AnamneseEstatisticaService::FAIXAS,
                'cruzamento' => $cruzamento,
            ],
            'sexoFaixa' => $this->sexoFaixa($porSexo, $catalogo),
            'perfis' => $this->perfis($pacientes),
            'procedimentos' => $this->procedimentos($porProc, $examesP),
            'qualidade' => $this->qualidade($pacientes, $examesP, $catalogo),
            'associacao' => $this->associacao($pacientes, $catalogo),
            'mensal' => $this->mensal($examesP, $meses, $perfil),
            'novosTipo' => $this->novosTipo($novos, $examesP, $meses, $catalogo),
        ];
    }

    /**
     * Tabelas dos blocos complementares para exportação em CSV (cabeçalho na primeira linha).
     *
     * @return array<string, array{titulo: string, linhas: list<list<string|int|float|null>>}>
     */
    public function tabelas(array $a): array
    {
        $n = fn ($v, int $casas = 1) => $v === null ? '' : number_format((float) $v, $casas, ',', '');
        $t = [];

        $linhas = [['condicoes_registradas', 'pacientes', 'pct_pacientes']];
        foreach ($a['cardio']['rotulos'] as $i => $r) {
            $linhas[] = [$r, $a['cardio']['valores'][$i], $n($a['cardio']['pct'][$i])];
        }
        $linhas[] = [];
        $linhas[] = ['condicao', 'itens_do_catalogo_considerados', 'pacientes', 'pct_pacientes'];
        foreach ($a['cardio']['condicoes'] as $c) {
            $linhas[] = [$c['rotulo'], implode(' | ', $c['itens']), $c['n'], $n($c['pct'])];
        }
        foreach (['porFaixa' => 'faixa_etaria', 'porSexo' => 'sexo', 'porProcedimento' => 'procedimento'] as $chave => $rotulo) {
            $linhas[] = [];
            $linhas[] = array_merge([$rotulo, 'pacientes'], array_map(fn ($r) => 'n_' . $r, $a['cardio']['rotulos']), array_map(fn ($r) => 'pct_' . $r, $a['cardio']['rotulos']));
            foreach ($a['cardio'][$chave]['grupos'] as $g => $grupo) {
                $linhas[] = array_merge(
                    [$grupo, $a['cardio'][$chave]['totais'][$g]],
                    array_map(fn ($l) => $l['n'][$g], $a['cardio'][$chave]['itens']),
                    array_map(fn ($l) => $n($l['valores'][$g]), $a['cardio'][$chave]['itens'])
                );
            }
        }
        $t['perfil-cardiovascular'] = ['titulo' => 'Perfil cardiovascular registrado', 'linhas' => $linhas];

        $ev = $a['evolucao'];
        $linhas = [
            ['indicador', 'valor'],
            ['pacientes_acompanhados', $ev['acompanhados']],
            ['sem_alteracao_nos_itens', $ev['semAlteracao']],
            ['com_pelo_menos_um_novo_item', $ev['comNovo']],
            ['com_item_nao_repetido', $ev['comNaoRepetido']],
            ['media_novos_itens_por_paciente', $n($ev['mediaNovos'], 2)],
            ['dias_entre_anamneses_media', $n($ev['intervaloMedio'])],
            ['dias_entre_anamneses_mediana', $n($ev['intervaloMediano'])],
            [],
            ['item', 'categoria', 'pacientes_em_que_apareceu_depois', 'pct_dos_acompanhados'],
        ];
        foreach ($ev['itens'] as $it) {
            $linhas[] = [$it['nome'], $it['categoria'], $it['n'], $n($it['pct'])];
        }
        $t['evolucao-itens'] = ['titulo' => 'Evolução entre anamneses do mesmo paciente', 'linhas' => $linhas];

        $cabResumo = ['pacientes', 'media_comorbidades', 'mediana', 'n_2_ou_mais', 'pct_2_ou_mais', 'n_4_ou_mais', 'pct_4_ou_mais'];
        $resumo = fn (array $r) => [$r['n'], $n($r['media'], 2), $n($r['mediana']), $r['n2'], $n($r['pct2']), $r['n4'], $n($r['pct4'])];

        $mf = $a['multiFaixa'];
        $linhas = [array_merge(['faixa_etaria'], $cabResumo, array_map(fn ($r) => 'n_' . $r, $mf['rotulos']))];
        foreach ($mf['estatisticas'] as $i => $r) {
            $linhas[] = array_merge([$r['grupo']], $resumo($r), isset($mf['grupos'][$i]) ? array_map(fn ($l) => $l['n'][$i], $mf['itens']) : []);
        }
        $t['multimorbidade-faixa'] = ['titulo' => 'Multimorbidade por faixa etária', 'linhas' => $linhas];

        $linhas = [array_merge(['sexo', 'faixa_etaria'], $cabResumo)];
        foreach ($a['multiSexo']['linhas'] as $r) {
            $linhas[] = array_merge([$r['grupo'], 'Todas'], $resumo($r));
        }
        foreach ($a['multiSexo']['cruzamento'] as $c) {
            foreach ($c['celulas'] as $i => $r) {
                $linhas[] = array_merge([$c['sexo'], $a['multiSexo']['faixas'][$i]], $resumo($r));
            }
        }
        $t['multimorbidade-sexo'] = ['titulo' => 'Multimorbidade por sexo', 'linhas' => $linhas];

        $sf = $a['sexoFaixa'];
        $linhas = [['item', 'tipo', 'sexo', 'faixa_etaria', 'pacientes_do_grupo', 'pacientes_com_o_item', 'pct_do_grupo']];
        foreach ($sf['itens'] as $it) {
            foreach ($sf['sexos'] as $s => $sexo) {
                foreach ($sf['faixas'] as $i => $fx) {
                    $total = $sf['totais'][$s][$i];
                    $linhas[] = [$it['nome'], $it['tipo'], $sexo, $fx, $total, $it['n'][$s][$i], $total ? $n($it['n'][$s][$i] * 100 / $total) : ''];
                }
            }
        }
        $t['sexo-faixa'] = ['titulo' => 'Perfil clínico por sexo e faixa etária', 'linhas' => $linhas];

        $linhas = [['perfil', 'criterio', 'pacientes', 'pct_pacientes']];
        foreach ($a['perfis'] as $pf) {
            $linhas[] = [$pf['nome'], $pf['definicao'], $pf['n'], $n($pf['pct'])];
        }
        $t['perfis'] = ['titulo' => 'Perfis clínicos agrupados', 'linhas' => $linhas];

        $linhas = [array_merge(['procedimento', 'exames'], $cabResumo, ['n_historico_cardiovascular', 'pct_historico_cardiovascular'])];
        foreach ($a['procedimentos'] as $r) {
            $linhas[] = array_merge([$r['nome'], $r['exames']], $resumo($r), [$r['nEvento'], $n($r['pctEvento'])]);
        }
        $t['carga-procedimento'] = ['titulo' => 'Carga clínica por procedimento', 'linhas' => $linhas];

        $q = $a['qualidade'];
        $linhas = [['campo', 'anamneses_com_o_campo', 'anamneses_sem_o_campo', 'pct_completude']];
        foreach ($q['campos'] as $c) {
            $linhas[] = [$c['campo'], $c['preenchidos'], $c['faltando'], $n($c['pct'])];
        }
        $linhas[] = [];
        $linhas[] = ['indicador', 'valor'];
        $linhas[] = ['total_de_anamneses', $q['anamneses']];
        $linhas[] = ['pacientes', $q['pacientes']];
        $linhas[] = ['pacientes_com_mais_de_uma_anamnese', $q['pacientesRepetidos']];
        $linhas[] = ['pacientes_com_mais_de_uma_anamnese_no_mesmo_dia', $q['pacientesMesmoDia']];
        $linhas[] = ['possiveis_duplicidades_grupos', $q['duplicidades']['grupos']];
        $linhas[] = ['possiveis_duplicidades_anamneses', $q['duplicidades']['exames']];
        $linhas[] = ['anamneses_com_mais_de_uma_dose_de_vacina', $q['multiplasDoses']['exames']];
        $linhas[] = [];
        $linhas[] = ['item_a', 'pacientes_a', 'item_b', 'pacientes_b', 'motivo'];
        foreach ($q['semelhantes'] as $s) {
            $linhas[] = [$s['a'], $s['nA'], $s['b'], $s['nB'], $s['motivo']];
        }
        $t['qualidade'] = ['titulo' => 'Qualidade e completude dos dados', 'linhas' => $linhas];

        $as = $a['associacao'];
        $linhas = [['comorbidade_a', 'pacientes_a', 'comorbidade_b', 'pacientes_b', 'pacientes_com_ambas', 'pct_de_a_que_tem_b', 'pct_de_b_que_tem_a', 'pct_do_total']];
        foreach ($as['itens'] as $i => $na) {
            foreach ($as['itens'] as $j => $nb) {
                if ($j > $i) {
                    $linhas[] = [$na, $as['totais'][$i], $nb, $as['totais'][$j], $as['n'][$i][$j], $n($as['condicional'][$i][$j]), $n($as['condicional'][$j][$i]), $n($as['total'][$i][$j])];
                }
            }
        }
        $t['associacao'] = ['titulo' => 'Matriz de associação entre comorbidades', 'linhas' => $linhas];

        $m = $a['mensal'];
        $linhas = [['mes', 'pacientes', 'media_comorbidades', 'pct_2_ou_mais', 'pct_4_ou_mais', 'pct_historico_cardiovascular', 'pct_com_condicao_cardiovascular']];
        foreach ($m['meses'] as $i => $mes) {
            $linhas[] = [$mes, $m['pacientes'][$i], $n($m['media'][$i], 2), $n($m['pct2'][$i]), $n($m['pct4'][$i]), $n($m['pctEvento'][$i]), $n($m['pctCardio'][$i])];
        }
        $t['carga-mensal'] = ['titulo' => 'Evolução mensal da carga clínica', 'linhas' => $linhas];

        $nt = $a['novosTipo'];
        $linhas = [array_merge(['mes'], array_map(fn ($g) => $g['nome'], $nt['grupos']))];
        foreach ($nt['meses'] as $i => $mes) {
            $linhas[] = array_merge([$mes], array_map(fn ($g) => $g['valores'][$i], $nt['grupos']));
        }
        $linhas[] = [];
        $linhas[] = ['item', 'tipo', 'novos_registros'];
        foreach ($nt['itens'] as $it) {
            $linhas[] = [$it['nome'], $it['tipoRotulo'], $it['n']];
        }
        $t['novos-tipo'] = ['titulo' => 'Novos registros por tipo de condição', 'linhas' => $linhas];

        return $t;
    }

    // ---------------------------------------------------------------------------------

    /**
     * Carga clínica de um conjunto de itens: nº de comorbidades, condições cardiovasculares e histórico.
     *
     * @return array{k: int, c: int, cond: array<string, true>, evento: bool}
     */
    private function perfil(array $cids, array $catalogo, array $condicaoDoItem): array
    {
        $k = 0;
        $cond = [];
        foreach ($cids as $cid => $_) {
            $k += ($catalogo[$cid]['categoria'] ?? '') === 'comorbidade' ? 1 : 0;
            if (isset($condicaoDoItem[$cid])) {
                $cond[$condicaoDoItem[$cid]] = true;
            }
        }

        return [
            'k' => $k,
            'c' => count($cond),
            'cond' => $cond,
            'evento' => (bool) array_intersect_key($cond, array_flip(AnamneseCondicoes::HISTORICO_CARDIOVASCULAR)),
        ];
    }

    /** Resumo da multimorbidade de uma lista de pacientes. */
    private function resumo(array $lista): array
    {
        $n = count($lista);
        $ks = array_column($lista, 'k');
        $n2 = count(array_filter($ks, fn ($k) => $k >= 2));
        $n4 = count(array_filter($ks, fn ($k) => $k >= 4));
        $nEvento = count(array_filter($lista, fn ($p) => $p['evento']));

        return [
            'n' => $n,
            'media' => $n ? round(array_sum($ks) / $n, 2) : null,
            'mediana' => $this->mediana($ks),
            'n2' => $n2,
            'pct2' => $n ? $this->pct($n2, $n) : null,
            'n4' => $n4,
            'pct4' => $n ? $this->pct($n4, $n) : null,
            'nEvento' => $nEvento,
            'pctEvento' => $n ? $this->pct($nEvento, $n) : null,
            'pequena' => $n < self::MIN_GRUPO,
        ];
    }

    /**
     * Distribuição de uma contagem (campo k ou c) por grupo, no formato dos mapas de calor:
     * linha = classe, coluna = grupo, valor = % dos pacientes do grupo (null se o grupo for pequeno).
     *
     * @param array<string, list<array>> $grupos
     * @param list<string>               $classes a última classe acumula "ou mais"
     */
    private function distribuicao(array $grupos, string $campo, array $classes): array
    {
        $teto = count($classes) - 1;
        $itens = array_map(fn ($c) => ['nome' => $c, 'valores' => [], 'n' => []], $classes);
        foreach ($grupos as $lista) {
            $cont = array_fill(0, $teto + 1, 0);
            foreach ($lista as $p) {
                $cont[min($teto, $p[$campo])]++;
            }
            foreach ($cont as $i => $v) {
                $itens[$i]['n'][] = $v;
                $itens[$i]['valores'][] = count($lista) >= self::MIN_GRUPO ? $this->pct($v, count($lista)) : null;
            }
        }

        return ['grupos' => array_map('strval', array_keys($grupos)), 'totais' => array_values(array_map('count', $grupos)), 'itens' => $itens];
    }

    /** 2. Evolução entre anamneses do mesmo paciente (anamneses do mesmo dia contam como um só momento). */
    private function evolucao(array $pacientes, array $examesP, array $catalogo): array
    {
        $acompanhados = 0;
        $semAlteracao = 0;
        $comNovo = 0;
        $comNaoRepetido = 0;
        $totalNovos = 0;
        $intervalos = [];
        $contItem = [];
        foreach ($pacientes as $p) {
            $dias = [];
            foreach ($p['ags'] as $ag) {
                $dia = substr($examesP[$ag]['dt'], 0, 10);
                $dias[$dia] = ($dias[$dia] ?? []) + array_filter($examesP[$ag]['cids'], fn ($cid) => ($catalogo[$cid]['categoria'] ?? '') !== 'vacina', ARRAY_FILTER_USE_KEY);
            }
            if (count($dias) < 2) {
                continue;
            }
            ksort($dias);
            $acompanhados++;
            $datas = array_keys($dias);
            foreach ($datas as $i => $d) {
                if ($i > 0) {
                    $intervalos[] = (new \DateTime($datas[$i - 1]))->diff(new \DateTime($d))->days;
                }
            }
            $primeira = array_shift($dias);
            $novosItens = [];
            $alterou = false;
            $naoRepetiu = false;
            foreach ($dias as $cids) {
                $novosItens += array_diff_key($cids, $primeira);
                $faltou = (bool) array_diff_key($primeira, $cids);
                $naoRepetiu = $naoRepetiu || $faltou;
                $alterou = $alterou || $faltou || array_diff_key($cids, $primeira);
            }
            $semAlteracao += $alterou ? 0 : 1;
            $comNovo += $novosItens ? 1 : 0;
            $comNaoRepetido += $naoRepetiu ? 1 : 0;
            $totalNovos += count($novosItens);
            foreach ($novosItens as $cid => $_) {
                $contItem[$cid] = ($contItem[$cid] ?? 0) + 1;
            }
        }
        arsort($contItem);

        $itens = [];
        foreach ($contItem as $cid => $n) {
            $itens[] = [
                'nome' => AnamneseEstatisticaService::titulo($catalogo[$cid]['nome'] ?? '?'),
                'categoria' => ClassificacaoEstudo::CATEGORIAS[$catalogo[$cid]['categoria'] ?? 'outros'] ?? 'Outros',
                'n' => $n,
                'pct' => $this->pct($n, $acompanhados),
            ];
        }

        return [
            'acompanhados' => $acompanhados,
            'pctAcompanhados' => $this->pct($acompanhados, count($pacientes)),
            'semAlteracao' => $semAlteracao,
            'pctSemAlteracao' => $this->pct($semAlteracao, $acompanhados),
            'comNovo' => $comNovo,
            'pctComNovo' => $this->pct($comNovo, $acompanhados),
            'comNaoRepetido' => $comNaoRepetido,
            'pctComNaoRepetido' => $this->pct($comNaoRepetido, $acompanhados),
            'mediaNovos' => $acompanhados ? round($totalNovos / $acompanhados, 2) : null,
            'intervaloMedio' => $intervalos ? round(array_sum($intervalos) / count($intervalos), 1) : null,
            'intervaloMediano' => $this->mediana($intervalos),
            'itens' => $itens,
        ];
    }

    /** 5. Prevalência por sexo e faixa etária ao mesmo tempo, para as condições cardiovasculares e para cada item. */
    private function sexoFaixa(array $porSexo, array $catalogo): array
    {
        $faixas = AnamneseEstatisticaService::FAIXAS;
        $indiceFaixa = array_flip($faixas);
        $sexos = array_keys($porSexo);
        $zeros = array_fill(0, count($sexos), array_fill(0, count($faixas), 0));
        $totais = $zeros;
        $cont = [];
        foreach ($sexos as $s => $sexo) {
            foreach ($porSexo[$sexo] as $p) {
                $i = $indiceFaixa[$p['faixa']] ?? null;
                if ($i === null) {
                    continue;
                }
                $totais[$s][$i]++;
                foreach ($p['cond'] as $condicao => $_) {
                    $cont['c:' . $condicao] ??= $zeros;
                    $cont['c:' . $condicao][$s][$i]++;
                }
                foreach ($p['cids'] as $cid => $_) {
                    $cont['i:' . $cid] ??= $zeros;
                    $cont['i:' . $cid][$s][$i]++;
                }
            }
        }

        $linha = function (string $chave, string $nome, string $tipo) use ($cont, $zeros, $totais) {
            $n = $cont[$chave] ?? $zeros;
            $pct = [];
            foreach ($n as $s => $porFaixa) {
                foreach ($porFaixa as $i => $v) {
                    $pct[$s][$i] = $totais[$s][$i] >= self::MIN_GRUPO ? $this->pct($v, $totais[$s][$i]) : null;
                }
            }

            return ['chave' => $chave, 'nome' => $nome, 'tipo' => $tipo, 'total' => array_sum(array_map('array_sum', $n)), 'n' => $n, 'pct' => $pct];
        };

        $itens = [];
        foreach (AnamneseCondicoes::CONDICOES as $chave => $c) {
            $itens[] = $linha('c:' . $chave, $c['rotulo'], 'condição cardiovascular');
        }
        $doCatalogo = [];
        foreach ($catalogo as $cid => $item) {
            if (isset($cont['i:' . $cid])) {
                $doCatalogo[] = $linha('i:' . $cid, AnamneseEstatisticaService::titulo($item['nome']), 'item do catálogo');
            }
        }
        usort($doCatalogo, fn ($a, $b) => $b['total'] <=> $a['total']);

        return ['faixas' => $faixas, 'sexos' => $sexos, 'totais' => $totais, 'itens' => array_merge($itens, $doCatalogo)];
    }

    /** 6. Perfis clínicos mutuamente exclusivos (primeiro critério atendido, na ordem de PERFIS). */
    private function perfis(array $pacientes): array
    {
        $cont = array_fill_keys(array_keys(self::PERFIS), 0);
        foreach ($pacientes as $p) {
            $cont[self::classificarPerfil($p['k'], $p['cond'])]++;
        }

        $out = [];
        foreach (self::PERFIS as $chave => [$nome, $definicao]) {
            $out[] = ['chave' => $chave, 'nome' => $nome, 'definicao' => $definicao, 'n' => $cont[$chave], 'pct' => $this->pct($cont[$chave], count($pacientes))];
        }

        return $out;
    }

    /**
     * @param int                  $k    nº de comorbidades
     * @param array<string, mixed> $cond condições cardiovasculares registradas (chaves de AnamneseCondicoes::CONDICOES)
     */
    public static function classificarPerfil(int $k, array $cond): string
    {
        $hipertensao = isset($cond['hipertensao']);
        $diabetes = isset($cond['diabetes']);
        $evento = (bool) array_intersect_key($cond, array_flip(AnamneseCondicoes::HISTORICO_CARDIOVASCULAR));

        return match (true) {
            $k >= 5 => 'alta_multimorbidade',
            $evento && ($hipertensao || $diabetes) => 'cardiometabolico_historico',
            $evento => 'cardiovascular',
            $hipertensao && $diabetes => 'cardiometabolico',
            $hipertensao => 'hipertensivo',
            $k <= 1 => 'baixa_carga',
            default => 'demais',
        };
    }

    /** 7. Carga clínica por procedimento: cada paciente conta uma vez em cada procedimento que realizou. */
    private function procedimentos(array $porProc, array $examesP): array
    {
        $exames = array_count_values(array_column($examesP, 'proc'));
        $out = [];
        foreach ($porProc as $proc => $lista) {
            $out[] = ['nome' => AnamneseEstatisticaService::titulo((string) $proc), 'exames' => $exames[$proc] ?? 0] + $this->resumo($lista);
        }
        usort($out, fn ($a, $b) => [$b['media'], $b['n']] <=> [$a['media'], $a['n']]);

        return $out;
    }

    /** 8. Qualidade e completude: só sinaliza; nada é corrigido. */
    private function qualidade(array $pacientes, array $examesP, array $catalogo): array
    {
        $n = count($examesP);
        $faltando = ['Idade (data de nascimento)' => 0, 'Sexo' => 0, 'Agendamento vinculado' => 0, 'Procedimento' => 0, 'Tipo de atendimento' => 0, 'Médico' => 0];
        $porDia = [];
        $porDiaProc = [];
        $examesDoses = 0;
        $pacientesDoses = [];
        foreach ($examesP as $e) {
            $faltando['Idade (data de nascimento)'] += $e['idade'] === null ? 1 : 0;
            $faltando['Sexo'] += $e['sexo'] === 'Não informado' ? 1 : 0;
            $faltando['Agendamento vinculado'] += $e['temAgendamento'] ? 0 : 1;
            $faltando['Procedimento'] += $e['temProcedimento'] ? 0 : 1;
            $faltando['Tipo de atendimento'] += $e['tipo'] === 'nd' ? 1 : 0;
            $faltando['Médico'] += $e['medico'] === null ? 1 : 0;

            $dia = $e['pid'] . '|' . substr($e['dt'], 0, 10);
            $porDia[$dia] = ($porDia[$dia] ?? 0) + 1;
            $chave = $dia . '|' . $e['proc'];
            $porDiaProc[$chave] = ($porDiaProc[$chave] ?? 0) + 1;

            $doses = count(array_filter(array_keys($e['cids']), fn ($cid) => ($catalogo[$cid]['dose'] ?? null) !== null));
            if ($doses > 1) {
                $examesDoses++;
                $pacientesDoses[$e['pid']] = true;
            }
        }

        $campos = [];
        foreach ($faltando as $campo => $v) {
            $campos[] = ['campo' => $campo, 'preenchidos' => $n - $v, 'faltando' => $v, 'pct' => $this->pct($n - $v, $n)];
        }
        $duplicados = array_filter($porDiaProc, fn ($v) => $v > 1);
        $pacientesMesmoDia = [];
        foreach ($porDia as $chave => $v) {
            if ($v > 1) {
                $pacientesMesmoDia[strstr($chave, '|', true)] = true;
            }
        }

        $contItem = [];
        foreach ($pacientes as $p) {
            foreach ($p['cids'] as $cid => $_) {
                $contItem[$cid] = ($contItem[$cid] ?? 0) + 1;
            }
        }
        $semelhantes = [];
        foreach (AnamneseCondicoes::itensSemelhantes($catalogo) as $par) {
            $semelhantes[] = [
                'a' => AnamneseEstatisticaService::titulo($catalogo[$par['a']]['nome']),
                'nA' => $contItem[$par['a']] ?? 0,
                'b' => AnamneseEstatisticaService::titulo($catalogo[$par['b']]['nome']),
                'nB' => $contItem[$par['b']] ?? 0,
                'motivo' => $par['motivo'],
            ];
        }
        usort($semelhantes, fn ($a, $b) => $b['nA'] + $b['nB'] <=> $a['nA'] + $a['nB']);

        return [
            'anamneses' => $n,
            'pacientes' => count($pacientes),
            'campos' => $campos,
            'pacientesRepetidos' => count(array_filter($pacientes, fn ($p) => count($p['ags']) > 1)),
            'pacientesMesmoDia' => count($pacientesMesmoDia),
            'duplicidades' => ['grupos' => count($duplicados), 'exames' => array_sum($duplicados)],
            'multiplasDoses' => ['exames' => $examesDoses, 'pacientes' => count($pacientesDoses), 'pct' => $this->pct($examesDoses, $n)],
            'semelhantes' => array_slice($semelhantes, 0, 30),
        ];
    }

    /** 9. Associação entre as 10 comorbidades mais frequentes: nº de pacientes, % condicional e % do total. */
    private function associacao(array $pacientes, array $catalogo): array
    {
        $cont = [];
        foreach ($pacientes as $p) {
            foreach ($p['cids'] as $cid => $_) {
                if (($catalogo[$cid]['categoria'] ?? '') === 'comorbidade') {
                    $cont[$cid] = ($cont[$cid] ?? 0) + 1;
                }
            }
        }
        arsort($cont);
        $top = array_slice(array_keys($cont), 0, 10);
        $nPac = count($pacientes);

        $n = [];
        $condicional = [];
        $total = [];
        foreach ($top as $i => $a) {
            foreach ($top as $j => $b) {
                if ($i === $j) {
                    $n[$i][$j] = $condicional[$i][$j] = $total[$i][$j] = null;
                    continue;
                }
                $ambos = count(array_filter($pacientes, fn ($p) => isset($p['cids'][$a], $p['cids'][$b])));
                $n[$i][$j] = $ambos;
                $condicional[$i][$j] = $this->pct($ambos, $cont[$a]);
                $total[$i][$j] = $this->pct($ambos, $nPac);
            }
        }

        $itens = array_map(fn ($cid) => AnamneseEstatisticaService::titulo($catalogo[$cid]['nome']), $top);
        $pares = [];
        foreach ($top as $i => $a) {
            foreach ($top as $j => $b) {
                if ($j > $i && $n[$i][$j] > 0) {
                    $pares[] = ['a' => $itens[$i], 'b' => $itens[$j], 'n' => $n[$i][$j], 'pctTotal' => $total[$i][$j], 'pctAB' => $condicional[$i][$j], 'pctBA' => $condicional[$j][$i]];
                }
            }
        }
        usort($pares, fn ($x, $y) => $y['n'] <=> $x['n']);

        return ['itens' => $itens, 'totais' => array_map(fn ($cid) => $cont[$cid], $top), 'n' => $n, 'condicional' => $condicional, 'total' => $total, 'pares' => array_slice($pares, 0, 10)];
    }

    /** 10. Carga clínica mês a mês, entre os pacientes atendidos em cada mês. */
    private function mensal(array $examesP, array $meses, callable $perfil): array
    {
        $porMes = [];
        foreach ($examesP as $e) {
            $porMes[$e['mes']][$e['pid']] = ($porMes[$e['mes']][$e['pid']] ?? []) + $e['cids'];
        }

        $out = ['meses' => [], 'pacientes' => [], 'media' => [], 'pct2' => [], 'pct4' => [], 'pctEvento' => [], 'pctCardio' => []];
        foreach ($meses as $m) {
            $lista = array_map($perfil, array_values($porMes[$m] ?? []));
            $r = $this->resumo($lista);
            $medido = $r['n'] >= self::MIN_MES;
            $out['meses'][] = AnamneseEstatisticaService::rotuloMes($m);
            $out['pacientes'][] = $r['n'];
            $out['media'][] = $medido ? $r['media'] : null;
            $out['pct2'][] = $medido ? $r['pct2'] : null;
            $out['pct4'][] = $medido ? $r['pct4'] : null;
            $out['pctEvento'][] = $medido ? $r['pctEvento'] : null;
            $out['pctCardio'][] = $medido ? $this->pct(count(array_filter($lista, fn ($p) => $p['c'] > 0)), $r['n']) : null;
        }

        return $out;
    }

    /** 11. Novos diagnósticos do período, separados por tipo de condição. */
    private function novosTipo(array $novos, array $examesP, array $meses, array $catalogo): array
    {
        $indiceMes = array_flip($meses);
        $series = array_fill_keys(array_keys(AnamneseCondicoes::TIPOS), array_fill(0, count($meses), 0));
        $totais = array_fill_keys(array_keys(AnamneseCondicoes::TIPOS), 0);
        $contItem = [];
        foreach ($novos as $novo) {
            $tipo = AnamneseCondicoes::tipo($catalogo[$novo['cid']]['nome'] ?? '');
            $i = $indiceMes[$examesP[$novo['ag']]['mes']] ?? null;
            if ($i !== null) {
                $series[$tipo][$i]++;
            }
            $totais[$tipo]++;
            $contItem[$novo['cid']] = ($contItem[$novo['cid']] ?? 0) + 1;
        }
        arsort($contItem);

        $itens = [];
        foreach ($contItem as $cid => $n) {
            $tipo = AnamneseCondicoes::tipo($catalogo[$cid]['nome'] ?? '');
            $itens[] = ['nome' => AnamneseEstatisticaService::titulo($catalogo[$cid]['nome'] ?? '?'), 'tipo' => $tipo, 'tipoRotulo' => AnamneseCondicoes::TIPOS[$tipo], 'n' => $n];
        }

        $grupos = [];
        foreach (AnamneseCondicoes::TIPOS as $tipo => $rotulo) {
            $grupos[] = ['chave' => $tipo, 'nome' => $rotulo, 'total' => $totais[$tipo], 'pct' => $this->pct($totais[$tipo], count($novos)), 'valores' => $series[$tipo]];
        }

        return ['meses' => array_map([AnamneseEstatisticaService::class, 'rotuloMes'], $meses), 'total' => count($novos), 'grupos' => $grupos, 'itens' => $itens];
    }

    private function mediana(array $valores): ?float
    {
        if (!$valores) {
            return null;
        }
        sort($valores);
        $n = count($valores);
        $meio = intdiv($n, 2);

        return $n % 2 ? (float) $valores[$meio] : ($valores[$meio - 1] + $valores[$meio]) / 2;
    }

    private function pct(int|float $n, int|float $d): float
    {
        return $d > 0 ? round($n * 100 / $d, 1) : 0.0;
    }
}
