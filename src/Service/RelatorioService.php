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

        // 2. Trazer apenas os campos estritamente necessários em formato Array escalar
        $items = $qb->select('a.id, a.dataHoraAgendada, a.createdAt, a.tipoAtendimento, a.convenioNome, a.procedimentoNome, a.status, p.nomeCompleto as pacienteNome, m.nome as medicoNome, e.nome as especialidadeNome')
            ->getQuery()
            ->getArrayResult();

        // Contadores de Financiamento / Convênio
        $countSus = 0;
        $countFilantropico = 0;
        $countConvenio = 0;
        $countParticular = 0;

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
        $porStatus = [];
        $porProcedimentoMes = []; // Matriz [procedimentoNome][mês] = qtd
        $porProcedimentoDia = []; // Matriz [dia][procedimentoNome] = qtd

        foreach ($items as $item) {
            $tipoAt = strtolower($item['tipoAtendimento'] ?? 'sus');
            if (str_contains($tipoAt, 'sus')) {
                $countSus++;
            } elseif (str_contains($tipoAt, 'filantrop')) {
                $countFilantropico++;
            } elseif (str_contains($tipoAt, 'particular')) {
                $countParticular++;
            } else {
                $countConvenio++;
            }

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

            $nomeProc = $item['procedimentoNome'] ?? 'Consulta / Procedimento Geral';
            $porProcedimento[$nomeProc] = ($porProcedimento[$nomeProc] ?? 0) + 1;

            if (!isset($porProcedimentoMes[$nomeProc])) {
                $porProcedimentoMes[$nomeProc] = [];
            }
            $porProcedimentoMes[$nomeProc][$chaveMes] = ($porProcedimentoMes[$nomeProc][$chaveMes] ?? 0) + 1;

            $espNome = $item['especialidadeNome'] ?? 'Sem Especialidade';
            $porEspecialidade[$espNome] = ($porEspecialidade[$espNome] ?? 0) + 1;

            $medicoNome = $item['medicoNome'] ?? 'Não Atribuído';
            $porMedico[$medicoNome] = ($porMedico[$medicoNome] ?? 0) + 1;

            $st = strtoupper($item['status'] ?? 'AGENDADO');
            $porStatus[$st] = ($porStatus[$st] ?? 0) + 1;

            if (!isset($porProcedimentoDia[$chaveDia])) {
                $porProcedimentoDia[$chaveDia] = [];
            }
            $porProcedimentoDia[$chaveDia][$nomeProc] = ($porProcedimentoDia[$chaveDia][$nomeProc] ?? 0) + 1;
        }

        // Percentuais
        $pctSus = $totalProcedimentos > 0 ? round(($countSus / $totalProcedimentos) * 100, 1) : 0;
        $pctFilantropico = $totalProcedimentos > 0 ? round(($countFilantropico / $totalProcedimentos) * 100, 1) : 0;
        $pctConvenio = $totalProcedimentos > 0 ? round((($countConvenio + $countParticular) / $totalProcedimentos) * 100, 1) : 0;

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

        // Preparar datasets por procedimento alinhados aos meses
        $mesesUnicos = array_keys($porMes);
        $datasetsProcedimentos = [];
        $paletaCores = [
            '#0891b2', '#059669', '#6366f1', '#d97706', '#dc2626', 
            '#8b5cf6', '#ec4899', '#0284c7', '#14b8a6', '#f59e0b',
            '#64748b', '#84cc16', '#3b82f6', '#a855f7', '#f43f5e'
        ];
        $i = 0;

        foreach ($porProcedimento as $procName => $totalCount) {
            $serieData = [];
            foreach ($mesesUnicos as $m) {
                $serieData[] = $porProcedimentoMes[$procName][$m] ?? 0;
            }
            $cor = $paletaCores[$i % count($paletaCores)];
            $datasetsProcedimentos[] = [
                'nome' => $procName,
                'total' => $totalCount,
                'cor' => $cor,
                'data' => $serieData,
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
}
