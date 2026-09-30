<?php

namespace App\Service;

use App\Entity\ClassificacaoEstudo;

/**
 * Panorama de um item da anamnese (ex.: Diabetes): tudo o que a base permite medir sobre ele,
 * comparando os pacientes que têm o item registrado com os que não têm.
 *
 * Mesma base, filtros e regras de AnamneseAnaliseService: o paciente conta uma vez no período
 * (itens = união dos exames do período; sexo, idade e faixa etária do exame mais recente), a
 * ausência de marcação não prova ausência da condição e nada aqui é diagnóstico.
 */
class AnamneseItemService
{
    public const CLASSES_OUTRAS = ['Nenhuma', '1', '2', '3', '4 ou mais'];

    public function __construct(private AnamneseEstatisticaService $estatistica)
    {
    }

    /**
     * @param array{inicio: \DateTimeInterface, fim: \DateTimeInterface, sexo?: ?string, faixa?: ?string, tipo?: ?string, procedimento?: ?string, medico?: ?int} $f
     */
    public function obter(array $f, ?int $item): array
    {
        $b = $this->estatistica->base($f);

        return $this->calcular($b['catalogo'], $b['examesP'], $b['novos'], $b['meses'], $item);
    }

    /**
     * Cálculo puro (sem banco). Sem item escolhido (ou com item fora do recorte), devolve só as opções.
     *
     * @param array<int, array{nome: string, categoria: string, dose: ?int}> $catalogo
     * @param array<int|string, array>                                       $examesP exames do período, em ordem cronológica
     * @param list<array{ag: int|string, cid: int}>                          $novos   novos diagnósticos do período
     * @param list<string>                                                   $meses   meses do período (Y-m)
     */
    public function calcular(array $catalogo, array $examesP, array $novos, array $meses, ?int $item): array
    {
        $titulo = fn (int $cid) => AnamneseEstatisticaService::titulo($catalogo[$cid]['nome'] ?? '?');
        $categoria = fn (int $cid) => $catalogo[$cid]['categoria'] ?? 'outros';
        $condicaoDoItem = [];
        foreach (AnamneseCondicoes::itensPorCondicao($catalogo) as $condicao => $cids) {
            $condicaoDoItem += array_fill_keys($cids, $condicao);
        }

        // ---- Pacientes do período ------------------------------------------------------
        $pacientes = [];
        foreach ($examesP as $ag => $e) {
            $p = &$pacientes[$e['pid']];
            $p ??= ['cids' => [], 'ags' => [], 'procs' => [], 'tipos' => [], 'medicos' => []];
            $p['cids'] += $e['cids'];
            $p['ags'][] = $ag;
            $p['procs'][$e['proc']] = true;
            $p['tipos'][$e['tipo']] = true;
            if ($e['medico'] !== null) {
                $p['medicos'][$e['medicoNome'] ?? ('Médico ' . $e['medico'])] = true;
            }
            $p['sexo'] = $e['sexo'];
            $p['idade'] = $e['idade'];
            $p['faixa'] = $e['faixa'];
            unset($p);
        }
        $nPac = count($pacientes);

        $contItem = [];
        foreach ($pacientes as $p) {
            foreach ($p['cids'] as $cid => $_) {
                $contItem[$cid] = ($contItem[$cid] ?? 0) + 1;
            }
        }
        arsort($contItem);
        $opcoes = [];
        foreach ($contItem as $cid => $n) {
            $opcoes[] = ['id' => $cid, 'nome' => $titulo($cid), 'categoria' => $categoria($cid), 'n' => $n];
        }
        usort($opcoes, fn ($a, $b) => [$b['n'], $a['nome']] <=> [$a['n'], $b['nome']]);

        $base = ['opcoes' => $opcoes, 'categorias' => ClassificacaoEstudo::CATEGORIAS, 'pacientes' => $nPac, 'exames' => count($examesP), 'item' => null];
        if ($item === null || !isset($catalogo[$item])) {
            return $base;
        }
        $x = $item;
        $base['item'] = [
            'id' => $x,
            'nome' => $titulo($x),
            'categoria' => $categoria($x),
            'categoriaRotulo' => ClassificacaoEstudo::CATEGORIAS[$categoria($x)] ?? 'Outros',
            'ehComorbidade' => $categoria($x) === 'comorbidade',
        ];
        $nCom = $contItem[$x] ?? 0;
        $base['com'] = $nCom;
        if ($nCom === 0) {
            return $base;
        }

        // Carga clínica de cada paciente, sem contar o próprio item
        $condicaoDoX = $condicaoDoItem[$x] ?? null;
        foreach ($pacientes as $pid => $p) {
            $outras = 0;
            $cond = [];
            foreach ($p['cids'] as $cid => $_) {
                $outras += $cid !== $x && $categoria($cid) === 'comorbidade' ? 1 : 0;
                if (isset($condicaoDoItem[$cid])) {
                    $cond[$condicaoDoItem[$cid]] = true;
                }
            }
            $pacientes[$pid] += [
                'tem' => isset($p['cids'][$x]),
                'outras' => $outras,
                'k' => $outras + (isset($p['cids'][$x]) && $categoria($x) === 'comorbidade' ? 1 : 0),
                'cond' => $cond,
                'evento' => (bool) array_intersect_key($cond, array_flip(AnamneseCondicoes::HISTORICO_CARDIOVASCULAR)),
            ];
        }
        $com = array_filter($pacientes, fn ($p) => $p['tem']);
        $sem = array_filter($pacientes, fn ($p) => !$p['tem']);
        $nSem = count($sem);

        // ---- Indicadores e comparação com x sem ---------------------------------------
        $examesCom = count(array_filter($examesP, fn ($e) => isset($e['cids'][$x])));
        $novosDoItem = array_values(array_filter($novos, fn ($n) => $n['cid'] === $x));
        $descrever = function (array $lista): array {
            $n = count($lista);
            $idades = array_values(array_filter(array_column($lista, 'idade'), fn ($i) => $i !== null));
            $outras = array_column($lista, 'outras');
            $sexoConhecido = count(array_filter($lista, fn ($p) => $p['sexo'] !== 'Não informado'));

            return [
                'n' => $n,
                'idadeMedia' => $idades ? round(array_sum($idades) / count($idades), 1) : null,
                'idadeMediana' => $this->mediana($idades),
                'pctFeminino' => $sexoConhecido ? $this->pct(count(array_filter($lista, fn ($p) => $p['sexo'] === 'Feminino')), $sexoConhecido) : null,
                'pct60' => $idades ? $this->pct(count(array_filter($idades, fn ($i) => $i >= 60)), count($idades)) : null,
                'outrasMedia' => $n ? round(array_sum($outras) / $n, 2) : null,
                'outrasMediana' => $this->mediana($outras),
                'pct2Outras' => $n ? $this->pct(count(array_filter($outras, fn ($k) => $k >= 2)), $n) : null,
                'pctEvento' => $n ? $this->pct(count(array_filter($lista, fn ($p) => $p['evento'])), $n) : null,
                'examesPorPaciente' => $n ? round(array_sum(array_map(fn ($p) => count($p['ags']), $lista)) / $n, 2) : null,
            ];
        };

        // ---- Sexo e faixa etária -------------------------------------------------------
        $faixas = AnamneseEstatisticaService::FAIXAS;
        $sexos = ['Feminino', 'Masculino'];
        $grade = array_fill_keys($sexos, array_fill_keys($faixas, ['n' => 0, 'total' => 0]));
        $geral = array_fill_keys($faixas, ['n' => 0, 'total' => 0]);
        foreach ($pacientes as $p) {
            if (!isset($geral[$p['faixa']])) {
                continue;
            }
            $geral[$p['faixa']]['total']++;
            $geral[$p['faixa']]['n'] += $p['tem'] ? 1 : 0;
            if (isset($grade[$p['sexo']])) {
                $grade[$p['sexo']][$p['faixa']]['total']++;
                $grade[$p['sexo']][$p['faixa']]['n'] += $p['tem'] ? 1 : 0;
            }
        }
        $celula = fn (array $c) => $c + ['pct' => $c['total'] >= AnamneseAnaliseService::MIN_GRUPO ? $this->pct($c['n'], $c['total']) : null];
        $porFaixa = [];
        foreach ($faixas as $fx) {
            $porFaixa[] = ['faixa' => $fx, 'geral' => $celula($geral[$fx]), 'Feminino' => $celula($grade['Feminino'][$fx]), 'Masculino' => $celula($grade['Masculino'][$fx])];
        }
        $porSexo = [];
        foreach (AnamneseAnaliseService::SEXOS as $sexo) {
            $total = count(array_filter($pacientes, fn ($p) => $p['sexo'] === $sexo));
            if ($total > 0) {
                $porSexo[] = ['nome' => $sexo] + $this->grupo(count(array_filter($com, fn ($p) => $p['sexo'] === $sexo)), $total);
            }
        }

        // ---- Evolução mensal -----------------------------------------------------------
        $porMes = [];
        foreach ($examesP as $e) {
            $porMes[$e['mes']][$e['pid']] = ($porMes[$e['mes']][$e['pid']] ?? false) || isset($e['cids'][$x]);
        }
        $novosMes = array_fill_keys($meses, 0);
        foreach ($novosDoItem as $n) {
            $m = $examesP[$n['ag']]['mes'];
            if (isset($novosMes[$m])) {
                $novosMes[$m]++;
            }
        }
        $mensal = ['meses' => [], 'pacientes' => [], 'com' => [], 'pct' => [], 'novos' => array_values($novosMes)];
        foreach ($meses as $m) {
            $lista = $porMes[$m] ?? [];
            $mensal['meses'][] = AnamneseEstatisticaService::rotuloMes($m);
            $mensal['pacientes'][] = count($lista);
            $mensal['com'][] = count(array_filter($lista));
            $mensal['pct'][] = count($lista) >= AnamneseAnaliseService::MIN_MES ? $this->pct(count(array_filter($lista)), count($lista)) : null;
        }

        // ---- Outras comorbidades, itens associados e combinações -----------------------
        $distribuir = function (array $lista): array {
            $cont = array_fill(0, 5, 0);
            foreach ($lista as $p) {
                $cont[min(4, $p['outras'])]++;
            }

            return ['n' => $cont, 'pct' => array_map(fn ($v) => count($lista) ? $this->pct($v, count($lista)) : null, $cont)];
        };

        $contCom = [];
        $combinacoes = [];
        foreach ($com as $p) {
            $outras = [];
            foreach ($p['cids'] as $cid => $_) {
                if ($cid === $x) {
                    continue;
                }
                $contCom[$cid] = ($contCom[$cid] ?? 0) + 1;
                if ($categoria($cid) === 'comorbidade') {
                    $outras[] = $cid;
                }
            }
            sort($outras);
            $chave = implode(',', $outras);
            $combinacoes[$chave] = ($combinacoes[$chave] ?? 0) + 1;
        }
        $associados = [];
        foreach ($contItem as $cid => $n) {
            if ($cid === $x || $categoria($cid) === 'vacina') {
                continue;
            }
            $nc = $contCom[$cid] ?? 0;
            $pctCom = $this->pct($nc, $nCom);
            $pctSem = $nSem ? $this->pct($n - $nc, $nSem) : null;
            $associados[] = [
                'nome' => $titulo($cid),
                'categoria' => ClassificacaoEstudo::CATEGORIAS[$categoria($cid)] ?? 'Outros',
                'nCom' => $nc,
                'pctCom' => $pctCom,
                'nSem' => $n - $nc,
                'pctSem' => $pctSem,
                'diferenca' => $pctSem === null ? null : round($pctCom - $pctSem, 1),
            ];
        }
        usort($associados, fn ($a, $b) => [$b['nCom'], $b['diferenca']] <=> [$a['nCom'], $a['diferenca']]);

        arsort($combinacoes);
        $topCombinacoes = [];
        foreach (array_slice($combinacoes, 0, 8, true) as $chave => $n) {
            $topCombinacoes[] = [
                'itens' => $chave === '' ? [] : array_map(fn ($cid) => $titulo((int) $cid), explode(',', (string) $chave)),
                'n' => $n,
                'pct' => $this->pct($n, $nCom),
            ];
        }

        // ---- Perfis, procedimento, atendimento e médico --------------------------------
        $perfis = array_fill_keys(array_keys(AnamneseAnaliseService::PERFIS), 0);
        foreach ($com as $p) {
            $perfis[AnamneseAnaliseService::classificarPerfil($p['k'], $p['cond'])]++;
        }
        $linhasPerfis = [];
        foreach (AnamneseAnaliseService::PERFIS as $chave => [$nome]) {
            $linhasPerfis[] = ['nome' => $nome, 'n' => $perfis[$chave], 'pct' => $this->pct($perfis[$chave], $nCom)];
        }

        $porGrupo = function (string $campo, callable $rotulo) use ($pacientes): array {
            $grupos = [];
            foreach ($pacientes as $p) {
                foreach ($p[$campo] as $g => $_) {
                    $grupos[$g] ??= ['n' => 0, 'total' => 0];
                    $grupos[$g]['total']++;
                    $grupos[$g]['n'] += $p['tem'] ? 1 : 0;
                }
            }
            $out = [];
            foreach ($grupos as $g => $c) {
                $out[] = ['nome' => $rotulo((string) $g)] + $this->grupo($c['n'], $c['total']);
            }
            usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);

            return $out;
        };

        // ---- Consistência do registro entre anamneses ----------------------------------
        $acompanhados = 0;
        $emTodas = 0;
        $apareceuDepois = 0;
        $naoRepetido = 0;
        $intervalos = [];
        foreach ($com as $p) {
            $dias = [];
            foreach ($p['ags'] as $ag) {
                $dia = substr($examesP[$ag]['dt'], 0, 10);
                $dias[$dia] = ($dias[$dia] ?? false) || isset($examesP[$ag]['cids'][$x]);
            }
            if (count($dias) < 2) {
                continue;
            }
            ksort($dias);
            $acompanhados++;
            $marcas = array_values($dias);
            $datas = array_keys($dias);
            $emTodas += !in_array(false, $marcas, true) ? 1 : 0;
            if (!$marcas[0]) {
                $apareceuDepois++;
                $intervalos[] = (new \DateTime($datas[0]))->diff(new \DateTime($datas[array_search(true, $marcas, true)]))->days;
            } elseif (in_array(false, $marcas, true)) {
                $naoRepetido++;
            }
        }

        // ---- Itens com nome parecido (a contagem pode estar dividida) -------------------
        $semelhantes = [];
        foreach (AnamneseCondicoes::itensSemelhantes($catalogo) as $par) {
            if ($par['a'] === $x || $par['b'] === $x) {
                $outro = $par['a'] === $x ? $par['b'] : $par['a'];
                $semelhantes[] = ['id' => $outro, 'nome' => $titulo($outro), 'n' => $contItem[$outro] ?? 0, 'motivo' => $par['motivo']];
            }
        }

        return $base + [
            'minGrupo' => AnamneseAnaliseService::MIN_GRUPO,
            'minMes' => AnamneseAnaliseService::MIN_MES,
            'sem' => $nSem,
            'prevalencia' => $this->pct($nCom, $nPac),
            'posicao' => array_search($x, array_keys($contItem), true) + 1,
            'totalItens' => count($contItem),
            'examesCom' => $examesCom,
            'pctExames' => $this->pct($examesCom, count($examesP)),
            'novos' => count($novosDoItem),
            'condicao' => $condicaoDoX ? AnamneseCondicoes::CONDICOES[$condicaoDoX]['rotulo'] : null,
            'ehHistorico' => in_array($condicaoDoX, AnamneseCondicoes::HISTORICO_CARDIOVASCULAR, true),
            'perfilCom' => $descrever($com),
            'perfilSem' => $descrever($sem),
            'porFaixa' => $porFaixa,
            'porSexo' => $porSexo,
            'piramide' => [
                'faixas' => $faixas,
                'Feminino' => array_map(fn ($fx) => $grade['Feminino'][$fx]['n'], $faixas),
                'Masculino' => array_map(fn ($fx) => $grade['Masculino'][$fx]['n'], $faixas),
            ],
            'mensal' => $mensal,
            'outras' => ['rotulos' => self::CLASSES_OUTRAS, 'com' => $distribuir($com), 'sem' => $distribuir($sem)],
            'associados' => $associados,
            'combinacoes' => $topCombinacoes,
            'perfis' => $linhasPerfis,
            'porProcedimento' => $porGrupo('procs', fn ($g) => AnamneseEstatisticaService::titulo($g)),
            'porTipo' => $porGrupo('tipos', fn ($g) => AnamneseEstatisticaService::TIPOS_ATENDIMENTO[$g] ?? 'Sem agendamento vinculado'),
            'porMedico' => $porGrupo('medicos', fn ($g) => $g),
            'consistencia' => [
                'acompanhados' => $acompanhados,
                'emTodas' => $emTodas,
                'pctEmTodas' => $this->pct($emTodas, $acompanhados),
                'apareceuDepois' => $apareceuDepois,
                'pctApareceuDepois' => $this->pct($apareceuDepois, $acompanhados),
                'naoRepetido' => $naoRepetido,
                'pctNaoRepetido' => $this->pct($naoRepetido, $acompanhados),
                'diasAteAparecer' => $this->mediana($intervalos),
            ],
            'semelhantes' => $semelhantes,
        ];
    }

    /**
     * Todas as tabelas do panorama em sequência, para exportação em CSV.
     *
     * @return list<list<string|int|float|null>>
     */
    public function linhasExportacao(array $p): array
    {
        if (empty($p['com'])) {
            return [['item', $p['item']['nome'] ?? ''], ['pacientes_com_o_item', 0]];
        }
        $n = fn ($v, int $casas = 1) => $v === null ? '' : number_format((float) $v, $casas, ',', '');
        $grupo = fn (array $g) => [$g['total'], $g['n'], $n($g['pct'])];

        $l = [
            ['item', $p['item']['nome']],
            ['categoria', $p['item']['categoriaRotulo']],
            [],
            ['indicador', 'valor'],
            ['pacientes_com_anamnese', $p['pacientes']],
            ['pacientes_com_o_item', $p['com']],
            ['prevalencia_pct', $n($p['prevalencia'])],
            ['posicao_entre_os_itens', $p['posicao']],
            ['exames_com_o_item_marcado', $p['examesCom']],
            ['pct_dos_exames', $n($p['pctExames'])],
            ['novos_registros_no_periodo', $p['novos']],
            [],
            ['comparacao', 'com_o_item', 'sem_o_item'],
        ];
        foreach (['n' => 0, 'idadeMedia' => 1, 'idadeMediana' => 1, 'pct60' => 1, 'pctFeminino' => 1, 'outrasMedia' => 2, 'outrasMediana' => 1, 'pct2Outras' => 1, 'pctEvento' => 1, 'examesPorPaciente' => 2] as $campo => $casas) {
            $l[] = [$campo, $n($p['perfilCom'][$campo], $casas), $n($p['perfilSem'][$campo], $casas)];
        }

        $l[] = [];
        $l[] = ['faixa_etaria', 'sexo', 'pacientes_do_grupo', 'pacientes_com_o_item', 'pct_do_grupo'];
        foreach ($p['porFaixa'] as $f) {
            foreach (['geral' => 'Todos', 'Feminino' => 'Feminino', 'Masculino' => 'Masculino'] as $chave => $sexo) {
                $l[] = array_merge([$f['faixa'], $sexo], $grupo($f[$chave]));
            }
        }

        $l[] = [];
        $l[] = ['mes', 'pacientes_do_mes', 'pacientes_com_o_item', 'pct_do_mes', 'novos_registros'];
        foreach ($p['mensal']['meses'] as $i => $mes) {
            $l[] = [$mes, $p['mensal']['pacientes'][$i], $p['mensal']['com'][$i], $n($p['mensal']['pct'][$i]), $p['mensal']['novos'][$i]];
        }

        $l[] = [];
        $l[] = ['outras_comorbidades', 'n_com_o_item', 'pct_com_o_item', 'n_sem_o_item', 'pct_sem_o_item'];
        foreach ($p['outras']['rotulos'] as $i => $r) {
            $l[] = [$r, $p['outras']['com']['n'][$i], $n($p['outras']['com']['pct'][$i]), $p['outras']['sem']['n'][$i], $n($p['outras']['sem']['pct'][$i])];
        }

        $l[] = [];
        $l[] = ['item_associado', 'categoria', 'n_entre_quem_tem', 'pct_entre_quem_tem', 'n_entre_quem_nao_tem', 'pct_entre_quem_nao_tem', 'diferenca_pontos_percentuais'];
        foreach ($p['associados'] as $a) {
            $l[] = [$a['nome'], $a['categoria'], $a['nCom'], $n($a['pctCom']), $a['nSem'], $n($a['pctSem']), $n($a['diferenca'])];
        }

        $l[] = [];
        $l[] = ['combinacao_de_outras_comorbidades', 'pacientes', 'pct_de_quem_tem_o_item'];
        foreach ($p['combinacoes'] as $c) {
            $l[] = [$c['itens'] ? implode(' + ', $c['itens']) : 'Nenhuma outra comorbidade', $c['n'], $n($c['pct'])];
        }

        $l[] = [];
        $l[] = ['perfil_clinico', 'pacientes', 'pct_de_quem_tem_o_item'];
        foreach ($p['perfis'] as $pf) {
            $l[] = [$pf['nome'], $pf['n'], $n($pf['pct'])];
        }

        foreach (['porSexo' => 'sexo', 'porProcedimento' => 'procedimento', 'porTipo' => 'tipo_de_atendimento', 'porMedico' => 'medico'] as $chave => $rotulo) {
            $l[] = [];
            $l[] = [$rotulo, 'pacientes_do_grupo', 'pacientes_com_o_item', 'pct_do_grupo'];
            foreach ($p[$chave] as $g) {
                $l[] = array_merge([$g['nome']], $grupo($g));
            }
        }

        $c = $p['consistencia'];
        $l[] = [];
        $l[] = ['consistencia_entre_anamneses', 'valor'];
        $l[] = ['pacientes_com_o_item_e_anamnese_em_mais_de_um_dia', $c['acompanhados']];
        $l[] = ['marcado_em_todas_as_anamneses', $c['emTodas']];
        $l[] = ['apareceu_depois_da_primeira', $c['apareceuDepois']];
        $l[] = ['nao_repetido_em_anamnese_posterior', $c['naoRepetido']];
        $l[] = ['dias_ate_aparecer_mediana', $n($c['diasAteAparecer'])];

        return $l;
    }

    /** @return array{n: int, total: int, pct: ?float, pequena: bool} */
    private function grupo(int $n, int $total): array
    {
        return ['n' => $n, 'total' => $total, 'pct' => $total ? $this->pct($n, $total) : null, 'pequena' => $total < AnamneseAnaliseService::MIN_GRUPO];
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
