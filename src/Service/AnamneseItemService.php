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
        ] + $this->aprofundamento($catalogo, $examesP, $meses, $x, $condicaoDoX, $pacientes);
    }

    /**
     * Blocos de aprofundamento do panorama: multimorbidade por faixa etária, carga cardiovascular registrada,
     * subgrupo com hipertensão, histórico cardiovascular por faixa etária, matriz de condições, volume mensal,
     * distribuição por procedimento e acompanhamento longitudinal.
     *
     * "Carga cardiovascular registrada" conta infarto, AVC, cateterismo e angioplastia (sem o próprio item,
     * quando ele é uma dessas condições). É uma contagem de registros, não uma medida de risco.
     */
    private function aprofundamento(array $catalogo, array $examesP, array $meses, int $x, ?string $condicaoDoX, array $pacientes): array
    {
        $min = AnamneseAnaliseService::MIN_GRUPO;
        $titulo = fn (int $cid) => AnamneseEstatisticaService::titulo($catalogo[$cid]['nome'] ?? '?');
        $categoria = fn (int $cid) => $catalogo[$cid]['categoria'] ?? 'outros';
        $rotulo = fn (string $c) => AnamneseCondicoes::CONDICOES[$c]['rotulo'];
        $itensPorCondicao = AnamneseCondicoes::itensPorCondicao($catalogo);
        $eventos = array_values(array_diff(AnamneseCondicoes::HISTORICO_CARDIOVASCULAR, [$condicaoDoX]));
        $parceiro = $condicaoDoX === 'hipertensao' ? 'diabetes' : 'hipertensao';
        $itensParceiro = array_flip($itensPorCondicao[$parceiro]);

        foreach ($pacientes as $pid => $p) {
            $pacientes[$pid]['ev'] = count(array_intersect_key($p['cond'], array_flip($eventos)));
            $pacientes[$pid]['outrasSemParceiro'] = count(array_filter(
                array_keys($p['cids']),
                fn ($cid) => $cid !== $x && !isset($itensParceiro[$cid]) && $categoria($cid) === 'comorbidade'
            ));
        }
        $com = array_values(array_filter($pacientes, fn ($p) => $p['tem']));
        $sem = array_values(array_filter($pacientes, fn ($p) => !$p['tem']));
        $nCom = count($com);

        $media = fn (array $v, int $casas) => $v ? round(array_sum($v) / count($v), $casas) : null;
        $idades = fn (array $lista) => array_values(array_filter(array_column($lista, 'idade'), fn ($i) => $i !== null));
        $quantos = fn (array $lista, callable $criterio) => count(array_filter($lista, $criterio));
        $parte = fn (int $n, int $total) => ['n' => $n, 'pct' => $total ? $this->pct($n, $total) : null];

        // ---- 1. Multimorbidade por faixa etária (só pacientes com o item) ---------------
        $porFaixa = array_fill_keys(AnamneseEstatisticaService::FAIXAS, []) + ['Sem idade' => []];
        foreach ($com as $p) {
            $porFaixa[$p['faixa']][] = $p;
        }
        $multiFaixa = [];
        foreach ($porFaixa as $faixa => $lista) {
            if ($faixa === 'Sem idade' && !$lista) {
                continue;
            }
            $n = count($lista);
            $outras = array_column($lista, 'outras');
            $celulas = [];
            foreach (self::CLASSES_OUTRAS as $i => $_) {
                $celulas[] = $parte($quantos($lista, fn ($p) => min(4, $p['outras']) === $i), $n);
            }
            $multiFaixa[] = [
                'faixa' => (string) $faixa,
                'n' => $n,
                'celulas' => $celulas,
                'media' => $media($outras, 2),
                'mediana' => $this->mediana($outras),
                'dois' => $parte($quantos($lista, fn ($p) => $p['outras'] >= 2), $n),
                'quatro' => $parte($quantos($lista, fn ($p) => $p['outras'] >= 4), $n),
                'pequena' => $n < $min,
            ];
        }

        // ---- 2. Carga cardiovascular registrada ----------------------------------------
        $classes = [];
        foreach (range(0, count($eventos)) as $i) {
            $lista = array_values(array_filter($com, fn ($p) => $p['ev'] === $i));
            $classes[] = ['rotulo' => $i === 0 ? 'Nenhum' : (string) $i] + $parte(count($lista), $nCom) + [
                'idadeMedia' => $media($idades($lista), 1),
                'idadeMediana' => $this->mediana($idades($lista)),
                'outrasMedia' => $media(array_column($lista, 'outras'), 2),
                'pequena' => count($lista) < $min,
            ];
        }
        $linhasEventos = [];
        foreach ($eventos as $e) {
            $linhasEventos[] = ['chave' => $e, 'rotulo' => $rotulo($e), 'itens' => array_map($titulo, $itensPorCondicao[$e])] + $parte($quantos($com, fn ($p) => isset($p['cond'][$e])), $nCom);
        }

        // ---- 3. Subgrupo: com e sem hipertensão (ou diabetes, quando o item é a hipertensão) ----
        $descrever = function (array $lista) use ($nCom, $eventos, $idades, $media, $quantos, $parte, $min): array {
            $n = count($lista);
            $sexoConhecido = $quantos($lista, fn ($p) => $p['sexo'] !== 'Não informado');
            $porEvento = [];
            foreach ($eventos as $e) {
                $porEvento[$e] = $parte($quantos($lista, fn ($p) => isset($p['cond'][$e])), $n);
            }

            return [
                'n' => $n,
                'pct' => $this->pct($n, $nCom),
                'idadeMedia' => $media($idades($lista), 1),
                'idadeMediana' => $this->mediana($idades($lista)),
                'pctFeminino' => $sexoConhecido ? $this->pct($quantos($lista, fn ($p) => $p['sexo'] === 'Feminino'), $sexoConhecido) : null,
                'pctMasculino' => $sexoConhecido ? $this->pct($quantos($lista, fn ($p) => $p['sexo'] === 'Masculino'), $sexoConhecido) : null,
                'outrasMedia' => $media(array_column($lista, 'outrasSemParceiro'), 2),
                'eventos' => $porEvento,
                'algum' => $parte($quantos($lista, fn ($p) => $p['ev'] > 0), $n),
                'pequena' => $n < $min,
            ];
        };
        $subgrupo = [
            'parceiro' => $rotulo($parceiro),
            'itens' => array_map($titulo, array_keys($itensParceiro)),
            'disponivel' => (bool) $itensParceiro,
            'sem' => $descrever(array_values(array_filter($com, fn ($p) => !isset($p['cond'][$parceiro])))),
            'com' => $descrever(array_values(array_filter($com, fn ($p) => isset($p['cond'][$parceiro])))),
        ];

        // ---- 4. Histórico cardiovascular por faixa etária: com x sem o item --------------
        $faixasAdultas = array_slice(AnamneseEstatisticaService::FAIXAS, 1);
        $criterios = ['algum' => ['Algum histórico cardiovascular', fn ($p) => $p['ev'] > 0]];
        foreach ($eventos as $e) {
            $criterios[$e] = [$rotulo($e), fn ($p) => isset($p['cond'][$e])];
        }
        $estratificado = [];
        foreach ($criterios as $chave => [$nomeCriterio, $criterio]) {
            $linhas = [];
            foreach ($faixasAdultas as $faixa) {
                $a = array_filter($com, fn ($p) => $p['faixa'] === $faixa);
                $b = array_filter($sem, fn ($p) => $p['faixa'] === $faixa);
                $ka = $quantos($a, $criterio);
                $kb = $quantos($b, $criterio);
                $pa = count($a) >= $min ? $this->pct($ka, count($a)) : null;
                $pb = count($b) >= $min ? $this->pct($kb, count($b)) : null;
                $linhas[] = [
                    'faixa' => $faixa,
                    'comN' => count($a), 'comK' => $ka, 'comPct' => $pa,
                    'semN' => count($b), 'semK' => $kb, 'semPct' => $pb,
                    'diferenca' => $pa === null || $pb === null ? null : round($pa - $pb, 1),
                ];
            }
            $estratificado[] = ['chave' => $chave, 'rotulo' => $nomeCriterio, 'linhas' => $linhas];
        }

        // ---- 5. Matriz de condições entre os pacientes com o item ----------------------
        $condicoes = array_values(array_diff(array_keys(AnamneseCondicoes::CONDICOES), [$condicaoDoX]));
        $totais = array_map(fn ($c) => $quantos($com, fn ($p) => isset($p['cond'][$c])), $condicoes);
        $matrizN = [];
        $matrizPct = [];
        foreach ($condicoes as $i => $a) {
            foreach ($condicoes as $j => $b) {
                $ambos = $i === $j ? null : $quantos($com, fn ($p) => isset($p['cond'][$a], $p['cond'][$b]));
                $matrizN[$i][$j] = $ambos;
                $matrizPct[$i][$j] = $ambos === null || $totais[$i] < $min ? null : $this->pct($ambos, $totais[$i]);
            }
        }

        // ---- 6. Volume mensal (pacientes e exames) -------------------------------------
        $porMes = [];
        foreach ($examesP as $e) {
            $m = &$porMes[$e['mes']];
            $m ??= ['pacientes' => [], 'com' => [], 'exames' => 0, 'examesCom' => 0];
            $m['pacientes'][$e['pid']] = true;
            $m['exames']++;
            if (isset($e['cids'][$x])) {
                $m['com'][$e['pid']] = true;
                $m['examesCom']++;
            }
            unset($m);
        }
        $volume = ['meses' => [], 'pacientesCom' => [], 'pacientesTotal' => [], 'pct' => [], 'examesCom' => [], 'examesTotal' => []];
        foreach ($meses as $mes) {
            $m = $porMes[$mes] ?? ['pacientes' => [], 'com' => [], 'exames' => 0, 'examesCom' => 0];
            $volume['meses'][] = AnamneseEstatisticaService::rotuloMes($mes);
            $volume['pacientesCom'][] = count($m['com']);
            $volume['pacientesTotal'][] = count($m['pacientes']);
            $volume['pct'][] = $m['pacientes'] ? $this->pct(count($m['com']), count($m['pacientes'])) : null;
            $volume['examesCom'][] = $m['examesCom'];
            $volume['examesTotal'][] = $m['exames'];
        }

        // ---- 7. Onde os pacientes com o item são atendidos ------------------------------
        $porProc = [];
        foreach ($examesP as $e) {
            if ($pacientes[$e['pid']]['tem']) {
                $porProc[$e['proc']] ??= ['pacientes' => [], 'exames' => 0];
                $porProc[$e['proc']]['pacientes'][$e['pid']] = true;
                $porProc[$e['proc']]['exames']++;
            }
        }
        $procedimentos = [];
        foreach ($porProc as $proc => $g) {
            $procedimentos[] = ['nome' => AnamneseEstatisticaService::titulo((string) $proc)] + $parte(count($g['pacientes']), $nCom) + ['exames' => $g['exames']];
        }
        usort($procedimentos, fn ($a, $b) => [$b['n'], $b['exames']] <=> [$a['n'], $a['exames']]);

        // ---- 8. Acompanhamento longitudinal dos pacientes com o item --------------------
        $momentos = [];
        $intervalos = [];
        $mudanca = ['Mesma quantidade' => 0, '+1 item registrado' => 0, '+2 ou mais' => 0, 'Menos itens na última anamnese' => 0];
        $apareceu = [];
        foreach ($pacientes as $p) {
            if (!$p['tem']) {
                continue;
            }
            $dias = [];
            foreach ($p['ags'] as $ag) {
                $dia = substr($examesP[$ag]['dt'], 0, 10);
                $dias[$dia] = ($dias[$dia] ?? []) + array_filter($examesP[$ag]['cids'], fn ($cid) => $categoria($cid) !== 'vacina', ARRAY_FILTER_USE_KEY);
            }
            if (count($dias) < 2) {
                continue;
            }
            ksort($dias);
            $datas = array_keys($dias);
            $momentos[] = count($dias);
            $intervalos[] = (new \DateTime($datas[0]))->diff(new \DateTime(end($datas)))->days;

            $primeira = $dias[$datas[0]];
            $vistos = $primeira;
            foreach (array_slice($datas, 1) as $data) {
                foreach (array_diff_key($dias[$data], $vistos) as $cid => $_) {
                    if ($cid !== $x) {
                        $apareceu[$cid][] = (new \DateTime($datas[0]))->diff(new \DateTime($data))->days;
                    }
                }
                $vistos += $dias[$data];
            }

            $contar = fn (array $cids) => count(array_filter(array_keys($cids), fn ($cid) => $categoria($cid) === 'comorbidade'));
            $delta = $contar($dias[end($datas)]) - $contar($primeira);
            $mudanca[match (true) {
                $delta === 0 => 'Mesma quantidade',
                $delta === 1 => '+1 item registrado',
                $delta >= 2 => '+2 ou mais',
                default => 'Menos itens na última anamnese',
            }]++;
        }
        $acompanhados = count($momentos);

        $condicaoDoItem = [];
        foreach ($itensPorCondicao as $condicao => $cids) {
            $condicaoDoItem += array_fill_keys($cids, $condicao);
        }
        $novosItens = [];
        foreach ($apareceu as $cid => $dias) {
            $novosItens[] = [
                'nome' => $titulo($cid),
                'categoria' => ClassificacaoEstudo::CATEGORIAS[$categoria($cid)] ?? 'Outros',
                'destaque' => isset($condicaoDoItem[$cid]),
                'n' => count($dias),
                'pct' => $this->pct(count($dias), $acompanhados),
                'diasMediana' => $this->mediana($dias),
            ];
        }
        usort($novosItens, fn ($a, $b) => [$b['destaque'], $b['n'], $a['nome']] <=> [$a['destaque'], $a['n'], $b['nome']]);

        $distribuicao = [];
        foreach (['2 momentos' => fn ($m) => $m === 2, '3 momentos' => fn ($m) => $m === 3, '4 ou mais momentos' => fn ($m) => $m >= 4] as $nome => $criterio) {
            $distribuicao[] = ['rotulo' => $nome] + $parte($quantos($momentos, $criterio), $acompanhados);
        }
        $linhasMudanca = [];
        foreach ($mudanca as $nome => $n) {
            $linhasMudanca[] = ['rotulo' => $nome] + $parte($n, $acompanhados);
        }

        return [
            'multiFaixa' => $multiFaixa,
            'cardio' => [
                'eventos' => $linhasEventos,
                'classes' => $classes,
                'comAlgum' => $parte($quantos($com, fn ($p) => $p['ev'] > 0), $nCom),
                'semItem' => array_values(array_map(fn ($l) => $l['rotulo'], array_filter($linhasEventos, fn ($l) => !$l['itens']))),
            ],
            'subgrupo' => $subgrupo,
            'estratificado' => $estratificado,
            'matriz' => ['itens' => array_map($rotulo, $condicoes), 'totais' => $totais, 'n' => $matrizN, 'pct' => $matrizPct],
            'volume' => $volume,
            'procedimentos' => $procedimentos,
            'longitudinal' => [
                'acompanhados' => $acompanhados,
                'momentosMedia' => $media($momentos, 2),
                'momentosMediana' => $this->mediana($momentos),
                'intervaloMedio' => $media($intervalos, 1),
                'intervaloMediano' => $this->mediana($intervalos),
                'intervaloMaior' => $intervalos ? max($intervalos) : null,
                'distribuicao' => $distribuicao,
                'novos' => $novosItens,
                'mudanca' => $linhasMudanca,
            ],
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

        $l[] = [];
        $l[] = array_merge(['multimorbidade_por_faixa_etaria', 'pacientes_com_o_item'], array_map(fn ($r) => 'n_' . $r, self::CLASSES_OUTRAS), array_map(fn ($r) => 'pct_' . $r, self::CLASSES_OUTRAS), ['media_outras_comorbidades', 'mediana', 'n_2_ou_mais', 'pct_2_ou_mais', 'n_4_ou_mais', 'pct_4_ou_mais']);
        foreach ($p['multiFaixa'] as $f) {
            $l[] = array_merge(
                [$f['faixa'], $f['n']],
                array_column($f['celulas'], 'n'),
                array_map(fn ($cel) => $n($cel['pct']), $f['celulas']),
                [$n($f['media'], 2), $n($f['mediana']), $f['dois']['n'], $n($f['dois']['pct']), $f['quatro']['n'], $n($f['quatro']['pct'])]
            );
        }

        $l[] = [];
        $l[] = ['carga_cardiovascular_registrada', 'pacientes', 'pct', 'idade_media', 'idade_mediana', 'media_outras_comorbidades'];
        foreach ($p['cardio']['classes'] as $cl) {
            $l[] = [$cl['rotulo'], $cl['n'], $n($cl['pct']), $n($cl['idadeMedia']), $n($cl['idadeMediana']), $n($cl['outrasMedia'], 2)];
        }
        $l[] = [];
        $l[] = ['antecedente_cardiovascular', 'itens_do_catalogo_considerados', 'pacientes', 'pct_de_quem_tem_o_item'];
        foreach ($p['cardio']['eventos'] as $e) {
            $l[] = [$e['rotulo'], implode(' | ', $e['itens']), $e['n'], $n($e['pct'])];
        }

        $sg = $p['subgrupo'];
        $l[] = [];
        $l[] = ['subgrupo_' . $sg['parceiro'], 'sem_' . $sg['parceiro'], 'com_' . $sg['parceiro']];
        foreach (['n' => 0, 'pct' => 1, 'idadeMedia' => 1, 'idadeMediana' => 1, 'pctFeminino' => 1, 'pctMasculino' => 1, 'outrasMedia' => 2] as $campo => $casas) {
            $l[] = [$campo, $n($sg['sem'][$campo], $casas), $n($sg['com'][$campo], $casas)];
        }
        foreach ($p['cardio']['eventos'] as $e) {
            $l[] = ['pct_' . $e['rotulo'], $n($sg['sem']['eventos'][$e['chave']]['pct']), $n($sg['com']['eventos'][$e['chave']]['pct'])];
        }
        $l[] = ['pct_algum_historico_cardiovascular', $n($sg['sem']['algum']['pct']), $n($sg['com']['algum']['pct'])];

        $l[] = [];
        $l[] = ['criterio', 'faixa_etaria', 'com_o_item_pacientes', 'com_o_item_com_o_criterio', 'com_o_item_pct', 'sem_o_item_pacientes', 'sem_o_item_com_o_criterio', 'sem_o_item_pct', 'diferenca_pontos_percentuais'];
        foreach ($p['estratificado'] as $serie) {
            foreach ($serie['linhas'] as $r) {
                $l[] = [$serie['rotulo'], $r['faixa'], $r['comN'], $r['comK'], $n($r['comPct']), $r['semN'], $r['semK'], $n($r['semPct']), $n($r['diferenca'])];
            }
        }

        $mz = $p['matriz'];
        $l[] = [];
        $l[] = ['condicao_da_linha', 'pacientes_com_a_condicao', 'condicao_da_coluna', 'pacientes_com_as_duas', 'pct_da_linha'];
        foreach ($mz['itens'] as $i => $a) {
            foreach ($mz['itens'] as $j => $b) {
                if ($i !== $j) {
                    $l[] = [$a, $mz['totais'][$i], $b, $mz['n'][$i][$j], $n($mz['pct'][$i][$j])];
                }
            }
        }

        $v = $p['volume'];
        $l[] = [];
        $l[] = ['mes', 'pacientes_com_o_item', 'total_de_pacientes', 'pct', 'exames_com_o_item', 'total_de_exames'];
        foreach ($v['meses'] as $i => $mes) {
            $l[] = [$mes, $v['pacientesCom'][$i], $v['pacientesTotal'][$i], $n($v['pct'][$i]), $v['examesCom'][$i], $v['examesTotal'][$i]];
        }

        $l[] = [];
        $l[] = ['procedimento', 'pacientes_com_o_item', 'pct_de_quem_tem_o_item', 'exames'];
        foreach ($p['procedimentos'] as $pr) {
            $l[] = [$pr['nome'], $pr['n'], $n($pr['pct']), $pr['exames']];
        }

        $lg = $p['longitudinal'];
        $l[] = [];
        $l[] = ['acompanhamento_longitudinal', 'valor'];
        $l[] = ['pacientes_acompanhados', $lg['acompanhados']];
        $l[] = ['momentos_por_paciente_media', $n($lg['momentosMedia'], 2)];
        $l[] = ['momentos_por_paciente_mediana', $n($lg['momentosMediana'])];
        $l[] = ['dias_entre_primeira_e_ultima_media', $n($lg['intervaloMedio'])];
        $l[] = ['dias_entre_primeira_e_ultima_mediana', $n($lg['intervaloMediano'])];
        $l[] = ['dias_entre_primeira_e_ultima_maior', $lg['intervaloMaior']];
        foreach (array_merge($lg['distribuicao'], $lg['mudanca']) as $r) {
            $l[] = [$r['rotulo'], $r['n'], $n($r['pct'])];
        }
        $l[] = [];
        $l[] = ['item_que_apareceu_depois', 'categoria', 'pacientes', 'pct_dos_acompanhados', 'mediana_de_dias_ate_aparecer'];
        foreach ($lg['novos'] as $it) {
            $l[] = [$it['nome'], $it['categoria'], $it['n'], $n($it['pct']), $n($it['diasMediana'])];
        }

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
