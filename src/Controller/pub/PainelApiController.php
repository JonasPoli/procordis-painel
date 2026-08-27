<?php

namespace App\Controller\pub;

use App\Entity\Agendamento;
use App\Repository\AgendamentoRepository;
use App\Repository\ChamadaTelaoRepository;
use App\Repository\EspecialidadeRepository;
use App\Repository\MedicoRepository;
use App\Repository\PacienteRepository;
use App\Repository\SetorSalaRepository;
use App\Service\DataSimulatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use App\Repository\ProcedimentoSlaRepository;
use App\Repository\ConfiguracaoIntegracaoRepository;
use App\Service\MedwareApiClientService;
use App\Entity\ProcedimentoSla;

#[Route('/api/v1', name: 'api_v1_')]
class PainelApiController extends AbstractController
{
    public function __construct(
        private AgendamentoRepository $agendamentoRepo,
        private ChamadaTelaoRepository $chamadaRepo,
        private MedicoRepository $medicoRepo,
        private EspecialidadeRepository $especialidadeRepo,
        private SetorSalaRepository $setorSalaRepo,
        private PacienteRepository $pacienteRepo,
        private DataSimulatorService $simulatorService,
        private ProcedimentoSlaRepository $slaRepo,
        private ?ConfiguracaoIntegracaoRepository $configRepo = null,
        private ?MedwareApiClientService $medwareClient = null
    ) {
    }

    private function verificarAutoSync(): void
    {
        if (!$this->configRepo || !$this->medwareClient) {
            return;
        }

        try {
            $config = $this->configRepo->getObterOuCriarConfiguracao();
            if (!$config->isModoSimulacao()) {
                $ultimo = $config->getUltimoSyncEm();
                $agora = new \DateTime();
                if (!$ultimo || ($agora->getTimestamp() - $ultimo->getTimestamp()) > 10) {
                    $this->medwareClient->sincronizarAgendamentosHoje();
                }
            }
        } catch (\Throwable $e) {
            // Silencioso em caso de timeout transitório para não travar resposta do painel
        }
    }

    #[Route('/painel/espera', name: 'painel_espera', methods: ['GET'])]
    public function painelEspera(Request $request): JsonResponse
    {
        $this->verificarAutoSync();
        $medicoId = $request->query->get('medico') ? (int) $request->query->get('medico') : null;
        $espId = $request->query->get('especialidade') ? (int) $request->query->get('especialidade') : null;

        $aguardando = $this->agendamentoRepo->findAguardandoAtendimento($medicoId, $espId);
        $maiorEspera = $this->agendamentoRepo->findPacienteMaiorTempoEspera();

        $lista = array_map(function (Agendamento $a) {
            return [
                'id' => $a->getId(),
                'codigoAgendamento' => $a->getCodigoAgendamento(),
                'pacienteNome' => $a->getPaciente() ? $a->getPaciente()->getNomeExibicao() : 'Paciente',
                'horarioChegada' => $a->getHorarioChegada() ? $a->getHorarioChegada()->format('H:i') : '--:--',
                'tempoEsperaMinutos' => $a->getTempoEsperaMinutos() ?? 0,
                'status' => $a->getStatus(),
                'statusRotulo' => $this->rotularStatus($a->getStatus()),
                'medicoNome' => $a->getMedico() ? $a->getMedico()->getNome() : 'A definir',
                'especialidadeNome' => $a->getEspecialidade() ? $a->getEspecialidade()->getNome() : 'A definir',
                'setorSala' => $a->getSetorSala() ? $a->getSetorSala()->getNomeSala() : 'A definir',
                'prioridade' => $a->isPrioridade(),
                'encaixe' => $a->isEncaixe(),
            ];
        }, $aguardando);

        $topWaiting = null;
        if ($maiorEspera) {
            $topWaiting = [
                'id' => $maiorEspera->getId(),
                'pacienteNome' => $maiorEspera->getPaciente() ? $maiorEspera->getPaciente()->getNomeExibicao() : 'Paciente',
                'horarioChegada' => $maiorEspera->getHorarioChegada() ? $maiorEspera->getHorarioChegada()->format('H:i') : '--:--',
                'tempoEsperaMinutos' => $maiorEspera->getTempoEsperaMinutos() ?? 0,
                'medicoNome' => $maiorEspera->getMedico() ? $maiorEspera->getMedico()->getNome() : 'Qualquer Médico',
            ];
        }

        $resumo = $this->agendamentoRepo->getResumoMetricasHoje();

        return new JsonResponse([
            'sucesso' => true,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'totalAguardando' => count($lista),
            'maiorEspera' => $topWaiting,
            'pacientes' => $lista,
            'resumoMetricas' => $resumo,
        ]);
    }

    #[Route('/painel/chamada/ultimas', name: 'painel_chamada_ultimas', methods: ['GET'])]
    public function painelChamadaUltimas(): JsonResponse
    {
        $this->verificarAutoSync();
        $chamadas = $this->chamadaRepo->findUltimasChamadas(5);
        $dados = array_map(function ($c) {
            return [
                'id' => $c->getId(),
                'senha' => $c->getSenha() ? $c->getSenha()->getNumeroFormatado() : 'SENHA',
                'pacienteNomeMascarado' => $c->getPacienteNomeMascarado(),
                'medicoNome' => $c->getMedico() ? $c->getMedico()->getNome() : null,
                'guicheOuConsultorio' => $c->getGuicheOuConsultorio() ?? 'Consultório',
                'dataHoraChamada' => $c->getDataHoraChamada() ? $c->getDataHoraChamada()->format('H:i:s') : '',
                'rechamadaCount' => $c->getRechamadaCount(),
            ];
        }, $chamadas);

        return new JsonResponse([
            'sucesso' => true,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'ultimaChamada' => $dados[0] ?? null,
            'historicoChamadas' => array_slice($dados, 1),
            'todasChamadas' => $dados,
        ]);
    }

    #[Route('/painel/medicos', name: 'painel_medicos', methods: ['GET'])]
    public function painelMedicos(): JsonResponse
    {
        $this->verificarAutoSync();
        $medicos = $this->medicoRepo->findAll();
        $dados = [];

        foreach ($medicos as $m) {
            $aguardando = $this->agendamentoRepo->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.medico = :m')
                ->andWhere('a.status IN (:st)')
                ->setParameter('m', $m)
                ->setParameter('st', ['aguardando_medico', 'em_triagem'])
                ->getQuery()
                ->getSingleScalarResult();

            $emConsulta = $this->agendamentoRepo->findOneBy([
                'medico' => $m,
                'status' => 'em_consulta'
            ]);

            $pacienteAtual = null;
            if ($emConsulta) {
                $pacienteAtual = [
                    'id' => $emConsulta->getId(),
                    'nome' => $emConsulta->getPaciente() ? $emConsulta->getPaciente()->getNomeExibicao() : 'Paciente',
                    'inicioConsulta' => $emConsulta->getHorarioInicioConsulta() ? $emConsulta->getHorarioInicioConsulta()->format('H:i') : '',
                    'tempoDecorridoMinutos' => $emConsulta->getTempoConsultaMinutos() ?? 0,
                    'sala' => $emConsulta->getSetorSala() ? $emConsulta->getSetorSala()->getNomeSala() : 'Consultório',
                ];
            }

            $dados[] = [
                'id' => $m->getId(),
                'nome' => $m->getNome(),
                'crm' => $m->getCrm(),
                'especialidade' => $m->getEspecialidade() ? $m->getEspecialidade()->getNome() : 'Clínica Geral',
                'statusAtividade' => $m->getStatusAtividade(),
                'pacientesAguardandoCount' => (int) $aguardando,
                'pacienteAtual' => $pacienteAtual,
            ];
        }

        return new JsonResponse([
            'sucesso' => true,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'medicos' => $dados,
        ]);
    }

    #[Route('/painel/triagem', name: 'painel_triagem', methods: ['GET'])]
    public function painelTriagem(): JsonResponse
    {
        $this->verificarAutoSync();
        $aguardandoTriagem = $this->agendamentoRepo->findBy(['status' => 'aguardando_triagem'], ['horarioChegada' => 'ASC']);
        $emTriagem = $this->agendamentoRepo->findBy(['status' => 'em_triagem'], ['horarioInicioTriagem' => 'ASC']);

        $format = function (Agendamento $a) {
            return [
                'id' => $a->getId(),
                'pacienteNome' => $a->getPaciente() ? $a->getPaciente()->getNomeExibicao() : 'Paciente',
                'horarioChegada' => $a->getHorarioChegada() ? $a->getHorarioChegada()->format('H:i') : '--:--',
                'tempoEsperaMinutos' => $a->getTempoEsperaMinutos() ?? 0,
                'prioridade' => $a->isPrioridade(),
            ];
        };

        return new JsonResponse([
            'sucesso' => true,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'totalFilaTriagem' => count($aguardandoTriagem),
            'totalEmTriagem' => count($emTriagem),
            'filaTriagem' => array_map($format, $aguardandoTriagem),
            'emTriagem' => array_map($format, $emTriagem),
        ]);
    }

    #[Route('/painel/dashboard', name: 'painel_dashboard', methods: ['GET'])]
    public function painelDashboard(): JsonResponse
    {
        $this->verificarAutoSync();
        $resumo = $this->agendamentoRepo->getResumoMetricasHoje();
        $todosHoje = $this->agendamentoRepo->findAll();

        $temposEspera = [];
        $temposConsulta = [];
        $fluxoPorHora = array_fill(7, 13, 0); // 07:00 às 19:00

        foreach ($todosHoje as $a) {
            if ($a->getHorarioChegada()) {
                $h = (int) $a->getHorarioChegada()->format('H');
                if (isset($fluxoPorHora[$h])) {
                    $fluxoPorHora[$h]++;
                }
            }
            if ($a->getTempoEsperaMinutos() !== null) {
                $temposEspera[] = $a->getTempoEsperaMinutos();
            }
            if ($a->getTempoConsultaMinutos() !== null) {
                $temposConsulta[] = $a->getTempoConsultaMinutos();
            }
        }

        $mediaEspera = count($temposEspera) > 0 ? (int) (array_sum($temposEspera) / count($temposEspera)) : 0;
        $mediaConsulta = count($temposConsulta) > 0 ? (int) (array_sum($temposConsulta) / count($temposConsulta)) : 0;

        return new JsonResponse([
            'sucesso' => true,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'resumo' => $resumo,
            'tempoMedioEsperaMinutos' => $mediaEspera,
            'tempoMedioConsultaMinutos' => $mediaConsulta,
            'totalAtendidosHoje' => $resumo['finalizado'],
            'totalAguardandoHoje' => $resumo['aguardando_triagem'] + $resumo['em_triagem'] + $resumo['aguardando_medico'],
            'fluxoPorHora' => $fluxoPorHora,
        ]);
    }

    #[Route('/painel/paciente/{id}/historico', name: 'painel_paciente_historico', methods: ['GET'])]
    public function pacienteHistorico(int $id): JsonResponse
    {
        $agendamento = $this->agendamentoRepo->find($id);
        if (!$agendamento) {
            return new JsonResponse(['sucesso' => false, 'mensagem' => 'Agendamento não encontrado'], 404);
        }

        $historico = [];
        foreach ($agendamento->getHistoricoEtapas() as $h) {
            $historico[] = [
                'id' => $h->getId(),
                'etapa' => $h->getEtapa(),
                'etapaRotulo' => ucfirst($h->getEtapa()),
                'inicio' => $h->getDataHoraInicio() ? $h->getDataHoraInicio()->format('H:i:s') : '',
                'fim' => $h->getDataHoraFim() ? $h->getDataHoraFim()->format('H:i:s') : '',
                'duracaoSegundos' => $h->getDuracaoSegundos(),
                'responsavel' => $h->getResponsavel(),
                'sala' => $h->getSetorSala() ? $h->getSetorSala()->getNomeSala() : null,
            ];
        }

        return new JsonResponse([
            'sucesso' => true,
            'paciente' => [
                'id' => $agendamento->getPaciente() ? $agendamento->getPaciente()->getId() : null,
                'nome' => $agendamento->getPaciente() ? $agendamento->getPaciente()->getNomeExibicao() : 'Paciente',
                'codigoAgendamento' => $agendamento->getCodigoAgendamento(),
                'statusAtual' => $agendamento->getStatus(),
                'medico' => $agendamento->getMedico() ? $agendamento->getMedico()->getNome() : 'A definir',
                'especialidade' => $agendamento->getEspecialidade() ? $agendamento->getEspecialidade()->getNome() : 'A definir',
                'horarioAgendado' => $agendamento->getDataHoraAgendada() ? $agendamento->getDataHoraAgendada()->format('H:i') : '--:--',
                'horarioChegada' => $agendamento->getHorarioChegada() ? $agendamento->getHorarioChegada()->format('H:i') : '--:--',
                'tempoTotalEspera' => $agendamento->getTempoEsperaMinutos(),
            ],
            'historico' => $historico,
        ]);
    }

    #[Route('/simulador/passo', name: 'simulador_passo', methods: ['POST'])]
    public function simuladorPasso(): JsonResponse
    {
        $logs = $this->simulatorService->simularPassoMinuto(1);
        return new JsonResponse([
            'sucesso' => true,
            'mensagem' => 'Passo da simulação executado com sucesso.',
            'logs' => $logs,
        ]);
    }

    #[Route('/painel/aguardando', name: 'painel_aguardando', methods: ['GET'])]
    public function painelAguardando(): JsonResponse
    {
        $this->verificarAutoSync();
        $aguardando = $this->agendamentoRepo->createQueryBuilder('a')
            ->where('a.status IN (:st)')
            ->setParameter('st', ['aguardando_triagem', 'em_triagem', 'aguardando_medico'])
            ->orderBy('a.horarioChegada', 'ASC')
            ->getQuery()->getResult();

        $slas = $this->slaRepo->findAll();
        $slaRulesMap = [];
        foreach ($slas as $s) {
            $slaRulesMap[mb_strtolower($s->getNomeProcedimento())] = $s;
        }

        $temposParaRecepcao = [];
        $temposDeRecepcao = [];
        $temposTotais = [];
        $maiorTempoMin = 0;
        $maiorTempoPac = null;

        $lista = array_map(function (Agendamento $a) use (&$temposParaRecepcao, &$temposDeRecepcao, &$temposTotais, &$maiorTempoMin, &$maiorTempoPac, $slaRulesMap) {
            $paraRecepcao = $a->getTempoParaRecepcaoMinutos();
            if ($paraRecepcao !== null) $temposParaRecepcao[] = $paraRecepcao;

            $deRecepcao = $a->getTempoRecepcaoMinutos();
            if ($deRecepcao !== null) $temposDeRecepcao[] = $deRecepcao;

            $tempoTotal = $a->getTempoTotalAtendimentoMinutos() ?? 0;
            $temposTotais[] = $tempoTotal;

            if ($tempoTotal > $maiorTempoMin) {
                $maiorTempoMin = $tempoTotal;
                $maiorTempoPac = $a;
            }

            // Calcular cor do SLA baseado no procedimento ou regras globais
            $procNome = mb_strtolower($a->getProcedimentoNome() ?? '');
            $limiteV = 59;
            $limiteA = 119;
            if (isset($slaRulesMap[$procNome])) {
                $limiteV = $slaRulesMap[$procNome]->getLimiteVerdeMinutos();
                $limiteA = $slaRulesMap[$procNome]->getLimiteAmareloMinutos();
            }
            $corSla = $a->getSlaStatus($limiteV, $limiteA);

            // Gerar ticket formatado de senha
            $prefixo = $a->isPrioridade() ? 'P' : 'N';
            $ticketSenha = $prefixo . str_pad((string) ($a->getId() % 99 + 1), 3, '0', STR_PAD_LEFT);

            return [
                'id' => $a->getId(),
                'senha' => $ticketSenha,
                'tipoSenha' => $a->getProcedimentoNome() ?? ($a->isPrioridade() ? 'Preferencial' : 'Normal'),
                'etapaAtual' => $a->getEtapaRotuloPainel(),
                'qtd' => $a->getQtdExames(),
                'atendimento' => $a->getCodigoAgendamento() ?? ('ATD-' . $a->getId()),
                'an' => $a->getAccessNumber() ?? ('AN-2026-' . (1000 + $a->getId())),
                'data' => ($a->getHorarioChegada() ?? new \DateTime())->format('d/m/Y'),
                'horaSenha' => $a->getHorarioChegada() ? $a->getHorarioChegada()->format('H:i:s') : '--:--:--',
                'inicioRecepcao' => $a->getHorarioInicioTriagem() ? $a->getHorarioInicioTriagem()->format('H:i:s') : '--:--:--',
                'fimRecepcao' => $a->getHorarioFimTriagem() ? $a->getHorarioFimTriagem()->format('H:i:s') : '--:--:--',
                'tempoTotalFormatado' => sprintf('%02d:%02d', intdiv($tempoTotal, 60), $tempoTotal % 60),
                'tempoTotalMinutos' => $tempoTotal,
                'slaCor' => $corSla,
                'guiche' => $a->getGuicheAtendimento() ?? 'Guichê 01',
                'procedimento' => $a->getProcedimentoNome() ?? ($a->getEspecialidade() ? $a->getEspecialidade()->getNome() : 'Consulta'),
            ];
        }, $aguardando);

        $fmtMin = function (array $arr) {
            if (empty($arr)) return '00:00';
            $avg = (int) (array_sum($arr) / count($arr));
            return sprintf('%02d:%02d', intdiv($avg, 60), $avg % 60);
        };

        return new JsonResponse([
            'sucesso' => true,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'kpis' => [
                'totalSenhas' => count($lista),
                'mediaTempoParaRecepcao' => $fmtMin($temposParaRecepcao),
                'mediaTempoDeRecepcao' => $fmtMin($temposDeRecepcao),
                'mediaTempoTotalAtendimento' => $fmtMin($temposTotais),
                'maiorTempoAtendimento' => sprintf('%02d:%02d', intdiv($maiorTempoMin, 60), $maiorTempoMin % 60),
            ],
            'pacientes' => $lista,
        ]);
    }

    #[Route('/painel/finalizados', name: 'painel_finalizados', methods: ['GET'])]
    public function painelFinalizados(): JsonResponse
    {
        $this->verificarAutoSync();
        $finalizados = $this->agendamentoRepo->findBy(['status' => 'finalizado'], ['horarioSaida' => 'DESC']);

        $slas = $this->slaRepo->findAll();
        $slaRulesMap = [];
        foreach ($slas as $s) {
            $slaRulesMap[mb_strtolower($s->getNomeProcedimento())] = $s;
        }

        $temposParaRecepcao = [];
        $temposDeRecepcao = [];
        $temposEsperaExame = [];
        $temposTotais = [];
        $maiorTempoMin = 0;

        $procedimentosPorHora = array_fill(6, 14, 0); // 06:00 às 19:00
        $procedimentosCount = [];
        $procedimentosMediaMap = [];
        $guichesCount = [];
        $slaCounts = ['verde' => 0, 'amarelo' => 0, 'vermelho' => 0];

        $lista = array_map(function (Agendamento $a) use (
            &$temposParaRecepcao, &$temposDeRecepcao, &$temposEsperaExame, &$temposTotais,
            &$maiorTempoMin, &$procedimentosPorHora, &$procedimentosCount, &$procedimentosMediaMap,
            &$guichesCount, &$slaCounts, $slaRulesMap
        ) {
            $paraRecepcao = $a->getTempoParaRecepcaoMinutos();
            if ($paraRecepcao !== null) $temposParaRecepcao[] = $paraRecepcao;

            $deRecepcao = $a->getTempoRecepcaoMinutos();
            if ($deRecepcao !== null) $temposDeRecepcao[] = $deRecepcao;

            $esperaExame = $a->getTempoEsperaMinutos();
            if ($esperaExame !== null) $temposEsperaExame[] = $esperaExame;

            $tempoTotal = $a->getTempoTotalAtendimentoMinutos() ?? 0;
            $temposTotais[] = $tempoTotal;
            if ($tempoTotal > $maiorTempoMin) $maiorTempoMin = $tempoTotal;

            // SLA
            $procNomeStr = $a->getProcedimentoNome() ?? 'Consulta Cardiológica';
            $procNome = mb_strtolower($procNomeStr);
            $limiteV = 59;
            $limiteA = 119;
            if (isset($slaRulesMap[$procNome])) {
                $limiteV = $slaRulesMap[$procNome]->getLimiteVerdeMinutos();
                $limiteA = $slaRulesMap[$procNome]->getLimiteAmareloMinutos();
            }
            $corSla = $a->getSlaStatus($limiteV, $limiteA);
            $slaCounts[$corSla]++;

            // Métricas por Hora
            $horaStr = $a->getHorarioSaida() ? (int) $a->getHorarioSaida()->format('H') : 8;
            if (isset($procedimentosPorHora[$horaStr])) {
                $procedimentosPorHora[$horaStr]++;
            }

            // Procedimentos Count & Media
            if (!isset($procedimentosCount[$procNomeStr])) {
                $procedimentosCount[$procNomeStr] = 0;
                $procedimentosMediaMap[$procNomeStr] = [];
            }
            $procedimentosCount[$procNomeStr]++;
            $procedimentosMediaMap[$procNomeStr][] = $tempoTotal;

            // Guichês
            $g = $a->getGuicheAtendimento() ?? 'Guichê 01';
            $guichesCount[$g] = ($guichesCount[$g] ?? 0) + 1;

            $prefixo = $a->isPrioridade() ? 'P' : 'N';
            $ticketSenha = $prefixo . str_pad((string) ($a->getId() % 99 + 1), 3, '0', STR_PAD_LEFT);

            return [
                'id' => $a->getId(),
                'senha' => $ticketSenha,
                'tipoSenha' => $procNomeStr,
                'etapaAtual' => 'ATEND FINALIZADO',
                'qtd' => $a->getQtdExames(),
                'atendimento' => $a->getCodigoAgendamento() ?? ('ATD-' . $a->getId()),
                'an' => $a->getAccessNumber() ?? ('AN-2026-' . (1000 + $a->getId())),
                'data' => ($a->getHorarioSaida() ?? new \DateTime())->format('d/m/Y'),
                'horaSenha' => $a->getHorarioChegada() ? $a->getHorarioChegada()->format('H:i:s') : '--:--:--',
                'inicioRecepcao' => $a->getHorarioInicioTriagem() ? $a->getHorarioInicioTriagem()->format('H:i:s') : '--:--:--',
                'fimRecepcao' => $a->getHorarioFimTriagem() ? $a->getHorarioFimTriagem()->format('H:i:s') : '--:--:--',
                'hrExameConsulta' => $a->getHorarioInicioConsulta() ? $a->getHorarioInicioConsulta()->format('H:i:s') : '--:--:--',
                'tempoTotalFormatado' => sprintf('%02d:%02d', intdiv($tempoTotal, 60), $tempoTotal % 60),
                'tempoTotalMinutos' => $tempoTotal,
                'slaCor' => $corSla,
                'guiche' => $g,
                'procedimento' => $procNomeStr,
            ];
        }, $finalizados);

        $fmtMin = function (array $arr) {
            if (empty($arr)) return '00:00';
            $avg = (int) (array_sum($arr) / count($arr));
            return sprintf('%02d:%02d', intdiv($avg, 60), $avg % 60);
        };

        // Formatar médias por procedimento
        $procedimentosAnalise = [];
        foreach ($procedimentosCount as $pNome => $qtd) {
            $arrTot = $procedimentosMediaMap[$pNome];
            $avgMin = count($arrTot) > 0 ? (int) (array_sum($arrTot) / count($arrTot)) : 0;
            $procedimentosAnalise[] = [
                'procedimento' => $pNome,
                'quantidade' => $qtd,
                'mediaMinutos' => $avgMin,
            ];
        }

        return new JsonResponse([
            'sucesso' => true,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'kpis' => [
                'totalSenhas' => count($lista),
                'mediaTempoParaRecepcao' => $fmtMin($temposParaRecepcao),
                'mediaTempoDeRecepcao' => $fmtMin($temposDeRecepcao),
                'mediaEsperaExameConsulta' => $fmtMin($temposEsperaExame),
                'mediaTempoTotalAtendimento' => $fmtMin($temposTotais),
                'maiorTempoAtendimento' => sprintf('%02d:%02d', intdiv($maiorTempoMin, 60), $maiorTempoMin % 60),
            ],
            'graficos' => [
                'procedimentoPorHora' => $procedimentosPorHora,
                'procedimentosAnalise' => $procedimentosAnalise,
                'atendimentosPorGuiche' => $guichesCount,
                'slaDistribuicao' => $slaCounts,
            ],
            'pacientes' => $lista,
        ]);
    }

    #[Route('/painel/sla-config', name: 'painel_sla_config', methods: ['GET'])]
    public function painelSlaConfig(): JsonResponse
    {
        $slas = $this->slaRepo->findAll();
        $lista = array_map(function (ProcedimentoSla $s) {
            return [
                'id' => $s->getId(),
                'codigo' => $s->getCodigo(),
                'nomeProcedimento' => $s->getNomeProcedimento(),
                'limiteVerdeMinutos' => $s->getLimiteVerdeMinutos(),
                'limiteAmareloMinutos' => $s->getLimiteAmareloMinutos(),
                'descricao' => $s->getDescricao(),
            ];
        }, $slas);

        return new JsonResponse([
            'sucesso' => true,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'regrasSla' => $lista,
        ]);
    }

    #[Route('/painel/sla-config/salvar', name: 'painel_sla_config_salvar', methods: ['POST'])]
    public function painelSlaConfigSalvar(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!$payload || !is_array($payload)) {
            return new JsonResponse(['sucesso' => false, 'mensagem' => 'Dados inválidos'], 400);
        }

        foreach ($payload as $item) {
            if (isset($item['id'])) {
                $sla = $this->slaRepo->find($item['id']);
                if ($sla) {
                    if (isset($item['limiteVerdeMinutos'])) $sla->setLimiteVerdeMinutos((int) $item['limiteVerdeMinutos']);
                    if (isset($item['limiteAmareloMinutos'])) $sla->setLimiteAmareloMinutos((int) $item['limiteAmareloMinutos']);
                }
            }
        }

        $this->slaRepo->getEntityManager()->flush();

        return new JsonResponse(['sucesso' => true, 'mensagem' => 'Regras de SLA atualizadas com sucesso']);
    }

    private function rotularStatus(string $status): string
    {
        return match ($status) {
            'agendado' => 'Agendado',
            'aguardando_triagem' => 'Aguardando Triagem',
            'em_triagem' => 'Em Triagem',
            'aguardando_medico' => 'Aguardando Médico',
            'em_consulta' => 'Em Consulta',
            'em_exame' => 'Em Exame',
            'finalizado' => 'Atendimento Concluído',
            'cancelado' => 'Cancelado',
            'ausente' => 'Ausente',
            'desistencia' => 'Desistência',
            default => ucfirst($status),
        };
    }
}

