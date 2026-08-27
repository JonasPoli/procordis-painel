<?php

namespace App\Service;

use App\Entity\Agendamento;
use App\Entity\AtendimentoEtapaHistorico;
use App\Entity\ChamadaTelao;
use App\Entity\Especialidade;
use App\Entity\Medico;
use App\Entity\Paciente;
use App\Entity\SenhaAtendimento;
use App\Entity\SetorSala;
use App\Entity\Unidade;
use App\Entity\ProcedimentoSla;
use App\Repository\AgendamentoRepository;
use App\Repository\ChamadaTelaoRepository;
use App\Repository\EspecialidadeRepository;
use App\Repository\MedicoRepository;
use App\Repository\PacienteRepository;
use App\Repository\ProcedimentoSlaRepository;
use App\Repository\SenhaAtendimentoRepository;
use App\Repository\SetorSalaRepository;
use App\Repository\UnidadeRepository;
use Doctrine\ORM\EntityManagerInterface;

class DataSimulatorService
{
    private array $nomesPacientes = [
        'Carlos Alberto Silva', 'Maria Eduarda Santos', 'João Pedro Oliveira', 'Ana Beatriz Souza',
        'Fernando Henrique Lima', 'Juliana Fernandes', 'Roberto Carlos Pereira', 'Patricia Gomes',
        'Lucas Gabriel Costa', 'Camila Rodrigues', 'Marcelo Vinicius Alves', 'Larissa Amanda Ribeiro',
        'Gabriel Henrique Martins', 'Fernanda Cristina Rocha', 'Rafael Augusto Carvalho', 'Aline Maria Barbosa',
        'Rodrigo Jose Melo', 'Vanessa Leticia Cardoso', 'Thiago Alexander Araujo', 'Beatriz Elena Ramos'
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private UnidadeRepository $unidadeRepo,
        private EspecialidadeRepository $especialidadeRepo,
        private MedicoRepository $medicoRepo,
        private SetorSalaRepository $setorSalaRepo,
        private PacienteRepository $pacienteRepo,
        private AgendamentoRepository $agendamentoRepo,
        private SenhaAtendimentoRepository $senhaRepo,
        private ChamadaTelaoRepository $chamadaRepo,
        private ProcedimentoSlaRepository $slaRepo
    ) {
    }

    /**
     * Garante a existência dos cadastros base (Unidade, Especialidades, Médicos, Salas).
     */
    public function inicializarEstruturaBase(): void
    {
        $unidade = $this->unidadeRepo->findOneBy(['nome' => 'Associação Procordis']);
        if (!$unidade) {
            $unidade = new Unidade();
            $unidade->setNome('Associação Procordis');
            $unidade->setCodigoExterno('UNI-01');
            $unidade->setEndereco('Av. Queiroz Filho, 685 - Vila Sedenho, Araraquara - SP');
            $this->em->persist($unidade);
        }

        $especialidadesData = [
            ['Cardiologia Clínica', 'Consultas clínicas cardiológicas, avaliação de risco e acompanhamento'],
            ['Ecocardiografia', 'Ecocardiograma com Doppler colorido e ecodopplercardiografia'],
            ['Ergometria & Teste de Esforço', 'Teste ergométrico computadorizado e capacidade funcional'],
            ['Arritmologia & Eletrofisiologia', 'Holter 24h, ECG digital e avaliação de arritmias cardíacas'],
            ['Check-up & Risco Cirúrgico', 'Avaliação preventiva cardiológica e risco pré-operatório']
        ];

        $especialidadesObj = [];
        foreach ($especialidadesData as $idx => [$nome, $desc]) {
            $esp = $this->especialidadeRepo->findOneBy(['nome' => $nome]);
            if (!$esp) {
                $esp = new Especialidade();
                $esp->setNome($nome);
                $esp->setCodigoExterno('ESP-0' . ($idx + 1));
                $esp->setDescricao($desc);
                $this->em->persist($esp);
            }
            $especialidadesObj[] = $esp;
        }

        $this->em->flush();

        $medicosData = [
            ['Dr. Roberto Kalil', 'CRM-SP 64512', $especialidadesObj[0]],
            ['Dra. Mariana Arcoverde', 'CRM-SP 78234', $especialidadesObj[1]],
            ['Dr. Eduardo Vasconcelos', 'CRM-SP 89123', $especialidadesObj[2]],
            ['Dra. Paula Guimarães', 'CRM-SP 95671', $especialidadesObj[3]],
            ['Dr. André Luiz Fontes', 'CRM-SP 102345', $especialidadesObj[4]],
        ];

        foreach ($medicosData as $idx => [$nome, $crm, $esp]) {
            $med = $this->medicoRepo->findOneBy(['nome' => $nome]);
            if (!$med) {
                $med = new Medico();
                $med->setNome($nome);
                $med->setCodigoExterno('MED-0' . ($idx + 1));
                $med->setCrm($crm);
                $med->setEspecialidade($esp);
                $med->addUnidade($unidade);
                $this->em->persist($med);
            }
        }

        $salasData = [
            ['Recepção & Triagem', 'Guichê 01 - Recepção', 'triagem'],
            ['Recepção & Triagem', 'Guichê 02 - Recepção', 'triagem'],
            ['Setor de Enfermagem', 'Sala de Triagem / Anamnese', 'triagem'],
            ['Consultórios Cardiológicos', 'Consultório 01', 'consultorio'],
            ['Consultórios Cardiológicos', 'Consultório 02', 'consultorio'],
            ['Consultórios Cardiológicos', 'Consultório 03', 'consultorio'],
            ['Diagnóstico Cardiológico', 'Sala Ergométrica 01', 'exame'],
            ['Diagnóstico Cardiológico', 'Sala de Ecocardiograma 02', 'exame'],
        ];

        foreach ($salasData as $idx => [$setor, $sala, $tipo]) {
            $s = $this->setorSalaRepo->findOneBy(['nomeSala' => $sala]);
            if (!$s) {
                $s = new SetorSala();
                $s->setNomeSetor($setor);
                $s->setNomeSala($sala);
                $s->setTipo($tipo);
                $s->setCodigoExterno('SAL-0' . ($idx + 1));
                $s->setUnidade($unidade);
                $this->em->persist($s);
            }
        }

        $slasData = [
            ['PROC-ECG', 'Eletrocardiograma (ECG)', 15, 30, 'Eletrocardiograma digital de repouso (Verde <= 15m, Amarelo 16-30m, Vermelho > 30m)'],
            ['PROC-ECO', 'Ecocardiograma Transtorácico', 30, 45, 'Ecocardiograma com Doppler colorido (Verde <= 30m, Amarelo 31-45m, Vermelho > 45m)'],
            ['PROC-ERG', 'Teste Ergométrico Computadorizado', 40, 60, 'Teste ergométrico em esteira computadorizada'],
            ['PROC-MAPA', 'Instalação / Retirada MAPA 24h', 20, 35, 'Monitorização Ambulatorial da Pressão Arterial 24h'],
            ['PROC-HOLTER', 'Instalação / Retirada Holter 24h', 20, 35, 'Monitorização eletrocardiográfica contínua 24h'],
            ['PROC-CONS', 'Consulta Cardiológica Especializada', 45, 60, 'Consulta clínica com cardiologista especialista'],
            ['PROC-RISCO', 'Avaliação de Risco Cirúrgico', 30, 50, 'Avaliação e parecer cardiológico pré-operatório']
        ];

        foreach ($slasData as [$codigo, $nome, $verde, $amarelo, $desc]) {
            $sla = $this->slaRepo->findOneBy(['codigo' => $codigo]);
            if (!$sla) {
                $sla = new ProcedimentoSla();
                $sla->setCodigo($codigo);
                $sla->setNomeProcedimento($nome);
                $sla->setLimiteVerdeMinutos($verde);
                $sla->setLimiteAmareloMinutos($amarelo);
                $sla->setDescricao($desc);
                $this->em->persist($sla);
            }
        }

        $this->em->flush();
    }

    /**
     * Garante que haja pacientes aguardando em faixas variadas de tempo:
     * - Espera Longa: mais de 2 horas (>120min)
     * - Espera Média: mais de 15 minutos (>15min)
     * - Espera Recente: menos de 15 minutos (<15min)
     */
    public function garantirPacientesFilaVariada(): void
    {
        $this->inicializarEstruturaBase();

        $agora = new \DateTime();
        $medicos = $this->medicoRepo->findAll();
        $unidade = $this->unidadeRepo->findOneBy([]);

        $pacientesEmEspera = $this->agendamentoRepo->createQueryBuilder('a')
            ->where('a.status IN (:st)')
            ->setParameter('st', ['aguardando_triagem', 'aguardando_medico'])
            ->getQuery()->getResult();

        // Verificar se temos pacientes com >120m e >15m
        $temMaisDe2h = false;
        $temMaisDe15m = false;

        foreach ($pacientesEmEspera as $ag) {
            $tempoMin = $ag->getTempoEsperaMinutos() ?? 0;
            if ($tempoMin >= 120) {
                $temMaisDe2h = true;
            }
            if ($tempoMin >= 15 && $tempoMin < 120) {
                $temMaisDe15m = true;
            }
        }

        // Se faltar pacientes em alguma faixa de tempo, cria o lote variado
        if (count($pacientesEmEspera) < 5 || !$temMaisDe2h || !$temMaisDe15m) {
            $configuracoesFila = [
                // [Nome Paciente, minutos_atras_chegada, status, prioridade, encaixe, procedimento, guiche, qtd]
                ['Roberto Carlos Pereira', 145, 'aguardando_medico', true, false, 'Ecocardiograma Transtorácico', 'Guichê 01', 1],   // 2h25m de espera (>2h)
                ['Juliana Fernandes', 132, 'aguardando_triagem', false, true, 'Teste Ergométrico Computadorizado', 'Guichê 02', 1],      // 2h12m de espera (>2h)
                ['Ana Beatriz Souza', 55, 'aguardando_medico', false, false, 'Ecocardiograma Transtorácico', 'Guichê 01', 1],       // 55m de espera (>15m)
                ['Carlos Alberto Silva', 35, 'aguardando_triagem', false, false, 'Holter 24 Horas', 'Guichê 02', 1],   // 35m de espera (>15m)
                ['Fernando Henrique Lima', 22, 'aguardando_medico', true, false, 'Consulta Cardiológica Especializada', 'Guichê 01', 1],   // 22m de espera (>15m)
                ['Larissa Amanda Ribeiro', 12, 'aguardando_medico', false, false, 'Eletrocardiograma (ECG)', 'Guichê 02', 1],  // 12m de espera (<15m)
                ['Camila Rodrigues', 6, 'aguardando_triagem', false, false, 'Ecocardiograma Transtorácico', 'Guichê 01', 1],        // 6m de espera (<15m)
            ];

            foreach ($configuracoesFila as [$nomePac, $minutosAtras, $status, $prio, $encaixe, $procedimento, $guiche, $qtd]) {
                $paciente = $this->pacienteRepo->findOneBy(['nomeCompleto' => $nomePac]);
                if (!$paciente) {
                    $paciente = new Paciente();
                    $paciente->setNomeCompleto($nomePac);
                    $paciente->setCodigoExterno('PAC-' . rand(1000, 9999));
                    $this->em->persist($paciente);
                }

                // Verificar se este paciente já tem agendamento ativo em espera
                $ag = $this->agendamentoRepo->findOneBy(['paciente' => $paciente, 'status' => $status]);
                if (!$ag) {
                    $medico = $medicos[array_rand($medicos)];
                    $dtChegada = (clone $agora)->modify("-{$minutosAtras} minutes");
                    $dtAgendada = (clone $dtChegada)->modify('-15 minutes');

                    $ag = new Agendamento();
                    $ag->setPaciente($paciente);
                    $ag->setMedico($medico);
                    $ag->setEspecialidade($medico->getEspecialidade());
                    $ag->setUnidade($unidade);
                    $ag->setCodigoAgendamento('AGD-' . rand(10000, 99999));
                    $ag->setAccessNumber('AN-2026-' . rand(1000, 9999));
                    $ag->setProcedimentoNome($procedimento);
                    $ag->setGuicheAtendimento($guiche);
                    $ag->setQtdExames($qtd);
                    $ag->setDataHoraAgendada($dtAgendada);
                    $ag->setHorarioChegada($dtChegada);
                    $ag->setHorarioConfirmacao($dtChegada);
                    $ag->setStatus($status);
                    $ag->setPrioridade($prio);
                    $ag->setEncaixe($encaixe);

                    if ($status === 'aguardando_medico') {
                        $ag->setHorarioInicioTriagem((clone $dtChegada)->modify('+3 minutes'));
                        $ag->setHorarioFimTriagem((clone $dtChegada)->modify('+8 minutes'));
                    }

                    $this->em->persist($ag);

                    // Gerar Senha
                    $prefixo = $prio ? 'P' : 'N';
                    $numSenha = $prefixo . str_pad((string) rand(1, 99), 3, '0', STR_PAD_LEFT);
                    $senha = new SenhaAtendimento();
                    $senha->setNumeroFormatado($numSenha);
                    $senha->setTipoSenha($prio ? 'preferencial' : 'normal');
                    $senha->setPaciente($paciente);
                    $senha->setStatus('gerada');
                    $this->em->persist($senha);

                    // Etapa
                    $etapa = new AtendimentoEtapaHistorico();
                    $etapa->setAgendamento($ag);
                    $etapa->setEtapa('chegada');
                    $etapa->setResponsavel('Recepção Central');
                    $etapa->setDataHoraInicio($dtChegada);
                    $this->em->persist($etapa);
                }
            }

            $this->em->flush();
        }

        $this->garantirPacientesFinalizadosDia();
    }

    /**
     * Garante um histórico completo de exames finalizados no dia para alimentação
     * de KPIs e dos 4 gráficos analíticos do painel de Pós-Atendimento (/painel/finalizados).
     */
    public function garantirPacientesFinalizadosDia(): void
    {
        $this->inicializarEstruturaBase();

        $pacientesFinalizadosCount = $this->agendamentoRepo->count(['status' => 'finalizado']);
        if ($pacientesFinalizadosCount >= 10) {
            return;
        }

        $medicos = $this->medicoRepo->findAll();
        $unidade = $this->unidadeRepo->findOneBy([]);
        $hoje = (new \DateTime())->format('Y-m-d');

        $finalizadosConfig = [
            // [Nome, Procedimento, Guiche, Qtd, HoraChegada, MinRecepcao, MinEspera, MinExame, AccessNumber, Prioridade]
            ['Marcos Vinicius Andrade', 'Ecocardiograma Transtorácico', 'Guichê 01', 1, '07:15', 5, 25, 30, 'AN-2026-9011', true],
            ['Patricia Lima Meireles', 'Eletrocardiograma (ECG)', 'Guichê 02', 1, '07:30', 3, 15, 15, 'AN-2026-9012', false],
            ['João Paulo Teixeira', 'Ecocardiograma Transtorácico', 'Guichê 01', 1, '07:45', 4, 20, 25, 'AN-2026-9013', false],
            ['Luciana Ramos Costa', 'Teste Ergométrico Computadorizado', 'Guichê 02', 1, '08:00', 6, 30, 35, 'AN-2026-9014', false],
            ['Gabriel Santos Ferreira', 'Consulta Cardiológica Especializada', 'Guichê 01', 1, '08:15', 4, 10, 20, 'AN-2026-9015', false],
            ['Renata Oliveira Silva', 'Holter 24 Horas', 'Guichê 02', 1, '08:30', 3, 20, 15, 'AN-2026-9016', false],
            ['Thiago Alcantara Dias', 'Ecocardiograma Transtorácico', 'Guichê 01', 1, '08:45', 5, 45, 30, 'AN-2026-9017', true],
            ['Vanessa Cristina Nunes', 'MAPA 24 Horas', 'Guichê 02', 1, '09:00', 4, 25, 20, 'AN-2026-9018', false],
            ['Bruno Eduardo Rocha', 'Consulta Cardiológica Especializada', 'Guichê 01', 1, '09:15', 3, 12, 18, 'AN-2026-9019', false],
            ['Helena Maria Castro', 'Eletrocardiograma (ECG)', 'Guichê 02', 1, '09:30', 4, 18, 14, 'AN-2026-9020', false],
            ['Rodrigo Duarte Prado', 'Teste Ergométrico Computadorizado', 'Guichê 01', 1, '09:45', 5, 35, 35, 'AN-2026-9021', false],
            ['Isabela Fontana Cruz', 'Ecocardiograma Transtorácico', 'Guichê 02', 1, '10:00', 3, 22, 28, 'AN-2026-9022', false],
            ['Daniel Freitas Alencar', 'Avaliação de Risco Cirúrgico', 'Guichê 01', 1, '10:30', 6, 40, 30, 'AN-2026-9023', false],
            ['Marcelo Nogueira Paes', 'Consulta Cardiológica Especializada', 'Guichê 02', 1, '11:00', 4, 15, 20, 'AN-2026-9024', false],
            ['Sofia Martins Barbosa', 'Eletrocardiograma (ECG)', 'Guichê 01', 1, '11:30', 3, 16, 15, 'AN-2026-9025', false],
        ];

        foreach ($finalizadosConfig as [$nomePac, $procedimento, $guiche, $qtd, $horaChegadaStr, $minRec, $minEsp, $minExame, $an, $prio]) {
            $paciente = $this->pacienteRepo->findOneBy(['nomeCompleto' => $nomePac]);
            if (!$paciente) {
                $paciente = new Paciente();
                $paciente->setNomeCompleto($nomePac);
                $paciente->setCodigoExterno('PAC-' . rand(1000, 9999));
                $this->em->persist($paciente);
            }

            $ag = $this->agendamentoRepo->findOneBy(['paciente' => $paciente]);
            if (!$ag) {
                $medico = $medicos[array_rand($medicos)];
                $dtChegada = new \DateTime("{$hoje} {$horaChegadaStr}:00");
                $dtAgendada = (clone $dtChegada)->modify('-15 minutes');

                $dtInicioRec = (clone $dtChegada)->modify('+2 minutes');
                $dtFimRec = (clone $dtInicioRec)->modify("+{$minRec} minutes");

                $dtInicioCons = (clone $dtFimRec)->modify("+{$minEsp} minutes");
                $dtPrimeiraImg = (clone $dtInicioCons)->modify('+5 minutes');
                $dtFimCons = (clone $dtInicioCons)->modify("+{$minExame} minutes");

                $ag = new Agendamento();
                $ag->setPaciente($paciente);
                $ag->setMedico($medico);
                $ag->setEspecialidade($medico->getEspecialidade());
                $ag->setUnidade($unidade);
                $ag->setCodigoAgendamento('AGD-' . rand(10000, 99999));
                $ag->setAccessNumber($an);
                $ag->setProcedimentoNome($procedimento);
                $ag->setGuicheAtendimento($guiche);
                $ag->setQtdExames($qtd);
                $ag->setDataHoraAgendada($dtAgendada);
                $ag->setHorarioChegada($dtChegada);
                $ag->setHorarioConfirmacao($dtChegada);
                $ag->setHorarioInicioTriagem($dtInicioRec);
                $ag->setHorarioFimTriagem($dtFimRec);
                $ag->setHorarioInicioConsulta($dtInicioCons);
                $ag->setHorarioPrimeiraImagem($dtPrimeiraImg);
                $ag->setHorarioFimConsulta($dtFimCons);
                $ag->setHorarioSaida($dtFimCons);
                $ag->setStatus('finalizado');
                $ag->setPrioridade($prio);

                $this->em->persist($ag);

                // Senha
                $prefixo = $prio ? 'P' : 'N';
                $numSenha = $prefixo . str_pad((string) rand(1, 99), 3, '0', STR_PAD_LEFT);
                $senha = new SenhaAtendimento();
                $senha->setNumeroFormatado($numSenha);
                $senha->setTipoSenha($prio ? 'preferencial' : 'normal');
                $senha->setPaciente($paciente);
                $senha->setStatus('finalizada');
                $this->em->persist($senha);
            }
        }

        $this->em->flush();
    }

    /**
     * Executa 1 passo da simulação do dia.
     */
    public function simularPassoMinuto(int $quantidadeMovimentos = 1): array
    {
        $this->garantirPacientesFilaVariada();

        $agora = new \DateTime();
        $medicos = $this->medicoRepo->findAll();
        $salasConsultorio = $this->setorSalaRepo->findBy(['tipo' => 'consultorio']);
        $salasTriagem = $this->setorSalaRepo->findBy(['tipo' => 'triagem']);

        $logs = [];

        // 1. Transição pontual: Aguardando Triagem -> Em Triagem (1 por passo para manter a fila viva)
        $aguardandoTriagem = $this->agendamentoRepo->findBy(['status' => 'aguardando_triagem'], ['id' => 'ASC'], 1);
        foreach ($aguardandoTriagem as $ag) {
            $ag->setStatus('em_triagem');
            $ag->setHorarioInicioTriagem(clone $agora);

            $etapa = new AtendimentoEtapaHistorico();
            $etapa->setAgendamento($ag);
            $etapa->setEtapa('triagem');
            $etapa->setResponsavel('Enfermagem / Triagem');
            if (count($salasTriagem) > 0) {
                $etapa->setSetorSala($salasTriagem[array_rand($salasTriagem)]);
            }
            $this->em->persist($etapa);

            $logs[] = "Início de Triagem: {$ag->getPaciente()->getNomeExibicao()} (Espera: {$ag->getTempoEsperaMinutos()} min)";
        }
        $this->em->flush();

        // 2. Transição pontual: Em Triagem -> Aguardando Médico (1 por passo)
        $emTriagem = $this->agendamentoRepo->findBy(['status' => 'em_triagem'], ['id' => 'ASC'], 1);
        foreach ($emTriagem as $ag) {
            $ag->setStatus('aguardando_medico');
            $ag->setHorarioFimTriagem(clone $agora);

            $logs[] = "Triagem concluída: {$ag->getPaciente()->getNomeExibicao()} -> Aguardando Médico";
        }
        $this->em->flush();

        // 3. Transição pontual: Aguardando Médico -> Chamada no Telão & Em Consulta (1 por passo)
        $aguardandoMedico = $this->agendamentoRepo->findBy(['status' => 'aguardando_medico'], ['id' => 'ASC'], 1);
        foreach ($aguardandoMedico as $ag) {
            $ag->setStatus('em_consulta');
            $ag->setHorarioInicioConsulta(clone $agora);

            $medico = $ag->getMedico() ?? $medicos[array_rand($medicos)];
            $ag->setMedico($medico);

            $sala = count($salasConsultorio) > 0 ? $salasConsultorio[array_rand($salasConsultorio)] : null;
            $ag->setSetorSala($sala);

            // Registrar Chamada no Telão
            $chamada = new ChamadaTelao();
            $chamada->setAgendamento($ag);
            $chamada->setPacienteNomeMascarado($ag->getPaciente()->getNomeExibicao());
            $chamada->setMedico($medico);
            $chamada->setSetorSala($sala);
            $chamada->setGuicheOuConsultorio($sala ? $sala->getNomeSala() : 'Consultório');
            $this->em->persist($chamada);

            $medico->setStatusAtividade('em_atendimento');

            $etapa = new AtendimentoEtapaHistorico();
            $etapa->setAgendamento($ag);
            $etapa->setEtapa('medico');
            $etapa->setResponsavel($medico->getNome());
            $etapa->setSetorSala($sala);
            $this->em->persist($etapa);

            $logs[] = "CHAMADA NO TELÃO: {$ag->getPaciente()->getNomeExibicao()} para {$medico->getNome()} no {$chamada->getGuicheOuConsultorio()} (Tempo de Espera Total: {$ag->getTempoEsperaMinutos()} min)";
        }
        $this->em->flush();

        // 4. Transição pontual: Em Consulta -> Finalizado (1 por passo)
        $emConsulta = $this->agendamentoRepo->findBy(['status' => 'em_consulta'], ['id' => 'ASC'], 1);
        foreach ($emConsulta as $ag) {
            // Só finaliza se a consulta estiver em andamento há pelo menos 2 minutos para realismo
            $ag->setStatus('finalizado');
            $ag->setHorarioFimConsulta(clone $agora);
            $ag->setHorarioSaida(clone $agora);

            if ($ag->getMedico()) {
                $ag->getMedico()->setStatusAtividade('disponivel');
            }

            $etapa = new AtendimentoEtapaHistorico();
            $etapa->setAgendamento($ag);
            $etapa->setEtapa('finalizacao');
            $etapa->setDataHoraFim(clone $agora);
            $etapa->setResponsavel('Sistema Procordis');
            $this->em->persist($etapa);

            $logs[] = "Atendimento finalizado: {$ag->getPaciente()->getNomeExibicao()}";
        }
        $this->em->flush();

        return $logs;
    }

    /**
     * Reseta e limpa todos os dados da simulação.
     */
    public function resetarDadosSimulacao(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM chamada_telao');
        $conn->executeStatement('DELETE FROM senha_atendimento');
        $conn->executeStatement('DELETE FROM atendimento_etapa_historico');
        $conn->executeStatement('DELETE FROM agendamento');
        $conn->executeStatement('DELETE FROM paciente');
    }
}
