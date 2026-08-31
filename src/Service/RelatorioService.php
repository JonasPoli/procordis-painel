<?php

namespace App\Service;

use App\Entity\Agendamento;
use App\Entity\AtendimentoEtapaHistorico;
use App\Repository\AgendamentoRepository;
use App\Repository\AtendimentoEtapaHistoricoRepository;
use App\Repository\EspecialidadeRepository;
use App\Repository\MedicoRepository;
use Doctrine\ORM\EntityManagerInterface;

class RelatorioService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AgendamentoRepository $agendamentoRepo,
        private AtendimentoEtapaHistoricoRepository $etapaRepo,
        private MedicoRepository $medicoRepo,
        private EspecialidadeRepository $especialidadeRepo
    ) {
    }

    /**
     * Retorna os dados consolidados do relatório de procedimentos por período.
     *
     * @param string $tipoPeriodo 'mes_especifico' ou 'ultimos_6_meses'
     * @param int|null $ano Ex: 2026
     * @param int|null $mes Ex: 8
     */
    public function obterRelatorioProcedimentos(string $tipoPeriodo = 'ultimos_6_meses', ?int $ano = null, ?int $mes = null, ?string $dataInicioCustom = null, ?string $dataFimCustom = null): array
    {
        $hoje = new \DateTime();
        $ano = $ano ?? (int) $hoje->format('Y');
        $mes = $mes ?? (int) $hoje->format('m');

        $qb = $this->agendamentoRepo->createQueryBuilder('a')
            ->leftJoin('a.paciente', 'p')->addSelect('p')
            ->leftJoin('a.medico', 'm')->addSelect('m')
            ->leftJoin('a.especialidade', 'e')->addSelect('e')
            ->orderBy('a.dataHoraAgendada', 'ASC');

        if ($tipoPeriodo === 'sem_filtro') {
            $rotuloPeriodo = 'Todo o Histórico (Sem filtro de data)';
            $dataInicio = null;
            $dataFim = null;
        } elseif ($tipoPeriodo === 'ano_especifico') {
            $dataInicio = new \DateTime("{$ano}-01-01 00:00:00");
            $dataFim = new \DateTime("{$ano}-12-31 23:59:59");
            $rotuloPeriodo = "Ano {$ano}";
            $qb->where('a.dataHoraAgendada BETWEEN :inicio AND :fim')
               ->setParameter('inicio', $dataInicio)
               ->setParameter('fim', $dataFim);
        } elseif ($tipoPeriodo === 'personalizado' && !empty($dataInicioCustom) && !empty($dataFimCustom)) {
            $dataInicio = \DateTime::createFromFormat('Y-m-d', $dataInicioCustom);
            $dataFim = \DateTime::createFromFormat('Y-m-d', $dataFimCustom);
            if ($dataInicio && $dataFim) {
                $dataInicio->setTime(0, 0, 0);
                $dataFim->setTime(23, 59, 59);
                $rotuloPeriodo = 'Período Personalizado (' . $dataInicio->format('d/m/Y') . ' a ' . $dataFim->format('d/m/Y') . ')';
                $qb->where('a.dataHoraAgendada BETWEEN :inicio AND :fim')
                   ->setParameter('inicio', $dataInicio)
                   ->setParameter('fim', $dataFim);
            }
        } elseif ($tipoPeriodo === 'mes_especifico') {
            $dataInicio = new \DateTime("{$ano}-{$mes}-01 00:00:00");
            $dataFim = (clone $dataInicio)->modify('last day of this month')->setTime(23, 59, 59);
            $rotuloPeriodo = $dataInicio->format('m/Y');
            $qb->where('a.dataHoraAgendada BETWEEN :inicio AND :fim')
               ->setParameter('inicio', $dataInicio)
               ->setParameter('fim', $dataFim);
        } else {
            // últimos 6 meses
            $dataFim = (clone $hoje)->setTime(23, 59, 59);
            $dataInicio = (clone $hoje)->modify('-5 months')->modify('first day of this month')->setTime(0, 0, 0);
            $rotuloPeriodo = 'Últimos 6 meses (' . $dataInicio->format('m/Y') . ' a ' . $dataFim->format('m/Y') . ')';
            $qb->where('a.dataHoraAgendada BETWEEN :inicio AND :fim')
               ->setParameter('inicio', $dataInicio)
               ->setParameter('fim', $dataFim);
        }

        // 1. Contar total de procedimentos no período
        $countQb = clone $qb;
        $totalProcedimentos = (int) $countQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        // 2. Trazer apenas os campos necessários em formato Array escalar
        $items = $qb->select('
            a.id,
            a.dataHoraAgendada,
            a.createdAt,
            a.horarioChegada,
            a.horarioInicioConsulta,
            a.horarioSaida,
            a.horarioFimConsulta,
            a.tipoAtendimento,
            a.convenioNome,
            a.procedimentoNome,
            a.status,
            a.encaixe,
            p.nomeCompleto as pacienteNome,
            p.sexo as pacienteSexo,
            p.dataNascimento as pacienteNascimento,
            m.nome as medicoNome,
            e.nome as especialidadeNome
        ')
        ->getQuery()
        ->getArrayResult();

        // Contadores de Financiamento / Convênio
        $countSus = 0;
        $countFilantropico = 0;
        $countConvenio = 0;
        $countParticular = 0;
        $countEncaixes = 0;
        $totalEsperaMinutos = 0;
        $countComEspera = 0;

        // Agrupamentos temporais e por entidade
        $porDia = [];
        $porSemana = [];
        $porMes = [];
        $porTrimestre = [];
        $porQuadrimestre = [];
        $porSemestre = [];
        $porAno = [];
        $porProcedimento = [];
        $porEspecialidade = [];
        $porMedico = [];
        $detalhesMedicos = [];
        $porConvenio = [];
        $porStatus = [];
        $porProcedimentoMes = []; // Matriz [procedimentoNome][mês] = qtd
        $porProcedimentoDia = []; // Matriz [dia][procedimentoNome] = qtd

        // Demografia
        $porGenero = ['M' => 0, 'F' => 0, 'NÃO INFORMADO' => 0];
        $porFaixaEtaria = [
            '0 a 18 anos' => 0,
            '19 a 39 anos' => 0,
            '40 a 59 anos' => 0,
            '60 a 79 anos' => 0,
            '80+ anos' => 0,
            'Não informada' => 0,
        ];

        // Dias da Semana e Faixas de Horário
        $diasSemanaNomes = [
            1 => 'Segunda-feira',
            2 => 'Terça-feira',
            3 => 'Quarta-feira',
            4 => 'Quinta-feira',
            5 => 'Sexta-feira',
            6 => 'Sábado',
            7 => 'Domingo'
        ];
        $porDiaSemana = [
            'Segunda-feira' => 0,
            'Terça-feira' => 0,
            'Quarta-feira' => 0,
            'Quinta-feira' => 0,
            'Sexta-feira' => 0,
            'Sábado' => 0,
            'Domingo' => 0,
        ];
        $porFaixaHoraria = [];
        for ($h = 6; $h <= 20; $h++) {
            $porFaixaHoraria[sprintf('%02dh', $h)] = 0;
        }

        // Matrizes de financiamento e status por intervalo
        $porFinanciamentoIntervalo = [
            'sus' => ['dia' => [], 'mes' => [], 'trimestre' => [], 'quadrimestre' => [], 'semestre' => [], 'ano' => []],
            'filantropico' => ['dia' => [], 'mes' => [], 'trimestre' => [], 'quadrimestre' => [], 'semestre' => [], 'ano' => []],
            'convenio' => ['dia' => [], 'mes' => [], 'trimestre' => [], 'quadrimestre' => [], 'semestre' => [], 'ano' => []],
        ];

        $porStatusIntervalo = [
            'finalizado' => ['dia' => [], 'mes' => [], 'trimestre' => [], 'quadrimestre' => [], 'semestre' => [], 'ano' => []],
            'cancelado' => ['dia' => [], 'mes' => [], 'trimestre' => [], 'quadrimestre' => [], 'semestre' => [], 'ano' => []],
            'agendado' => ['dia' => [], 'mes' => [], 'trimestre' => [], 'quadrimestre' => [], 'semestre' => [], 'ano' => []],
        ];

        foreach ($items as $item) {
            $tipoAt = strtolower($item['tipoAtendimento'] ?? 'sus');
            $finKey = 'convenio';
            if (str_contains($tipoAt, 'sus')) {
                $countSus++;
                $finKey = 'sus';
            } elseif (str_contains($tipoAt, 'filantrop')) {
                $countFilantropico++;
                $finKey = 'filantropico';
            } elseif (str_contains($tipoAt, 'particular')) {
                $countParticular++;
                $finKey = 'convenio';
            } else {
                $countConvenio++;
                $finKey = 'convenio';
            }

            if (!empty($item['encaixe'])) {
                $countEncaixes++;
            }

            $convNome = !empty($item['convenioNome']) ? trim($item['convenioNome']) : (str_contains($tipoAt, 'sus') ? 'SUS - Sistema Único de Saúde' : 'Particular / Outros');
            $porConvenio[$convNome] = ($porConvenio[$convNome] ?? 0) + 1;

            $dt = $item['dataHoraAgendada'] ?? $item['createdAt'];
            if (!$dt instanceof \DateTimeInterface) {
                $dt = new \DateTime($dt ?? 'now');
            }

            $chaveDia = $dt->format('Y-m-d');
            $chaveSemana = 'Semana ' . $dt->format('W/Y');
            $chaveMes = $dt->format('m/Y');
            
            $numMes = (int) $dt->format('m');
            $trimestreNum = (int) ceil($numMes / 3);
            $quadrimestreNum = (int) ceil($numMes / 4);
            $semestreNum = $numMes <= 6 ? 1 : 2;

            $chaveTrimestre = $trimestreNum . 'º Trimestre/' . $dt->format('Y');
            $chaveQuadrimestre = $quadrimestreNum . 'º Quadrimestre/' . $dt->format('Y');
            $chaveSemestre = $semestreNum . 'º Semestre/' . $dt->format('Y');
            $chaveAno = $dt->format('Y');

            $porDia[$chaveDia] = ($porDia[$chaveDia] ?? 0) + 1;
            $porSemana[$chaveSemana] = ($porSemana[$chaveSemana] ?? 0) + 1;
            $porMes[$chaveMes] = ($porMes[$chaveMes] ?? 0) + 1;
            $porTrimestre[$chaveTrimestre] = ($porTrimestre[$chaveTrimestre] ?? 0) + 1;
            $porQuadrimestre[$chaveQuadrimestre] = ($porQuadrimestre[$chaveQuadrimestre] ?? 0) + 1;
            $porSemestre[$chaveSemestre] = ($porSemestre[$chaveSemestre] ?? 0) + 1;
            $porAno[$chaveAno] = ($porAno[$chaveAno] ?? 0) + 1;

            // Dia da semana e Hora
            $numDiaSemana = (int) $dt->format('N');
            $nomeDiaSemana = $diasSemanaNomes[$numDiaSemana] ?? 'Outro';
            $porDiaSemana[$nomeDiaSemana] = ($porDiaSemana[$nomeDiaSemana] ?? 0) + 1;

            $horaFormatada = $dt->format('H') . 'h';
            if (isset($porFaixaHoraria[$horaFormatada])) {
                $porFaixaHoraria[$horaFormatada]++;
            } else {
                $porFaixaHoraria[$horaFormatada] = ($porFaixaHoraria[$horaFormatada] ?? 0) + 1;
            }

            // Demografia - Gênero
            $sexoRaw = strtoupper(trim($item['pacienteSexo'] ?? ''));
            if ($sexoRaw === 'M' || $sexoRaw === 'MASCULINO') {
                $porGenero['M']++;
            } elseif ($sexoRaw === 'F' || $sexoRaw === 'FEMININO') {
                $porGenero['F']++;
            } else {
                $porGenero['NÃO INFORMADO']++;
            }

            // Demografia - Faixa Etária
            $nasc = $item['pacienteNascimento'] ?? null;
            if ($nasc instanceof \DateTimeInterface) {
                $idade = $dt->diff($nasc)->y;
                if ($idade <= 18) {
                    $porFaixaEtaria['0 a 18 anos']++;
                } elseif ($idade <= 39) {
                    $porFaixaEtaria['19 a 39 anos']++;
                } elseif ($idade <= 59) {
                    $porFaixaEtaria['40 a 59 anos']++;
                } elseif ($idade <= 79) {
                    $porFaixaEtaria['60 a 79 anos']++;
                } else {
                    $porFaixaEtaria['80+ anos']++;
                }
            } else {
                $porFaixaEtaria['Não informada']++;
            }

            // Tempo de Espera
            $chegada = $item['horarioChegada'] ?? null;
            $inicioConsulta = $item['horarioInicioConsulta'] ?? $item['horarioSaida'] ?? $item['horarioFimConsulta'] ?? null;
            if ($chegada instanceof \DateTimeInterface && $inicioConsulta instanceof \DateTimeInterface) {
                $diffSec = $inicioConsulta->getTimestamp() - $chegada->getTimestamp();
                if ($diffSec > 0 && $diffSec < 43200) { // menos de 12 horas
                    $totalEsperaMinutos += ($diffSec / 60);
                    $countComEspera++;
                }
            }

            // Financiamento por intervalo
            $porFinanciamentoIntervalo[$finKey]['dia'][$chaveDia] = ($porFinanciamentoIntervalo[$finKey]['dia'][$chaveDia] ?? 0) + 1;
            $porFinanciamentoIntervalo[$finKey]['mes'][$chaveMes] = ($porFinanciamentoIntervalo[$finKey]['mes'][$chaveMes] ?? 0) + 1;
            $porFinanciamentoIntervalo[$finKey]['trimestre'][$chaveTrimestre] = ($porFinanciamentoIntervalo[$finKey]['trimestre'][$chaveTrimestre] ?? 0) + 1;
            $porFinanciamentoIntervalo[$finKey]['quadrimestre'][$chaveQuadrimestre] = ($porFinanciamentoIntervalo[$finKey]['quadrimestre'][$chaveQuadrimestre] ?? 0) + 1;
            $porFinanciamentoIntervalo[$finKey]['semestre'][$chaveSemestre] = ($porFinanciamentoIntervalo[$finKey]['semestre'][$chaveSemestre] ?? 0) + 1;
            $porFinanciamentoIntervalo[$finKey]['ano'][$chaveAno] = ($porFinanciamentoIntervalo[$finKey]['ano'][$chaveAno] ?? 0) + 1;

            $nomeProc = $item['procedimentoNome'] ?? 'Consulta / Procedimento Geral';
            $porProcedimento[$nomeProc] = ($porProcedimento[$nomeProc] ?? 0) + 1;

            if (!isset($porProcedimentoIntervalo[$nomeProc])) {
                $porProcedimentoIntervalo[$nomeProc] = [
                    'dia' => [],
                    'mes' => [],
                    'trimestre' => [],
                    'quadrimestre' => [],
                    'semestre' => [],
                    'ano' => [],
                ];
            }
            $porProcedimentoIntervalo[$nomeProc]['dia'][$chaveDia] = ($porProcedimentoIntervalo[$nomeProc]['dia'][$chaveDia] ?? 0) + 1;
            $porProcedimentoIntervalo[$nomeProc]['mes'][$chaveMes] = ($porProcedimentoIntervalo[$nomeProc]['mes'][$chaveMes] ?? 0) + 1;
            $porProcedimentoIntervalo[$nomeProc]['trimestre'][$chaveTrimestre] = ($porProcedimentoIntervalo[$nomeProc]['trimestre'][$chaveTrimestre] ?? 0) + 1;
            $porProcedimentoIntervalo[$nomeProc]['quadrimestre'][$chaveQuadrimestre] = ($porProcedimentoIntervalo[$nomeProc]['quadrimestre'][$chaveQuadrimestre] ?? 0) + 1;
            $porProcedimentoIntervalo[$nomeProc]['semestre'][$chaveSemestre] = ($porProcedimentoIntervalo[$nomeProc]['semestre'][$chaveSemestre] ?? 0) + 1;
            $porProcedimentoIntervalo[$nomeProc]['ano'][$chaveAno] = ($porProcedimentoIntervalo[$nomeProc]['ano'][$chaveAno] ?? 0) + 1;

            $espNome = $item['especialidadeNome'] ?? 'Sem Especialidade';
            $porEspecialidade[$espNome] = ($porEspecialidade[$espNome] ?? 0) + 1;

            $medicoNome = $item['medicoNome'] ?? 'Não Atribuído';
            $porMedico[$medicoNome] = ($porMedico[$medicoNome] ?? 0) + 1;

            $st = strtoupper($item['status'] ?? 'AGENDADO');
            $porStatus[$st] = ($porStatus[$st] ?? 0) + 1;

            $stKey = 'agendado';
            $isFinalizado = false;
            $isCancelado = false;
            if (str_contains($st, 'CANCEL') || str_contains($st, 'FALTA') || str_contains($st, 'DESIST')) {
                $stKey = 'cancelado';
                $isCancelado = true;
            } elseif (str_contains($st, 'FINALIZ') || str_contains($st, 'CONCLU')) {
                $stKey = 'finalizado';
                $isFinalizado = true;
            }

            // Detalhes por Médico
            if (!isset($detalhesMedicos[$medicoNome])) {
                $detalhesMedicos[$medicoNome] = [
                    'nome' => $medicoNome,
                    'especialidade' => $espNome,
                    'total' => 0,
                    'finalizados' => 0,
                    'cancelados' => 0,
                    'encaixes' => 0,
                ];
            }
            $detalhesMedicos[$medicoNome]['total']++;
            if ($isFinalizado) $detalhesMedicos[$medicoNome]['finalizados']++;
            if ($isCancelado) $detalhesMedicos[$medicoNome]['cancelados']++;
            if (!empty($item['encaixe'])) $detalhesMedicos[$medicoNome]['encaixes']++;

            $porStatusIntervalo[$stKey]['dia'][$chaveDia] = ($porStatusIntervalo[$stKey]['dia'][$chaveDia] ?? 0) + 1;
            $porStatusIntervalo[$stKey]['mes'][$chaveMes] = ($porStatusIntervalo[$stKey]['mes'][$chaveMes] ?? 0) + 1;
            $porStatusIntervalo[$stKey]['trimestre'][$chaveTrimestre] = ($porStatusIntervalo[$stKey]['trimestre'][$chaveTrimestre] ?? 0) + 1;
            $porStatusIntervalo[$stKey]['quadrimestre'][$chaveQuadrimestre] = ($porStatusIntervalo[$stKey]['quadrimestre'][$chaveQuadrimestre] ?? 0) + 1;
            $porStatusIntervalo[$stKey]['semestre'][$chaveSemestre] = ($porStatusIntervalo[$stKey]['semestre'][$chaveSemestre] ?? 0) + 1;
            $porStatusIntervalo[$stKey]['ano'][$chaveAno] = ($porStatusIntervalo[$stKey]['ano'][$chaveAno] ?? 0) + 1;

            if (!isset($porProcedimentoDia[$chaveDia])) {
                $porProcedimentoDia[$chaveDia] = [];
            }
            $porProcedimentoDia[$chaveDia][$nomeProc] = ($porProcedimentoDia[$chaveDia][$nomeProc] ?? 0) + 1;
        }

        // Ordenar e enriquecer médicos
        uasort($detalhesMedicos, fn($a, $b) => $b['total'] <=> $a['total']);
        foreach ($detalhesMedicos as &$dm) {
            $dm['pctTotal'] = $totalProcedimentos > 0 ? round(($dm['total'] / $totalProcedimentos) * 100, 1) : 0;
            $dm['taxaCancelamento'] = $dm['total'] > 0 ? round(($dm['cancelados'] / $dm['total']) * 100, 1) : 0;
        }
        unset($dm);

        // Ordenar convênios
        arsort($porConvenio);

        // Percentuais
        $pctSus = $totalProcedimentos > 0 ? round(($countSus / $totalProcedimentos) * 100, 1) : 0;
        $pctFilantropico = $totalProcedimentos > 0 ? round(($countFilantropico / $totalProcedimentos) * 100, 1) : 0;
        $pctConvenio = $totalProcedimentos > 0 ? round((($countConvenio + $countParticular) / $totalProcedimentos) * 100, 1) : 0;
        $taxaEncaixes = $totalProcedimentos > 0 ? round(($countEncaixes / $totalProcedimentos) * 100, 1) : 0;
        
        $totalCancelados = ($porStatus['CANCELADO'] ?? 0) + ($porStatus['FALTOU'] ?? 0) + ($porStatus['DESMARCADO'] ?? 0);
        $taxaCancelamentoGeral = $totalProcedimentos > 0 ? round(($totalCancelados / $totalProcedimentos) * 100, 1) : 0;
        $tempoMedioEsperaMin = $countComEspera > 0 ? round($totalEsperaMinutos / $countComEspera, 1) : 0;

        // Ordenar dados
        ksort($porDia);
        ksort($porMes);
        ksort($porTrimestre);
        ksort($porQuadrimestre);
        ksort($porSemestre);
        ksort($porAno);
        arsort($porProcedimento);
        arsort($porEspecialidade);
        arsort($porMedico);

        // Média por dia com atendimento
        $diasComAtendimento = count($porDia);
        $mediaDia = $diasComAtendimento > 0 ? round($totalProcedimentos / $diasComAtendimento, 1) : 0;

        // Preparar chaves temporais
        $chavesTemporais = [
            'dia' => array_keys($porDia),
            'mes' => array_keys($porMes),
            'trimestre' => array_keys($porTrimestre),
            'quadrimestre' => array_keys($porQuadrimestre),
            'semestre' => array_keys($porSemestre),
            'ano' => array_keys($porAno),
        ];

        // Séries de Financiamento por Intervalo
        $seriesFinanciamento = [];
        foreach (['sus', 'filantropico', 'convenio'] as $fk) {
            foreach ($chavesTemporais as $mod => $keys) {
                $arrVals = [];
                foreach ($keys as $k) {
                    $arrVals[] = $porFinanciamentoIntervalo[$fk][$mod][$k] ?? 0;
                }
                $seriesFinanciamento[$fk][$mod] = $arrVals;
            }
        }

        // Séries de Status por Intervalo
        $seriesStatus = [];
        foreach (['finalizado', 'cancelado', 'agendado'] as $sk) {
            foreach ($chavesTemporais as $mod => $keys) {
                $arrVals = [];
                foreach ($keys as $k) {
                    $arrVals[] = $porStatusIntervalo[$sk][$mod][$k] ?? 0;
                }
                $seriesStatus[$sk][$mod] = $arrVals;
            }
        }

        // Insights estatísticos da evolução
        $picoValor = 0;
        $picoPeriodo = '-';
        $minValor = $totalProcedimentos > 0 ? PHP_INT_MAX : 0;
        $minPeriodo = '-';

        $mesesCounts = array_values($porMes);
        $totalMeses = count($mesesCounts);
        $mediaPeriodoMes = $totalMeses > 0 ? round($totalProcedimentos / $totalMeses, 1) : 0;

        foreach ($porMes as $mKey => $v) {
            if ($v > $picoValor) {
                $picoValor = $v;
                $picoPeriodo = $mKey;
            }
            if ($v < $minValor) {
                $minValor = $v;
                $minPeriodo = $mKey;
            }
        }
        if ($minValor === PHP_INT_MAX) {
            $minValor = 0;
        }

        $datasetsProcedimentos = [];
        $paletaCores = [
            '#0891b2', '#059669', '#6366f1', '#d97706', '#dc2626', 
            '#8b5cf6', '#ec4899', '#0284c7', '#14b8a6', '#f59e0b',
            '#64748b', '#84cc16', '#3b82f6', '#a855f7', '#f43f5e'
        ];
        $i = 0;

        foreach ($porProcedimento as $procName => $totalCount) {
            $cor = $paletaCores[$i % count($paletaCores)];
            
            $seriesPorModalidade = [];
            foreach ($chavesTemporais as $mod => $keys) {
                $arrVals = [];
                foreach ($keys as $k) {
                    $arrVals[] = $porProcedimentoIntervalo[$procName][$mod][$k] ?? 0;
                }
                $seriesPorModalidade[$mod] = $arrVals;
            }

            $datasetsProcedimentos[] = [
                'nome' => $procName,
                'total' => $totalCount,
                'cor' => $cor,
                'data' => $seriesPorModalidade['mes'], // retrocompatibilidade
                'series' => $seriesPorModalidade,
            ];
            $i++;
        }

        return [
            'tipoPeriodo' => $tipoPeriodo,
            'rotuloPeriodo' => $rotuloPeriodo,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'ano' => $ano,
            'mes' => $mes,
            'totalProcedimentos' => $totalProcedimentos,
            'financiamento' => [
                'sus' => $countSus,
                'pctSus' => $pctSus,
                'filantropico' => $countFilantropico,
                'pctFilantropico' => $pctFilantropico,
                'convenio' => $countConvenio + $countParticular,
                'pctConvenio' => $pctConvenio,
            ],
            'eficiencia' => [
                'countEncaixes' => $countEncaixes,
                'taxaEncaixes' => $taxaEncaixes,
                'totalCancelados' => $totalCancelados,
                'taxaCancelamentoGeral' => $taxaCancelamentoGeral,
                'tempoMedioEsperaMin' => $tempoMedioEsperaMin,
            ],
            'detalhesMedicos' => $detalhesMedicos,
            'porConvenio' => $porConvenio,
            'porGenero' => $porGenero,
            'porFaixaEtaria' => $porFaixaEtaria,
            'porDiaSemana' => $porDiaSemana,
            'porFaixaHoraria' => $porFaixaHoraria,
            'insights' => [
                'picoValor' => $picoValor,
                'picoPeriodo' => $picoPeriodo,
                'minValor' => $minValor,
                'minPeriodo' => $minPeriodo,
                'mediaPeriodoMes' => $mediaPeriodoMes,
            ],
            'seriesFinanciamento' => $seriesFinanciamento,
            'seriesStatus' => $seriesStatus,
            'mediaDia' => $mediaDia,
            'porDia' => $porDia,
            'porDiaKeys' => array_keys($porDia),
            'porDiaValues' => array_values($porDia),
            'porSemana' => $porSemana,
            'porMes' => $porMes,
            'porMesKeys' => array_keys($porMes),
            'porMesValues' => array_values($porMes),
            'porTrimestre' => $porTrimestre,
            'porTrimestreKeys' => array_keys($porTrimestre),
            'porTrimestreValues' => array_values($porTrimestre),
            'porQuadrimestre' => $porQuadrimestre,
            'porQuadrimestreKeys' => array_keys($porQuadrimestre),
            'porQuadrimestreValues' => array_values($porQuadrimestre),
            'porSemestre' => $porSemestre,
            'porSemestreKeys' => array_keys($porSemestre),
            'porSemestreValues' => array_values($porSemestre),
            'porAno' => $porAno,
            'porAnoKeys' => array_keys($porAno),
            'porAnoValues' => array_values($porAno),
            'porProcedimento' => $porProcedimento,
            'porProcedimentoDia' => $porProcedimentoDia,
            'porEspecialidade' => $porEspecialidade,
            'porMedico' => $porMedico,
            'porStatus' => $porStatus,
            'datasetsProcedimentos' => $datasetsProcedimentos,
            'agendamentos' => $items,
        ];
    }

    /**
     * Retorna os dados consolidados do relatório de anamneses e triagens por período.
     */
    /**
     * Retorna os dados consolidados do relatório de anamneses e triagens por período.
     */
    public function obterRelatorioAnamneses(string $tipoPeriodo = 'ultimos_6_meses', ?int $ano = null, ?int $mes = null): array
    {
        $hoje = new \DateTime();
        $ano = $ano ?? (int) $hoje->format('Y');
        $mes = $mes ?? (int) $hoje->format('m');

        if ($tipoPeriodo === 'mes_especifico') {
            $dataInicio = new \DateTime("{$ano}-{$mes}-01 00:00:00");
            $dataFim = (clone $dataInicio)->modify('last day of this month')->setTime(23, 59, 59);
            $rotuloPeriodo = $dataInicio->format('m/Y');
        } else {
            $dataFim = (clone $hoje)->setTime(23, 59, 59);
            $dataInicio = (clone $hoje)->modify('-5 months')->modify('first day of this month')->setTime(0, 0, 0);
            $rotuloPeriodo = 'Últimos 6 meses (' . $dataInicio->format('m/Y') . ' a ' . $dataFim->format('m/Y') . ')';
        }

        // Buscar todos os agendamentos do período selecionado
        $agendamentos = $this->agendamentoRepo->createQueryBuilder('a')
            ->leftJoin('a.paciente', 'p')->addSelect('p')
            ->leftJoin('a.medico', 'm')->addSelect('m')
            ->leftJoin('a.historicoEtapas', 'h')->addSelect('h')
            ->where('a.dataHoraAgendada BETWEEN :inicio AND :fim OR a.horarioChegada BETWEEN :inicio AND :fim')
            ->setParameter('inicio', $dataInicio)
            ->setParameter('fim', $dataFim)
            ->orderBy('a.dataHoraAgendada', 'DESC')
            ->getQuery()
            ->getResult();

        $triagens = [];
        $riscos = [
            'verde' => 0,
            'amarelo' => 0,
            'vermelho' => 0,
            'azul' => 0,
            'nao_classificado' => 0
        ];

        $somaDuracaoSegundos = 0;
        $totalDuracaoValida = 0;

        foreach ($agendamentos as $ag) {
            $etapas = $ag->getHistoricoEtapas();
            $etapaPrincipal = null;

            if (count($etapas) > 0) {
                $etapaPrincipal = $etapas[0];
            } else {
                // Criar instância de apresentação se o agendamento ainda não possuir etapa vinculada
                $etapaPrincipal = new AtendimentoEtapaHistorico();
                $etapaPrincipal->setAgendamento($ag);
                $etapaPrincipal->setEtapa('chegada');
                $etapaPrincipal->setDataHoraInicio($ag->getHorarioChegada() ?? $ag->getDataHoraAgendada() ?? new \DateTime());
                $etapaPrincipal->setDataHoraFim($ag->getHorarioSaida() ?? $ag->getHorarioFimConsulta());
                $etapaPrincipal->setResponsavel($ag->getMedico() ? $ag->getMedico()->getNome() : 'Medware API');
            }

            $triagens[] = $etapaPrincipal;

            $r = strtolower($etapaPrincipal->getClassificacaoRisco() ?? 'nao_classificado');
            if (isset($riscos[$r])) {
                $riscos[$r]++;
            } else {
                $riscos['nao_classificado']++;
            }

            if ($etapaPrincipal->getDuracaoSegundos() && $etapaPrincipal->getDuracaoSegundos() > 0) {
                $somaDuracaoSegundos += $etapaPrincipal->getDuracaoSegundos();
                $totalDuracaoValida++;
            }
        }

        $totalAnamneses = count($triagens);
        $tempoMedioTriagemMin = $totalDuracaoValida > 0 ? round(($somaDuracaoSegundos / $totalDuracaoValida) / 60, 1) : 5.0;

        return [
            'tipoPeriodo' => $tipoPeriodo,
            'rotuloPeriodo' => $rotuloPeriodo,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'ano' => $ano,
            'mes' => $mes,
            'totalAnamneses' => $totalAnamneses,
            'riscos' => $riscos,
            'tempoMedioTriagemMin' => $tempoMedioTriagemMin,
            'triagens' => $triagens,
        ];
    }

    /**
     * Retorna a lista de nomes de procedimentos únicos cadastrados nos agendamentos.
     */
    public function obterProcedimentosUnicos(): array
    {
        $res = $this->agendamentoRepo->createQueryBuilder('a')
            ->select('DISTINCT a.procedimentoNome')
            ->where('a.procedimentoNome IS NOT NULL')
            ->andWhere('a.procedimentoNome != \'\'')
            ->orderBy('a.procedimentoNome', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($res));
    }

    /**
     * Retorna a lista de médicos cadastrados e suas especialidades.
     */
    public function obterMedicosComEspecialidade(): array
    {
        return $this->medicoRepo->createQueryBuilder('m')
            ->leftJoin('m.especialidade', 'e')
            ->select('m.nome as medicoNome, e.nome as especialidadeNome')
            ->orderBy('m.nome', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Retorna a lista de convênios/planos únicos.
     */
    public function obterConveniosUnicos(): array
    {
        $res = $this->agendamentoRepo->createQueryBuilder('a')
            ->select('DISTINCT a.convenioNome')
            ->where('a.convenioNome IS NOT NULL')
            ->andWhere('a.convenioNome != \'\'')
            ->orderBy('a.convenioNome', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($res));
    }

    /**
     * Retorna um Iterable para streaming de todos os agendamentos com dados analíticos completos para exportação de dados brutos.
     */
    public function obterIterableAgendamentosCompletos(?string $tipoPeriodo = null, ?int $ano = null, ?int $mes = null, ?string $dataInicioCustom = null, ?string $dataFimCustom = null): iterable
    {
        $qb = $this->agendamentoRepo->createQueryBuilder('a')
            ->leftJoin('a.paciente', 'p')
            ->leftJoin('a.medico', 'm')
            ->leftJoin('a.especialidade', 'e')
            ->select('
                a.id,
                a.dataHoraAgendada,
                a.createdAt,
                a.horarioChegada,
                a.horarioInicioConsulta,
                a.horarioFimConsulta,
                a.horarioSaida,
                a.tipoAtendimento,
                a.convenioNome,
                a.procedimentoNome,
                a.status,
                a.encaixe,
                p.nomeCompleto as pacienteNome,
                p.sexo as pacienteSexo,
                p.dataNascimento as pacienteNascimento,
                m.nome as medicoNome,
                e.nome as especialidadeNome
            ')
            ->orderBy('a.dataHoraAgendada', 'ASC');

        if ($tipoPeriodo === 'ano_especifico' && $ano) {
            $qb->where('a.dataHoraAgendada BETWEEN :inicio AND :fim')
               ->setParameter('inicio', new \DateTime("{$ano}-01-01 00:00:00"))
               ->setParameter('fim', new \DateTime("{$ano}-12-31 23:59:59"));
        } elseif ($tipoPeriodo === 'mes_especifico' && $ano && $mes) {
            $dtInicio = new \DateTime("{$ano}-{$mes}-01 00:00:00");
            $dtFim = (clone $dtInicio)->modify('last day of this month')->setTime(23, 59, 59);
            $qb->where('a.dataHoraAgendada BETWEEN :inicio AND :fim')
               ->setParameter('inicio', $dtInicio)
               ->setParameter('fim', $dtFim);
        } elseif ($tipoPeriodo === 'personalizado' && !empty($dataInicioCustom) && !empty($dataFimCustom)) {
            $dtInicio = \DateTime::createFromFormat('Y-m-d', $dataInicioCustom);
            $dtFim = \DateTime::createFromFormat('Y-m-d', $dataFimCustom);
            if ($dtInicio && $dtFim) {
                $qb->where('a.dataHoraAgendada BETWEEN :inicio AND :fim')
                   ->setParameter('inicio', $dtInicio->setTime(0, 0, 0))
                   ->setParameter('fim', $dtFim->setTime(23, 59, 59));
            }
        } elseif ($tipoPeriodo === 'ultimos_6_meses') {
            $hoje = new \DateTime();
            $dataFim = (clone $hoje)->setTime(23, 59, 59);
            $dataInicio = (clone $hoje)->modify('-5 months')->modify('first day of this month')->setTime(0, 0, 0);
            $qb->where('a.dataHoraAgendada BETWEEN :inicio AND :fim')
               ->setParameter('inicio', $dataInicio)
               ->setParameter('fim', $dataFim);
        }

        return $qb->getQuery()->toIterable();
    }
}
