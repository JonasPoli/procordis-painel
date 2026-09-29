<?php

namespace App\Service;

use App\Entity\ClassificacaoEstudo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Estatísticas da anamnese (somente agregados — nenhum nome de paciente sai daqui).
 *
 * Definições:
 * - "Paciente com anamnese no período": teve ao menos um exame com classificação no período.
 * - Prevalência de um item = pacientes com o item marcado em algum exame do período ÷ pacientes com anamnese no período.
 *   (Ausência da marcação não prova ausência da condição; por isso o denominador é só quem teve anamnese.)
 * - Novo diagnóstico = item que aparece pela 1ª vez num paciente que já tinha exame anterior com anamnese sem esse item.
 * - Cobertura = exames (agendamentos atendidos) com anamnese ÷ exames atendidos.
 */
class AnamneseEstatisticaService
{
    public const FAIXAS = ['0–17', '18–29', '30–39', '40–49', '50–59', '60–69', '70–79', '80+'];

    public const TIPOS_ATENDIMENTO = ['sus' => 'SUS', 'convenio' => 'Convênio', 'particular' => 'Particular', 'filantropico' => 'Filantrópico'];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param array{inicio: \DateTimeInterface, fim: \DateTimeInterface, sexo?: ?string, faixa?: ?string, tipo?: ?string, procedimento?: ?string} $f
     */
    public function obterPainel(array $f): array
    {
        $conn = $this->em->getConnection();
        $catalogo = $this->catalogo();
        $ini = $f['inicio']->format('Y-m-d 00:00:00');
        $fim = $f['fim']->format('Y-m-d 23:59:59');

        // Todas as marcações ativas (histórico inteiro — necessário para "novos diagnósticos").
        $rows = $conn->fetchAllAssociative(
            'SELECT ec.paciente_id AS pid, ec.cod_agendamento AS ag, ec.data_exame AS dt, ec.classificacao_id AS cid,
                    p.sexo, p.data_nascimento AS nasc, a.procedimento_nome AS proc, a.tipo_atendimento AS tipo
               FROM exame_classificacao ec
               JOIN classificacao_estudo c ON c.id = ec.classificacao_id AND c.ativo = 1
               JOIN paciente p ON p.id = ec.paciente_id
               LEFT JOIN agendamento a ON a.id = ec.agendamento_id
              WHERE ec.removido_em IS NULL
              ORDER BY ec.data_exame'
        );

        // ---- 1. Exames (agregando as marcações por exame) -------------------------------
        $exames = [];          // ag => [pid, dt, mes, sexo, idade, faixa, proc, tipo, cids[]]
        foreach ($rows as $r) {
            $ag = $r['ag'];
            if (!isset($exames[$ag])) {
                $dt = substr((string) $r['dt'], 0, 19);
                $idade = $r['nasc'] ? (new \DateTime(substr($r['nasc'], 0, 10)))->diff(new \DateTime($dt))->y : null;
                $exames[$ag] = [
                    'pid' => (int) $r['pid'],
                    'dt' => $dt,
                    'mes' => substr($dt, 0, 7),
                    'sexo' => $this->normalizarSexo($r['sexo']),
                    'idade' => $idade,
                    'faixa' => $this->faixa($idade),
                    'proc' => $r['proc'] ? mb_strtoupper(trim($r['proc'])) : 'SEM AGENDAMENTO VINCULADO',
                    'tipo' => $r['tipo'] ?: 'nd',
                    'cids' => [],
                ];
            }
            $exames[$ag]['cids'][(int) $r['cid']] = true;
        }

        // ---- 2. Novos diagnósticos (histórico completo por paciente) -------------------
        $novos = [];           // lista de [mes, cid, exame]
        $porPaciente = [];
        foreach ($exames as $ag => $e) {
            $porPaciente[$e['pid']][] = $ag;
        }
        foreach ($porPaciente as $ags) {
            $visto = [];
            foreach ($ags as $i => $ag) {
                foreach ($exames[$ag]['cids'] as $cid => $_) {
                    if (!isset($visto[$cid]) && $i > 0 && !$this->ehVacina($catalogo, $cid)) {
                        $novos[] = ['ag' => $ag, 'cid' => $cid];
                    }
                    $visto[$cid] = true;
                }
            }
        }

        // ---- 3. Filtro do período e dos recortes --------------------------------------
        $noFiltro = fn (array $e) => $e['dt'] >= $ini && $e['dt'] <= $fim && $this->passaRecorte($e, $f);
        $examesP = array_filter($exames, $noFiltro);

        // Paciente no período: união dos itens + dados do exame mais recente
        $pacientes = [];
        foreach ($examesP as $e) {
            $p = &$pacientes[$e['pid']];
            $p ??= ['cids' => [], 'exames' => 0];
            $p['cids'] += $e['cids'];
            $p['exames']++;
            $p['sexo'] = $e['sexo'];
            $p['idade'] = $e['idade'];
            $p['faixa'] = $e['faixa'];
            unset($p);
        }
        $nPac = count($pacientes);
        $nEx = count($examesP);

        // ---- 4. Prevalência por item ---------------------------------------------------
        $contItem = [];
        foreach ($pacientes as $p) {
            foreach ($p['cids'] as $cid => $_) {
                $contItem[$cid] = ($contItem[$cid] ?? 0) + 1;
            }
        }
        arsort($contItem);
        $prevalencia = [];
        foreach ($contItem as $cid => $n) {
            $prevalencia[] = [
                'id' => $cid,
                'nome' => $this->titulo($catalogo[$cid]['nome'] ?? '?'),
                'categoria' => $catalogo[$cid]['categoria'] ?? 'outros',
                'n' => $n,
                'pct' => $this->pct($n, $nPac),
            ];
        }

        $idsComorb = array_values(array_filter(array_keys($contItem), fn ($cid) => ($catalogo[$cid]['categoria'] ?? '') === 'comorbidade'));
        $idsNaoVacina = array_values(array_filter(array_keys($contItem), fn ($cid) => !$this->ehVacina($catalogo, $cid)));
        $topComorb = array_slice($idsComorb, 0, 10);
        $topGeral = array_slice($idsNaoVacina, 0, 10);

        // ---- 5. Multimorbidade (quantas comorbidades por paciente) ---------------------
        $multi = array_fill_keys(['0', '1', '2', '3', '4', '5+'], 0);
        $somaComorb = 0;
        $combinacoes = [];
        foreach ($pacientes as $p) {
            $cs = array_values(array_filter(array_keys($p['cids']), fn ($cid) => ($catalogo[$cid]['categoria'] ?? '') === 'comorbidade'));
            $k = count($cs);
            $somaComorb += $k;
            $multi[$k >= 5 ? '5+' : (string) $k]++;
            if ($k >= 2) {
                sort($cs);
                $chave = implode(',', $cs);
                $combinacoes[$chave] = ($combinacoes[$chave] ?? 0) + 1;
            }
        }
        arsort($combinacoes);
        $topComb = [];
        foreach (array_slice($combinacoes, 0, 10, true) as $chave => $n) {
            $topComb[] = [
                'itens' => array_map(fn ($cid) => $this->titulo($catalogo[(int) $cid]['nome'] ?? '?'), explode(',', (string) $chave)),
                'n' => $n,
                'pct' => $this->pct($n, $nPac),
            ];
        }

        // ---- 6. Co-ocorrência P(coluna | linha) entre as principais comorbidades --------
        $coocorrencia = ['itens' => [], 'matriz' => []];
        foreach ($topComorb as $a) {
            $coocorrencia['itens'][] = $this->titulo($catalogo[$a]['nome']);
            $linha = [];
            foreach ($topComorb as $b) {
                if ($a === $b) {
                    $linha[] = null;
                    continue;
                }
                $comA = 0;
                $ambos = 0;
                foreach ($pacientes as $p) {
                    if (isset($p['cids'][$a])) {
                        $comA++;
                        if (isset($p['cids'][$b])) {
                            $ambos++;
                        }
                    }
                }
                $linha[] = $this->pct($ambos, $comA);
            }
            $coocorrencia['matriz'][] = $linha;
        }

        // ---- 7. Sexo, faixa etária, tipo de atendimento, procedimento -------------------
        $porSexo = $this->prevalenciaPorGrupo($pacientes, 'sexo', ['Feminino', 'Masculino'], $topGeral, $catalogo);
        $contFaixa = array_count_values(array_column($pacientes, 'faixa'));
        $faixasPresentes = array_values(array_filter(self::FAIXAS, fn ($fx) => ($contFaixa[$fx] ?? 0) >= 10));
        $porFaixa = $this->prevalenciaPorGrupo($pacientes, 'faixa', $faixasPresentes, $topGeral, $catalogo);

        $examesComoGrupo = array_map(fn ($e) => ['cids' => $e['cids'], 'tipo' => $e['tipo'], 'proc' => $e['proc']], $examesP);
        $tiposPresentes = array_values(array_intersect(array_keys(self::TIPOS_ATENDIMENTO), array_unique(array_column($examesComoGrupo, 'tipo'))));
        $porTipo = $this->prevalenciaPorGrupo($examesComoGrupo, 'tipo', $tiposPresentes, array_slice($topGeral, 0, 8), $catalogo);
        $porTipo['grupos'] = array_map(fn ($t) => self::TIPOS_ATENDIMENTO[$t] ?? $t, $porTipo['grupos']);

        $contProc = array_count_values(array_column($examesComoGrupo, 'proc'));
        arsort($contProc);
        $procs = array_slice(array_keys($contProc), 0, 8);
        $porProcedimento = $this->prevalenciaPorGrupo($examesComoGrupo, 'proc', $procs, array_slice($topGeral, 0, 8), $catalogo);
        $porProcedimento['grupos'] = array_map(fn ($p) => $this->titulo($p), $porProcedimento['grupos']);

        // Pirâmide etária dos pacientes com anamnese
        $piramide = ['faixas' => self::FAIXAS, 'Feminino' => array_fill(0, 8, 0), 'Masculino' => array_fill(0, 8, 0)];
        foreach ($pacientes as $p) {
            $i = array_search($p['faixa'], self::FAIXAS, true);
            if ($i !== false && isset($piramide[$p['sexo']])) {
                $piramide[$p['sexo']][$i]++;
            }
        }

        // ---- 8. Vacina COVID: dose máxima declarada por faixa etária ---------------------
        $vacina = $this->vacinas($pacientes, $catalogo);

        // ---- 9. Série mensal: prevalência dos principais itens + volume ------------------
        $meses = $this->mesesEntre($f['inicio'], $f['fim']);
        $pacMes = [];
        foreach ($examesP as $e) {
            $pm = &$pacMes[$e['mes']][$e['pid']];
            $pm ??= [];
            $pm += $e['cids'];
            unset($pm);
        }
        $tendencia = ['meses' => array_map(fn ($m) => $this->rotuloMes($m), $meses), 'series' => []];
        foreach (array_slice($topComorb, 0, 5) as $cid) {
            $vals = [];
            foreach ($meses as $m) {
                $lista = $pacMes[$m] ?? [];
                $n = count(array_filter($lista, fn ($cids) => isset($cids[$cid])));
                $vals[] = count($lista) >= 20 ? $this->pct($n, count($lista)) : null;
            }
            $tendencia['series'][] = ['nome' => $this->titulo($catalogo[$cid]['nome']), 'valores' => $vals];
        }

        // ---- 10. Novos diagnósticos no período ------------------------------------------
        $novosP = array_values(array_filter($novos, fn ($n) => isset($examesP[$n['ag']])));
        $novosMes = array_fill_keys($meses, 0);
        $novosItem = [];
        foreach ($novosP as $n) {
            $m = $examesP[$n['ag']]['mes'];
            if (isset($novosMes[$m])) {
                $novosMes[$m]++;
            }
            $novosItem[$n['cid']] = ($novosItem[$n['cid']] ?? 0) + 1;
        }
        arsort($novosItem);
        $pacientesRetorno = count(array_filter($porPaciente, fn ($ags) => count($ags) > 1));

        // ---- 11. Cobertura do preenchimento (agendamentos atendidos x com anamnese) ------
        $cobertura = $this->cobertura($f, $ini, $fim, $examesP, $meses);

        return [
            'kpis' => [
                'pacientes' => $nPac,
                'exames' => $nEx,
                'cobertura' => $cobertura['total'],
                'mediaComorbidades' => $nPac ? round($somaComorb / $nPac, 2) : 0,
                'multimorbidadePct' => $this->pct($multi['2'] + $multi['3'] + $multi['4'] + $multi['5+'], $nPac),
                'novosDiagnosticos' => count($novosP),
                'itemMaisPrevalente' => $prevalencia[0] ?? null,
                'pacientesRetorno' => $pacientesRetorno,
            ],
            'prevalencia' => $prevalencia,
            'multimorbidade' => ['rotulos' => array_keys($multi), 'valores' => array_values($multi), 'pct' => array_map(fn ($v) => $this->pct($v, $nPac), array_values($multi))],
            'combinacoes' => $topComb,
            'coocorrencia' => $coocorrencia,
            'porSexo' => $porSexo,
            'porFaixa' => $porFaixa,
            'porTipo' => $porTipo,
            'porProcedimento' => $porProcedimento,
            'piramide' => $piramide,
            'vacina' => $vacina,
            'tendencia' => $tendencia,
            'novos' => [
                'meses' => array_map(fn ($m) => $this->rotuloMes($m), array_keys($novosMes)),
                'valores' => array_values($novosMes),
                'itens' => array_map(fn ($cid, $n) => ['nome' => $this->titulo($catalogo[$cid]['nome']), 'n' => $n], array_keys(array_slice($novosItem, 0, 10, true)), array_slice($novosItem, 0, 10, true)),
            ],
            'cobertura' => $cobertura,
            'categorias' => ClassificacaoEstudo::CATEGORIAS,
        ];
    }

    /** Opções para os filtros. */
    public function opcoesFiltro(): array
    {
        $conn = $this->em->getConnection();
        $procs = $conn->fetchFirstColumn(
            'SELECT DISTINCT UPPER(a.procedimento_nome) FROM exame_classificacao ec JOIN agendamento a ON a.id = ec.agendamento_id WHERE a.procedimento_nome IS NOT NULL ORDER BY 1'
        );
        $datas = $conn->fetchAssociative('SELECT MIN(data_exame) AS ini, MAX(data_exame) AS fim FROM exame_classificacao WHERE removido_em IS NULL');

        return [
            'procedimentos' => $procs,
            'faixas' => self::FAIXAS,
            'tipos' => self::TIPOS_ATENDIMENTO,
            'primeiraData' => $datas['ini'] ? substr($datas['ini'], 0, 10) : null,
            'ultimaData' => $datas['fim'] ? substr($datas['fim'], 0, 10) : null,
        ];
    }

    /**
     * Linhas para pesquisa: uma por exame, com o paciente pseudonimizado e um 0/1 por item do catálogo.
     * Inclui o código do agendamento para cruzar com outros dados (ex.: ECG).
     *
     * @return iterable<array>
     */
    public function linhasExportacao(array $f, string $segredo): iterable
    {
        $catalogo = $this->catalogo(true);
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT ec.cod_agendamento AS ag, ec.cod_paciente AS codpac, ec.data_exame AS dt, ec.classificacao_id AS cid,
                    p.sexo, p.data_nascimento AS nasc, a.procedimento_nome AS proc, a.tipo_atendimento AS tipo
               FROM exame_classificacao ec
               JOIN paciente p ON p.id = ec.paciente_id
               LEFT JOIN agendamento a ON a.id = ec.agendamento_id
              WHERE ec.removido_em IS NULL AND ec.data_exame BETWEEN ? AND ?
              ORDER BY ec.data_exame, ec.cod_agendamento',
            [$f['inicio']->format('Y-m-d 00:00:00'), $f['fim']->format('Y-m-d 23:59:59')]
        );

        yield array_merge(['cod_agendamento', 'paciente_pseudonimo', 'data_exame', 'sexo', 'idade_no_exame', 'faixa_etaria', 'procedimento', 'tipo_atendimento', 'qtd_itens'], array_map(fn ($c) => $c['nome'], $catalogo));

        $atual = null;
        $flush = function (?array $e) use ($catalogo, $f) {
            if (!$e || !$this->passaRecorte($e, $f)) {
                return null;
            }
            $linha = [$e['ag'], $e['pseudo'], $e['dt'], $e['sexo'], $e['idade'] ?? '', $e['faixa'] ?? '', $e['proc'], $e['tipo'], count($e['cids'])];
            foreach ($catalogo as $cid => $_) {
                $linha[] = isset($e['cids'][$cid]) ? 1 : 0;
            }

            return $linha;
        };

        foreach ($rows as $r) {
            if (!$atual || $atual['ag'] !== $r['ag']) {
                if ($l = $flush($atual)) {
                    yield $l;
                }
                $dt = substr((string) $r['dt'], 0, 19);
                $idade = $r['nasc'] ? (new \DateTime(substr($r['nasc'], 0, 10)))->diff(new \DateTime($dt))->y : null;
                $atual = [
                    'ag' => $r['ag'], 'pseudo' => substr(hash_hmac('sha256', (string) $r['codpac'], $segredo), 0, 16), 'dt' => $dt,
                    'sexo' => $this->normalizarSexo($r['sexo']), 'idade' => $idade, 'faixa' => $this->faixa($idade),
                    'proc' => $r['proc'] ? mb_strtoupper(trim($r['proc'])) : '', 'tipo' => $r['tipo'] ?: '', 'cids' => [],
                ];
            }
            $atual['cids'][(int) $r['cid']] = true;
        }
        if ($l = $flush($atual)) {
            yield $l;
        }
    }

    // ---------------------------------------------------------------------------------

    private function cobertura(array $f, string $ini, string $fim, array $examesP, array $meses): array
    {
        $conn = $this->em->getConnection();
        $atendidos = $conn->fetchAllAssociative(
            "SELECT a.codigo_agendamento AS ag, a.data_hora_agendada AS dt, a.procedimento_nome AS proc, a.tipo_atendimento AS tipo,
                    p.sexo, p.data_nascimento AS nasc, m.nome AS medico
               FROM agendamento a
               JOIN paciente p ON p.id = a.paciente_id
               LEFT JOIN medico m ON m.id = a.medico_id
              WHERE a.data_hora_agendada BETWEEN ? AND ?
                AND a.status <> 'cancelado'
                AND (a.horario_chegada IS NOT NULL OR a.status = 'finalizado')",
            [$ini, $fim]
        );

        $porMes = [];
        $porProc = [];
        $porMedico = [];
        $total = 0;
        $com = 0;
        foreach ($atendidos as $a) {
            $dt = substr((string) $a['dt'], 0, 19);
            $idade = $a['nasc'] ? (new \DateTime(substr($a['nasc'], 0, 10)))->diff(new \DateTime($dt))->y : null;
            $e = ['sexo' => $this->normalizarSexo($a['sexo']), 'faixa' => $this->faixa($idade), 'tipo' => $a['tipo'] ?: 'nd', 'proc' => $a['proc'] ? mb_strtoupper(trim($a['proc'])) : 'SEM PROCEDIMENTO'];
            if (!$this->passaRecorte($e, $f)) {
                continue;
            }
            $tem = isset($examesP[$a['ag']]) ? 1 : 0;
            $mes = substr($dt, 0, 7);
            $total++;
            $com += $tem;
            $porMes[$mes]['total'] = ($porMes[$mes]['total'] ?? 0) + 1;
            $porMes[$mes]['com'] = ($porMes[$mes]['com'] ?? 0) + $tem;
            $porProc[$e['proc']]['total'] = ($porProc[$e['proc']]['total'] ?? 0) + 1;
            $porProc[$e['proc']]['com'] = ($porProc[$e['proc']]['com'] ?? 0) + $tem;
            $med = $a['medico'] ?: 'Sem médico';
            $porMedico[$med]['total'] = ($porMedico[$med]['total'] ?? 0) + 1;
            $porMedico[$med]['com'] = ($porMedico[$med]['com'] ?? 0) + $tem;
        }

        $mensal = ['meses' => [], 'com' => [], 'sem' => []];
        foreach ($meses as $m) {
            $mensal['meses'][] = $this->rotuloMes($m);
            $c = $porMes[$m]['com'] ?? 0;
            $t = $porMes[$m]['total'] ?? 0;
            $mensal['com'][] = $c;
            $mensal['sem'][] = max(0, $t - $c);
        }

        $ranking = function (array $grupos, int $min): array {
            $out = [];
            foreach ($grupos as $nome => $g) {
                if ($g['total'] >= $min) {
                    $out[] = ['nome' => $this->titulo((string) $nome), 'total' => $g['total'], 'com' => $g['com'], 'pct' => $this->pct($g['com'], $g['total'])];
                }
            }
            usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);

            return array_slice($out, 0, 12);
        };

        return [
            'total' => $total ? $this->pct($com, $total) : null,
            'atendidos' => $total,
            'comAnamnese' => $com,
            'mensal' => $mensal,
            'porProcedimento' => $ranking($porProc, 5),
            'porMedico' => $ranking($porMedico, 10),
        ];
    }

    private function vacinas(array $pacientes, array $catalogo): array
    {
        $doses = [];
        foreach ($catalogo as $cid => $c) {
            if ($c['categoria'] === 'vacina' && $c['dose']) {
                $doses[$cid] = $c['dose'];
            }
        }
        $maxDose = $doses ? max($doses) : 0;
        $rotulos = array_merge(['Não declarada'], array_map(fn ($d) => $d . 'ª dose', $maxDose ? range(1, $maxDose) : []));
        $grid = [];
        $geral = array_fill(0, count($rotulos), 0);
        foreach (self::FAIXAS as $fx) {
            $grid[$fx] = array_fill(0, count($rotulos), 0);
        }
        foreach ($pacientes as $p) {
            $d = 0;
            foreach ($p['cids'] as $cid => $_) {
                $d = max($d, $doses[$cid] ?? 0);
            }
            $geral[$d]++;
            if (isset($grid[$p['faixa']])) {
                $grid[$p['faixa']][$d]++;
            }
        }
        $faixas = [];
        $series = array_fill(0, count($rotulos), []);
        foreach ($grid as $fx => $vals) {
            $tot = array_sum($vals);
            if ($tot < 10) {
                continue;
            }
            $faixas[] = $fx;
            foreach ($vals as $i => $v) {
                $series[$i][] = $this->pct($v, $tot);
            }
        }
        $total = array_sum($geral);
        $tresMais = 0;
        foreach ($geral as $d => $v) {
            if ($d >= 3) {
                $tresMais += $v;
            }
        }

        return [
            'temDados' => (bool) $doses,
            'rotulos' => $rotulos,
            'faixas' => $faixas,
            'series' => $series,
            'geral' => $geral,
            'pctTresMais' => $this->pct($tresMais, $total),
            'pctDeclarada' => $this->pct($total - $geral[0], $total),
        ];
    }

    private function prevalenciaPorGrupo(array $unidades, string $campo, array $grupos, array $itens, array $catalogo): array
    {
        $tot = array_fill_keys($grupos, 0);
        $cont = [];
        foreach ($unidades as $u) {
            $g = $u[$campo] ?? null;
            if (!array_key_exists($g, $tot)) {
                continue;
            }
            $tot[$g]++;
            foreach ($itens as $cid) {
                if (isset($u['cids'][$cid])) {
                    $cont[$cid][$g] = ($cont[$cid][$g] ?? 0) + 1;
                }
            }
        }

        $linhas = [];
        foreach ($itens as $cid) {
            $vals = [];
            foreach ($grupos as $g) {
                $vals[] = $tot[$g] ? $this->pct($cont[$cid][$g] ?? 0, $tot[$g]) : null;
            }
            $linhas[] = ['nome' => $this->titulo($catalogo[$cid]['nome']), 'valores' => $vals];
        }

        return ['grupos' => $grupos, 'totais' => array_values($tot), 'itens' => $linhas];
    }

    private function catalogo(bool $somenteAtivos = false): array
    {
        $out = [];
        $sql = 'SELECT id, nome, categoria FROM classificacao_estudo' . ($somenteAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY cod_classificacao';
        foreach ($this->em->getConnection()->fetchAllAssociative($sql) as $r) {
            $c = (new ClassificacaoEstudo())->setNome($r['nome']);
            $out[(int) $r['id']] = ['nome' => $r['nome'], 'categoria' => $r['categoria'], 'dose' => $r['categoria'] === 'vacina' ? $c->getNumeroDose() : null];
        }

        return $out;
    }

    private function passaRecorte(array $e, array $f): bool
    {
        if (!empty($f['sexo']) && $e['sexo'] !== $f['sexo']) {
            return false;
        }
        if (!empty($f['faixa']) && $e['faixa'] !== $f['faixa']) {
            return false;
        }
        if (!empty($f['tipo']) && $e['tipo'] !== $f['tipo']) {
            return false;
        }
        if (!empty($f['procedimento']) && $e['proc'] !== mb_strtoupper($f['procedimento'])) {
            return false;
        }

        return true;
    }

    private function ehVacina(array $catalogo, int $cid): bool
    {
        return ($catalogo[$cid]['categoria'] ?? '') === 'vacina';
    }

    private function normalizarSexo(?string $s): string
    {
        $s = mb_strtoupper(trim((string) $s));

        return match (true) {
            str_starts_with($s, 'F') => 'Feminino',
            str_starts_with($s, 'M') => 'Masculino',
            default => 'Não informado',
        };
    }

    private function faixa(?int $idade): string
    {
        if ($idade === null) {
            return 'Sem idade';
        }

        return match (true) {
            $idade < 18 => '0–17',
            $idade < 30 => '18–29',
            $idade < 40 => '30–39',
            $idade < 50 => '40–49',
            $idade < 60 => '50–59',
            $idade < 70 => '60–69',
            $idade < 80 => '70–79',
            default => '80+',
        };
    }

    private function mesesEntre(\DateTimeInterface $ini, \DateTimeInterface $fim): array
    {
        $meses = [];
        $c = new \DateTime($ini->format('Y-m-01'));
        $limite = $fim->format('Y-m');
        while ($c->format('Y-m') <= $limite && count($meses) < 240) {
            $meses[] = $c->format('Y-m');
            $c->modify('+1 month');
        }

        return $meses;
    }

    private function rotuloMes(string $ym): string
    {
        $n = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

        return $n[(int) substr($ym, 5, 2) - 1] . '/' . substr($ym, 2, 2);
    }

    private function titulo(string $s): string
    {
        $s = mb_convert_case(mb_strtolower(trim($s)), MB_CASE_TITLE, 'UTF-8');

        $s = preg_replace_callback('/\b(De|Da|Do|Das|Dos|E|Com|Em|Por)\b/u', fn ($m) => mb_strtolower($m[0]), $s);

        // Siglas médicas comuns ficam em maiúsculas
        return preg_replace_callback('/\b(Avc|Iam|Dpoc|Has|Dm|Hiv|Irc|Drc|Ic|Tev|Tvp|Dac|Ecg|Mapa|Sus)\b/u', fn ($m) => mb_strtoupper($m[0]), $s);
    }

    private function pct(int|float $n, int|float $d): float
    {
        return $d > 0 ? round($n * 100 / $d, 1) : 0.0;
    }
}
