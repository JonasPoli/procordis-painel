<?php

namespace App\Service;

use App\Entity\Agendamento;
use App\Entity\AtendimentoEtapaHistorico;
use App\Entity\ChamadaTelao;
use App\Entity\Especialidade;
use App\Entity\LogSyncApi;
use App\Entity\Medico;
use App\Entity\Paciente;
use App\Entity\ProcedimentoSla;
use App\Entity\SenhaAtendimento;
use App\Entity\Unidade;
use App\Repository\AgendamentoRepository;
use App\Repository\ChamadaTelaoRepository;
use App\Repository\ConfiguracaoIntegracaoRepository;
use App\Repository\EspecialidadeRepository;
use App\Repository\MedicoRepository;
use App\Repository\PacienteRepository;
use App\Repository\ProcedimentoSlaRepository;
use App\Repository\SenhaAtendimentoRepository;
use App\Repository\UnidadeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MedwareApiClientService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ConfiguracaoIntegracaoRepository $configRepository,
        private EntityManagerInterface $em,
        private AgendamentoRepository $agendamentoRepo,
        private PacienteRepository $pacienteRepo,
        private MedicoRepository $medicoRepo,
        private EspecialidadeRepository $especialidadeRepo,
        private UnidadeRepository $unidadeRepo,
        private ProcedimentoSlaRepository $slaRepo,
        private SenhaAtendimentoRepository $senhaRepo,
        private ChamadaTelaoRepository $chamadaRepo
    ) {
    }

    private function getApiUrl(string $path): string
    {
        $config = $this->configRepository->getObterOuCriarConfiguracao();
        $baseUrl = rtrim($config->getApiBaseUrl(), '/');
        if (!str_ends_with(strtolower($baseUrl), '/api')) {
            $baseUrl .= '/api';
        }
        return $baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Tenta autenticar na API Medware (/Acesso/login).
     */
    public function autenticar(): bool
    {
        $config = $this->configRepository->getObterOuCriarConfiguracao();
        if ($config->isModoSimulacao()) {
            $config->setStatusConexao('modo_simulacao');
            $this->em->flush();
            return true;
        }

        $usuario = $config->getApiUsuario();
        $senha = $config->getApiSenha();

        if (empty($usuario) || empty($senha)) {
            $config->setStatusConexao('credenciais_ausentes');
            $this->em->flush();
            return false;
        }

        $inicio = microtime(true);
        try {
            $url = $this->getApiUrl('/Acesso/login');
            $response = $this->httpClient->request('POST', $url, [
                'json' => [
                    'identificacao' => $usuario,
                    'senha' => $senha
                ],
                'timeout' => 8.0,
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            $status = $response->getStatusCode();
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);

            if ($status === 200) {
                $data = $response->toArray();
                $token = $data['token'] ?? null;
                if ($token) {
                    $config->setApiToken($token);
                    $config->setStatusConexao('conectado');
                    $config->setUltimoSyncEm(new \DateTime());
                    $this->registrarLog('/Acesso/login', 'POST', 200, $tempoMs, null, 1);
                    $this->em->flush();
                    return true;
                }
            }

            $config->setStatusConexao('erro_autenticacao');
            $this->registrarLog('/Acesso/login', 'POST', $status, $tempoMs, 'Token não retornado', 0);
            $this->em->flush();
            return false;
        } catch (\Throwable $e) {
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);
            $config->setStatusConexao('falha_conexao');
            $this->registrarLog('/Acesso/login', 'POST', 500, $tempoMs, $e->getMessage(), 0);
            $this->em->flush();
            return false;
        }
    }

    /**
     * Consulta agendamentos da API Medware (/Medware/Agendamento/Listar).
     */
    public function listarAgendamentos(\DateTimeInterface $dataInicio, \DateTimeInterface $dataFim): array
    {
        $config = $this->configRepository->getObterOuCriarConfiguracao();
        if ($config->isModoSimulacao()) {
            return [];
        }

        if (!$config->getApiToken()) {
            if (!$this->autenticar()) {
                return [];
            }
        }

        $inicio = microtime(true);
        try {
            $url = $this->getApiUrl('/Medware/Agendamento/Listar');
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $config->getApiToken()
                ],
                'query' => [
                    'dataInicio' => $dataInicio->format('d/m/Y'),
                    'dataFim' => $dataFim->format('d/m/Y'),
                    'pageSize' => 500
                ],
                'timeout' => 12.0,
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            $status = $response->getStatusCode();
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);

            if ($status === 200) {
                $items = $response->toArray();
                $this->registrarLog('/Medware/Agendamento/Listar', 'GET', 200, $tempoMs, null, count($items));
                return $items;
            }

            // Se deu 401 Unauthorized, tenta re-autenticar uma vez
            if ($status === 401) {
                if ($this->autenticar()) {
                    return $this->listarAgendamentos($dataInicio, $dataFim);
                }
            }

            $this->registrarLog('/Medware/Agendamento/Listar', 'GET', $status, $tempoMs, 'Status HTTP: ' . $status, 0);
            return [];
        } catch (\Throwable $e) {
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);
            $this->registrarLog('/Medware/Agendamento/Listar', 'GET', 500, $tempoMs, $e->getMessage(), 0);
            return [];
        }
    }

    /**
     * Sincroniza os agendamentos da data com a base local.
     */
    public function sincronizarAgendamentosHoje(?\DateTimeInterface $data = null): array
    {
        $data = $data ?? new \DateTime();
        $items = $this->listarAgendamentos($data, $data);

        if (empty($items)) {
            return ['total' => 0, 'novos' => 0, 'atualizados' => 0, 'erro' => 'Nenhum registro retornado ou erro de conexão'];
        }

        $unidade = $this->unidadeRepo->findOneBy([]) ?? new Unidade();
        if (!$unidade->getId()) {
            $unidade->setNome('Procordis Centro Médico');
            $unidade->setCodigoExterno('UNI-01');
            $this->em->persist($unidade);
            $this->em->flush();
        }

        $novos = 0;
        $atualizados = 0;

        foreach ($items as $item) {
            $codAgendamento = (string) ($item['codAgendamento'] ?? '');
            if (empty($codAgendamento)) {
                continue;
            }

            // 1. Paciente
            $pacienteData = $item['paciente'] ?? [];
            $codPac = (string) ($pacienteData['codPaciente'] ?? '');
            $nomePac = trim($pacienteData['nome'] ?? 'Paciente ' . $codAgendamento);
            $cpfPac = trim($pacienteData['cpf'] ?? '');

            $paciente = null;
            if (!empty($codPac)) {
                $paciente = $this->pacienteRepo->findOneBy(['codigoExterno' => 'PAC-' . $codPac]);
            }
            if (!$paciente && !empty($cpfPac)) {
                $paciente = $this->pacienteRepo->findOneBy(['cpf' => $cpfPac]);
            }
            if (!$paciente && !empty($nomePac)) {
                $paciente = $this->pacienteRepo->findOneBy(['nomeCompleto' => $nomePac]);
            }
            if (!$paciente) {
                $paciente = new Paciente();
                $paciente->setNomeCompleto($nomePac);
                $paciente->setCodigoExterno(!empty($codPac) ? 'PAC-' . $codPac : 'PAC-' . rand(10000, 99999));
                $this->em->persist($paciente);
            }

            if (!empty($cpfPac)) {
                $paciente->setCpf($cpfPac);
            }
            if (!empty($pacienteData['telefone'])) {
                $paciente->setCelular(trim($pacienteData['telefone']));
            }
            if (!empty($pacienteData['sexo'])) {
                $paciente->setSexo(trim($pacienteData['sexo']));
            }
            if (!empty($pacienteData['dataNascimento'])) {
                $dtNasc = $this->parseDateTime($pacienteData['dataNascimento']);
                if ($dtNasc) {
                    $paciente->setDataNascimento($dtNasc);
                }
            }

            // 2. Médico
            $medicoData = $item['medico'] ?? [];
            $codMed = (string) ($medicoData['codMedico'] ?? '');
            $nomeMed = trim($medicoData['nome'] ?? '');
            $crmMed = trim($medicoData['numeroConselho'] ?? '');
            $ufMed = trim($medicoData['ufConselho'] ?? '');
            $espMedStr = trim($medicoData['especialidade'] ?? '');

            $medico = null;
            if (!empty($nomeMed)) {
                if (!empty($codMed)) {
                    $medico = $this->medicoRepo->findOneBy(['codigoExterno' => 'MED-' . $codMed]);
                }
                if (!$medico) {
                    $medico = $this->medicoRepo->findOneBy(['nome' => $nomeMed]);
                }
                if (!$medico) {
                    $medico = new Medico();
                    $medico->setNome($nomeMed);
                    $medico->setCodigoExterno(!empty($codMed) ? 'MED-' . $codMed : 'MED-' . rand(1000, 9999));
                    $medico->addUnidade($unidade);
                    $this->em->persist($medico);
                }

                if (!empty($crmMed)) {
                    $medico->setCrm('CRM-' . ($ufMed ? $ufMed . ' ' : '') . $crmMed);
                }

                if (!empty($espMedStr)) {
                    $esp = $this->especialidadeRepo->findOneBy(['nome' => $espMedStr]);
                    if (!$esp) {
                        $esp = new Especialidade();
                        $esp->setNome($espMedStr);
                        $esp->setCodigoExterno('ESP-' . rand(100, 999));
                        $this->em->persist($esp);
                    }
                    $medico->setEspecialidade($esp);
                }
            }

            // 3. Procedimento & Convênio/Plano
            $procData = $item['procedimentoPlanoOperadora'] ?? [];
            $procNome = trim($procData['descricaoProcedimento'] ?? 'Consulta / Exame');
            $convenioNome = trim($procData['descricaoPlanoOperadora'] ?? $procData['descricaoOperadora'] ?? 'SUS - Sistema Único de Saúde');
            $tipoAt = 'sus';
            if (str_contains(mb_strtolower($convenioNome), 'filantrop')) {
                $tipoAt = 'filantropico';
            } elseif (!str_contains(mb_strtolower($convenioNome), 'sus')) {
                $tipoAt = 'convenio';
            }

            // 4. Agendamento
            $agendamento = $this->agendamentoRepo->findOneBy(['codigoAgendamento' => $codAgendamento]);
            if (!$agendamento) {
                $agendamento = new Agendamento();
                $agendamento->setCodigoAgendamento($codAgendamento);
                $agendamento->setPaciente($paciente);
                $agendamento->setUnidade($unidade);
                $this->em->persist($agendamento);
                $novos++;
            } else {
                $atualizados++;
            }

            $agendamento->setPaciente($paciente);
            if ($medico) {
                $agendamento->setMedico($medico);
                $agendamento->setEspecialidade($medico->getEspecialidade());
            }
            $agendamento->setProcedimentoNome($procNome);
            $agendamento->setConvenioNome($convenioNome);
            $agendamento->setTipoAtendimento($tipoAt);
            $agendamento->setEncaixe((bool) ($item['encaixe'] ?? 0));

            // Horários
            $dtAgendada = $this->parseDateTime($item['dataHoraAgendada'] ?? null);
            if ($dtAgendada) {
                $agendamento->setDataHoraAgendada($dtAgendada);
            }
            $dtConfirmado = $this->parseDateTime($item['dataHoraConfirmado'] ?? null);
            if ($dtConfirmado) {
                $agendamento->setHorarioConfirmacao($dtConfirmado);
            }
            $dtChegada = $this->parseDateTime($item['dataHoraChegada'] ?? null);
            if ($dtChegada) {
                $agendamento->setHorarioChegada($dtChegada);
            }
            $dtLiberacao = $this->parseDateTime($item['dataHoraLiberacao'] ?? null);
            if ($dtLiberacao) {
                $agendamento->setHorarioSaida($dtLiberacao);
                $agendamento->setHorarioFimConsulta($dtLiberacao);
            }

            // Status mapping
            $rawStatus = strtoupper(trim((string) ($item['status'] ?? '')));
            $codStatus = (int) ($item['codStatusAgendamento'] ?? 0);

            if (str_contains($rawStatus, 'CANCELADO') || $codStatus === 1) {
                $agendamento->setStatus('cancelado');
            } elseif ($dtLiberacao) {
                $agendamento->setStatus('finalizado');
            } elseif ($dtChegada) {
                if ($codStatus === 4) {
                    $agendamento->setStatus('em_consulta');
                    if (!$agendamento->getHorarioInicioConsulta()) {
                        $agendamento->setHorarioInicioConsulta(new \DateTime());
                    }
                } elseif ($codStatus === 3) {
                    $agendamento->setStatus('aguardando_medico');
                } else {
                    $agendamento->setStatus('aguardando_triagem');
                }
            } else {
                $agendamento->setStatus('agendado');
            }

            // Senha e Chamadas para alimentar o Telão e Painéis
            if ($dtChegada) {
                $senha = $this->senhaRepo->findOneBy(['paciente' => $paciente]);
                if (!$senha) {
                    $senha = new SenhaAtendimento();
                    $prefixo = $agendamento->isPrioridade() ? 'P' : 'N';
                    $senha->setNumeroFormatado($prefixo . str_pad((string) (($agendamento->getId() ?? rand(1, 99)) % 99 + 1), 3, '0', STR_PAD_LEFT));
                    $senha->setTipoSenha($agendamento->isPrioridade() ? 'preferencial' : 'normal');
                    $senha->setPaciente($paciente);
                    $senha->setStatus($dtLiberacao ? 'finalizada' : 'gerada');
                    $this->em->persist($senha);
                }

                if ($agendamento->getStatus() === 'em_consulta' || $agendamento->getStatus() === 'aguardando_medico') {
                    $chamada = $this->chamadaRepo->findOneBy(['agendamento' => $agendamento]);
                    if (!$chamada) {
                        $chamada = new ChamadaTelao();
                        $chamada->setAgendamento($agendamento);
                        $chamada->setPacienteNomeMascarado($paciente->getNomeExibicao() ?? 'Paciente');
                        $chamada->setMedico($medico);
                        $chamada->setSenha($senha);
                        $chamada->setGuicheOuConsultorio('Consultório ' . ($medico ? mb_substr($medico->getNome(), 0, 15) : '01'));
                        $chamada->setDataHoraChamada($agendamento->getHorarioInicioConsulta() ?? $agendamento->getHorarioChegada() ?? new \DateTime());
                        $this->em->persist($chamada);
                    }
                }
            }
        }

        $this->em->flush();

        $config = $this->configRepository->getObterOuCriarConfiguracao();
        $config->setUltimoSyncEm(new \DateTime());
        $this->em->flush();

        return [
            'total' => count($items),
            'novos' => $novos,
            'atualizados' => $atualizados,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
        ];
    }

    private function parseDateTime(?string $dateStr): ?\DateTime
    {
        if (empty($dateStr) || trim($dateStr) === '') {
            return null;
        }
        $dateStr = trim($dateStr);
        $dt = \DateTime::createFromFormat('d/m/Y H:i:s', $dateStr);
        if ($dt) {
            return $dt;
        }
        $dt = \DateTime::createFromFormat('d/m/Y H:i', $dateStr);
        if ($dt) {
            return $dt;
        }
        $dt = \DateTime::createFromFormat('d/m/Y', $dateStr);
        if ($dt) {
            return $dt;
        }
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $dateStr);
        if ($dt) {
            return $dt;
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $dateStr);
        if ($dt) {
            return $dt;
        }
        return null;
    }

    /**
     * Sincroniza um período histórico completo paginado por intervalos de datas.
     */
    public function sincronizarPeriodoHistorico(\DateTimeInterface $dataInicio, \DateTimeInterface $dataFim): array
    {
        $cursorAtual = clone $dataInicio;
        $totalGeral = 0;
        $novosGeral = 0;
        $atualizadosGeral = 0;
        $diasProcessados = 0;

        // Itera dia a dia para garantir carga completa sem sobrecarregar a API
        while ($cursorAtual <= $dataFim) {
            $res = $this->sincronizarAgendamentosHoje($cursorAtual);
            if (!isset($res['erro'])) {
                $totalGeral += $res['total'] ?? 0;
                $novosGeral += $res['novos'] ?? 0;
                $atualizadosGeral += $res['atualizados'] ?? 0;
            }
            $diasProcessados++;
            $cursorAtual->modify('+1 day');
        }

        return [
            'total' => $totalGeral,
            'novos' => $novosGeral,
            'atualizados' => $atualizadosGeral,
            'diasProcessados' => $diasProcessados
        ];
    }

    private function registrarLog(string $endpoint, string $metodo, int $status, int $tempoMs, ?string $erro, int $count): void
    {
        $log = new LogSyncApi();
        $log->setEndpoint($endpoint);
        $log->setMetodo($metodo);
        $log->setHttpStatus($status);
        $log->setTempoRespostaMs($tempoMs);
        $log->setMensagemErro($erro);
        $log->setRegistrosProcessados($count);

        $this->em->persist($log);
    }
}
