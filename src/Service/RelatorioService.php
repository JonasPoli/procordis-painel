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
    public function obterRelatorioProcedimentos(string $tipoPeriodo = 'ultimos_6_meses', ?int $ano = null, ?int $mes = null): array
    {
        $hoje = new \DateTime();
        $ano = $ano ?? (int) $hoje->format('Y');
        $mes = $mes ?? (int) $hoje->format('m');

        if ($tipoPeriodo === 'mes_especifico') {
            $dataInicio = new \DateTime("{$ano}-{$mes}-01 00:00:00");
            $dataFim = (clone $dataInicio)->modify('last day of this month')->setTime(23, 59, 59);
            $rotuloPeriodo = $dataInicio->format('m/Y');
        } else {
            // últimos 6 meses
            $dataFim = (clone $hoje)->setTime(23, 59, 59);
            $dataInicio = (clone $hoje)->modify('-5 months')->modify('first day of this month')->setTime(0, 0, 0);
            $rotuloPeriodo = 'Últimos 6 meses (' . $dataInicio->format('m/Y') . ' a ' . $dataFim->format('m/Y') . ')';
        }

        // Buscar agendamentos no período
        $qb = $this->agendamentoRepo->createQueryBuilder('a')
            ->leftJoin('a.paciente', 'p')->addSelect('p')
            ->leftJoin('a.medico', 'm')->addSelect('m')
            ->leftJoin('a.especialidade', 'e')->addSelect('e')
            ->where('a.dataHoraAgendada BETWEEN :inicio AND :fim')
            ->setParameter('inicio', $dataInicio)
            ->setParameter('fim', $dataFim)
            ->orderBy('a.dataHoraAgendada', 'ASC');

        /** @var Agendamento[] $agendamentos */
        $agendamentos = $qb->getQuery()->getResult();

        $totalProcedimentos = count($agendamentos);

        // Contadores de Financiamento / Convênio
        $countSus = 0;
        $countFilantropico = 0;
        $countConvenio = 0;
        $countParticular = 0;

        // Agrupamentos temporais
        $porDia = [];
        $porSemana = [];
        $porMes = [];
        $porSemestre = [];
        $porAno = [];
        $porProcedimento = [];
        $porEspecialidade = [];
        $porMedico = [];

        foreach ($agendamentos as $ag) {
            $tipoAt = strtolower($ag->getTipoAtendimento() ?? 'sus');
            if (str_contains($tipoAt, 'sus')) {
                $countSus++;
            } elseif (str_contains($tipoAt, 'filantrop')) {
                $countFilantropico++;
            } elseif (str_contains($tipoAt, 'particular')) {
                $countParticular++;
            } else {
                $countConvenio++;
            }

            $dt = $ag->getDataHoraAgendada() ?? $ag->getCreatedAt();
            $chaveDia = $dt->format('Y-m-d');
            $chaveSemana = 'Semana ' . $dt->format('W/Y');
            $chaveMes = $dt->format('m/Y');
            
            $numMes = (int) $dt->format('m');
            $semestreNum = $numMes <= 6 ? 1 : 2;
            $chaveSemestre = $semestreNum . 'º Semestre/' . $dt->format('Y');
            $chaveAno = $dt->format('Y');

            $porDia[$chaveDia] = ($porDia[$chaveDia] ?? 0) + 1;
            $porSemana[$chaveSemana] = ($porSemana[$chaveSemana] ?? 0) + 1;
            $porMes[$chaveMes] = ($porMes[$chaveMes] ?? 0) + 1;
            $porSemestre[$chaveSemestre] = ($porSemestre[$chaveSemestre] ?? 0) + 1;
            $porAno[$chaveAno] = ($porAno[$chaveAno] ?? 0) + 1;

            $nomeProc = $ag->getProcedimentoNome() ?? 'Consulta / Procedimento Geral';
            $porProcedimento[$nomeProc] = ($porProcedimento[$nomeProc] ?? 0) + 1;

            $espNome = $ag->getEspecialidade() ? $ag->getEspecialidade()->getNome() : 'Sem Especialidade';
            $porEspecialidade[$espNome] = ($porEspecialidade[$espNome] ?? 0) + 1;

            $medicoNome = $ag->getMedico() ? $ag->getMedico()->getNome() : 'Não Atribuído';
            $porMedico[$medicoNome] = ($porMedico[$medicoNome] ?? 0) + 1;
        }

        // Percentuais
        $pctSus = $totalProcedimentos > 0 ? round(($countSus / $totalProcedimentos) * 100, 1) : 0;
        $pctFilantropico = $totalProcedimentos > 0 ? round(($countFilantropico / $totalProcedimentos) * 100, 1) : 0;
        $pctConvenio = $totalProcedimentos > 0 ? round((($countConvenio + $countParticular) / $totalProcedimentos) * 100, 1) : 0;

        // Ordenar dados por dia
        ksort($porDia);
        ksort($porMes);
        ksort($porSemestre);
        ksort($porAno);

        // Média por dia com atendimento
        $diasComAtendimento = count($porDia);
        $mediaDia = $diasComAtendimento > 0 ? round($totalProcedimentos / $diasComAtendimento, 1) : 0;

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
            'porSemana' => $porSemana,
            'porMes' => $porMes,
            'porSemestre' => $porSemestre,
            'porAno' => $porAno,
            'porProcedimento' => $porProcedimento,
            'porEspecialidade' => $porEspecialidade,
            'porMedico' => $porMedico,
            'agendamentos' => $agendamentos,
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
