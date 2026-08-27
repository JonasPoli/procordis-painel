<?php

namespace App\Service;

use App\Entity\Agendamento;
use App\Entity\ChamadaTelao;
use App\Entity\ConfiguracaoIntegracao;
use App\Entity\Especialidade;
use App\Entity\LogSyncApi;
use App\Entity\Medico;
use App\Entity\Paciente;
use App\Entity\ProcedimentoSla;
use App\Entity\SenhaAtendimento;
use App\Entity\SetorSala;
use App\Entity\User;
use App\Repository\ConfiguracaoIntegracaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SystemHealthCheckService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConfiguracaoIntegracaoRepository $configRepo,
        private HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')] private string $projectDir
    ) {
    }

    /**
     * Executa todos os testes de diagnóstico e integridade do sistema.
     */
    public function executarTodosTestes(string $appBaseUrl = 'http://127.0.0.1:8001'): array
    {
        $inicioTotal = microtime(true);
        $testes = [];

        // 1. Grupo: Banco de Dados
        $testes['database'] = $this->testarBancoDados();

        // 2. Grupo: API Medware Procordis
        $testes['medware_api'] = $this->testarApiMedware();

        // 3. Grupo: Endpoints dos Painéis
        $testes['painel_endpoints'] = $this->testarEndpointsPaineis($appBaseUrl);

        // 4. Grupo: Servidor & Ambiente
        $testes['environment'] = $this->testarAmbienteServidor();

        // 5. Grupo: Métricas e Volume de Dados
        $testes['data_stats'] = $this->obterEstatisticasBanco();

        $tempoTotalMs = (int) ((microtime(true) - $inicioTotal) * 1000);

        // Resumo Geral
        $totalTestes = 0;
        $totalSucesso = 0;
        $totalAvisos = 0;
        $totalErros = 0;

        foreach ($testes as $grupo => $itens) {
            if ($grupo === 'data_stats') continue;
            foreach ($itens as $item) {
                $totalTestes++;
                if (($item['status'] ?? '') === 'success') $totalSucesso++;
                elseif (($item['status'] ?? '') === 'warning') $totalAvisos++;
                else $totalErros++;
            }
        }

        $saudeGeral = 'excelente';
        if ($totalErros > 0) $saudeGeral = 'critico';
        elseif ($totalAvisos > 0) $saudeGeral = 'alerta';

        return [
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'tempoTotalMs' => $tempoTotalMs,
            'saudeGeral' => $saudeGeral,
            'resumo' => [
                'total' => $totalTestes,
                'sucesso' => $totalSucesso,
                'avisos' => $totalAvisos,
                'erros' => $totalErros,
                'porcentagemSaude' => $totalTestes > 0 ? (int) round(($totalSucesso / $totalTestes) * 100) : 100,
            ],
            'grupos' => $testes,
        ];
    }

    /**
     * Testa a conectividade, latência e tabelas do banco de dados MySQL.
     */
    public function testarBancoDados(): array
    {
        $resultados = [];
        $conn = $this->em->getConnection();

        // Teste 1: Ping / Conexão SQL simples
        $inicio = microtime(true);
        try {
            $version = $conn->fetchOne('SELECT VERSION()');
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);
            $resultados[] = [
                'id' => 'db_ping',
                'nome' => 'Conexão Doctrine MySQL',
                'descricao' => 'Verifica se a conexão ativa com o servidor de banco de dados responde.',
                'status' => 'success',
                'tempoMs' => $tempoMs,
                'detalhes' => "Conexão estabelecida com sucesso. Versão MySQL: {$version}",
                'dados' => ['versao' => $version, 'driver' => $conn->getDriver()::class]
            ];
        } catch (\Throwable $e) {
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);
            $resultados[] = [
                'id' => 'db_ping',
                'nome' => 'Conexão Doctrine MySQL',
                'descricao' => 'Verifica se a conexão com o banco de dados responde.',
                'status' => 'error',
                'tempoMs' => $tempoMs,
                'detalhes' => 'Falha ao conectar no banco de dados: ' . $e->getMessage(),
                'dados' => null
            ];
        }

        // Teste 2: Integridade de Tabelas Principais
        $tabelas = [
            'agendamento', 'paciente', 'medico', 'especialidade',
            'setor_sala', 'chamada_telao', 'senha_atendimento',
            'procedimento_sla', 'configuracao_integracao', 'log_sync_api', 'user'
        ];

        $tabelasOk = [];
        $tabelasErro = [];
        $inicio = microtime(true);
        try {
            $schemaManager = $conn->createSchemaManager();
            $tabelasExistentes = array_map(fn($t) => $t->getName(), $schemaManager->listTables());

            foreach ($tabelas as $t) {
                if (in_array($t, $tabelasExistentes, true)) {
                    $count = (int) $conn->fetchOne("SELECT COUNT(*) FROM `{$t}`");
                    $tabelasOk[$t] = $count;
                } else {
                    $tabelasErro[] = $t;
                }
            }

            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);
            $status = count($tabelasErro) === 0 ? 'success' : 'error';
            $msg = count($tabelasErro) === 0
                ? "Todas as " . count($tabelas) . " tabelas principais existem e estão acessíveis."
                : "Tabelas ausentes: " . implode(', ', $tabelasErro);

            $resultados[] = [
                'id' => 'db_tabelas',
                'nome' => 'Tabelas & Estrutura Schema',
                'descricao' => 'Verifica a existência de todas as tabelas do modelo de dados.',
                'status' => $status,
                'tempoMs' => $tempoMs,
                'detalhes' => $msg,
                'dados' => $tabelasOk
            ];
        } catch (\Throwable $e) {
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);
            $resultados[] = [
                'id' => 'db_tabelas',
                'nome' => 'Tabelas & Estrutura Schema',
                'descricao' => 'Verifica a existência de todas as tabelas do modelo de dados.',
                'status' => 'error',
                'tempoMs' => $tempoMs,
                'detalhes' => 'Erro ao checar tabelas: ' . $e->getMessage(),
                'dados' => null
            ];
        }

        return $resultados;
    }

    /**
     * Testa autenticação, latência e busca de registros da API Medware.
     */
    public function testarApiMedware(): array
    {
        $resultados = [];
        $config = $this->configRepo->getObterOuCriarConfiguracao();
        $baseUrl = rtrim($config->getApiBaseUrl(), '/');
        if (!str_ends_with(strtolower($baseUrl), '/api')) {
            $baseUrl .= '/api';
        }

        $usuario = $config->getApiUsuario();
        $senha = $config->getApiSenha();
        $token = null;

        // Teste 1: Conectividade Base HTTP/HTTPS
        $inicio = microtime(true);
        try {
            $rootUrl = preg_replace('/\/api$/i', '', $baseUrl);
            $response = $this->httpClient->request('GET', $rootUrl, [
                'timeout' => 5.0,
                'verify_peer' => false,
                'verify_host' => false,
            ]);
            $statusCode = $response->getStatusCode();
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);

            $resultados[] = [
                'id' => 'medware_host',
                'nome' => 'Disponibilidade do Host API (DNS / HTTPS)',
                'descricao' => 'Verifica se o servidor ' . parse_url($rootUrl, PHP_URL_HOST) . ' está acessível na internet.',
                'status' => ($statusCode >= 200 && $statusCode < 400) ? 'success' : 'warning',
                'tempoMs' => $tempoMs,
                'detalhes' => "Servidor respondeu com código HTTP {$statusCode}.",
                'dados' => ['url' => $rootUrl, 'httpStatus' => $statusCode, 'server' => $response->getHeaders(false)['server'][0] ?? 'IIS/Nginx']
            ];
        } catch (\Throwable $e) {
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);
            $resultados[] = [
                'id' => 'medware_host',
                'nome' => 'Disponibilidade do Host API',
                'descricao' => 'Verifica se o servidor está acessível na internet.',
                'status' => 'error',
                'tempoMs' => $tempoMs,
                'detalhes' => 'Falha de comunicação: ' . $e->getMessage(),
                'dados' => null
            ];
        }

        // Teste 2: Autenticação /Acesso/login
        $inicio = microtime(true);
        try {
            $loginUrl = $baseUrl . '/Acesso/login';
            $response = $this->httpClient->request('POST', $loginUrl, [
                'json' => [
                    'identificacao' => $usuario,
                    'senha' => $senha
                ],
                'timeout' => 6.0,
                'verify_peer' => false,
                'verify_host' => false,
            ]);
            $statusCode = $response->getStatusCode();
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);

            if ($statusCode === 200) {
                $data = $response->toArray(false);
                $token = $data['token'] ?? null;
                $codUsuario = $data['codUsuario'] ?? null;

                $resultados[] = [
                    'id' => 'medware_auth',
                    'nome' => 'Autenticação Medware (/api/Acesso/login)',
                    'descricao' => 'Autentica com as credenciais do usuário "' . $usuario . '" e gera o token JWT.',
                    'status' => $token ? 'success' : 'warning',
                    'tempoMs' => $tempoMs,
                    'detalhes' => $token ? "Autenticação bem-sucedida! CodUsuario: {$codUsuario}, Token JWT recebido." : "Login 200 mas token ausente.",
                    'dados' => [
                        'usuario' => $usuario,
                        'codUsuario' => $codUsuario,
                        'tokenPreview' => $token ? substr($token, 0, 25) . '...' : null,
                        'refreshToken' => $data['refreshToken'] ?? null
                    ]
                ];
            } else {
                $resultados[] = [
                    'id' => 'medware_auth',
                    'nome' => 'Autenticação Medware (/api/Acesso/login)',
                    'descricao' => 'Autentica com as credenciais configuradas.',
                    'status' => 'error',
                    'tempoMs' => $tempoMs,
                    'detalhes' => "Falha na autenticação. Código HTTP: {$statusCode}",
                    'dados' => ['usuario' => $usuario, 'httpStatus' => $statusCode]
                ];
            }
        } catch (\Throwable $e) {
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);
            $resultados[] = [
                'id' => 'medware_auth',
                'nome' => 'Autenticação Medware (/api/Acesso/login)',
                'descricao' => 'Autentica com as credenciais configuradas.',
                'status' => 'error',
                'tempoMs' => $tempoMs,
                'detalhes' => 'Erro ao autenticar: ' . $e->getMessage(),
                'dados' => null
            ];
        }

        // Teste 3: Consulta de Agendamentos /Medware/Agendamento/Listar
        if ($token) {
            $inicio = microtime(true);
            try {
                $hoje = (new \DateTime())->format('d/m/Y');
                $listarUrl = $baseUrl . '/Medware/Agendamento/Listar';
                $response = $this->httpClient->request('GET', $listarUrl, [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'query' => [
                        'dataInicio' => $hoje,
                        'dataFim' => $hoje,
                        'pageSize' => 500
                    ],
                    'timeout' => 8.0,
                    'verify_peer' => false,
                    'verify_host' => false,
                ]);

                $statusCode = $response->getStatusCode();
                $tempoMs = (int) ((microtime(true) - $inicio) * 1000);

                if ($statusCode === 200) {
                    $items = $response->toArray(false);
                    $total = count($items);
                    $resultados[] = [
                        'id' => 'medware_listar',
                        'nome' => 'Consulta de Agendamentos (/Medware/Agendamento/Listar)',
                        'descricao' => 'Consulta agendamentos, pacientes e médicos em tempo real para a data de hoje (' . $hoje . ').',
                        'status' => 'success',
                        'tempoMs' => $tempoMs,
                        'detalhes' => "Consulta executada com sucesso! {$total} agendamentos retornados da clínica Procordis para hoje.",
                        'dados' => [
                            'dataConsultada' => $hoje,
                            'totalRegistros' => $total,
                            'amostraPrimeiroRegistro' => $items[0] ? [
                                'codAgendamento' => $items[0]['codAgendamento'] ?? null,
                                'paciente' => $items[0]['paciente']['nome'] ?? null,
                                'medico' => $items[0]['medico']['nome'] ?? null,
                                'procedimento' => $items[0]['procedimentoPlanoOperadora']['descricaoProcedimento'] ?? null,
                                'dataHoraAgendada' => $items[0]['dataHoraAgendada'] ?? null,
                            ] : null
                        ]
                    ];
                } else {
                    $resultados[] = [
                        'id' => 'medware_listar',
                        'nome' => 'Consulta de Agendamentos (/Medware/Agendamento/Listar)',
                        'descricao' => 'Consulta agendamentos em tempo real.',
                        'status' => 'error',
                        'tempoMs' => $tempoMs,
                        'detalhes' => "Erro na consulta. Código HTTP: {$statusCode}",
                        'dados' => ['httpStatus' => $statusCode]
                    ];
                }
            } catch (\Throwable $e) {
                $tempoMs = (int) ((microtime(true) - $inicio) * 1000);
                $resultados[] = [
                    'id' => 'medware_listar',
                    'nome' => 'Consulta de Agendamentos (/Medware/Agendamento/Listar)',
                    'descricao' => 'Consulta agendamentos em tempo real.',
                    'status' => 'error',
                    'tempoMs' => $tempoMs,
                    'detalhes' => 'Erro ao consultar agendamentos: ' . $e->getMessage(),
                    'dados' => null
                ];
            }
        }

        return $resultados;
    }

    /**
     * Testa todos os endpoints internos da API dos painéis.
     */
    public function testarEndpointsPaineis(string $appBaseUrl = 'http://127.0.0.1:8001'): array
    {
        $endpoints = [
            [
                'id' => 'endpoint_espera',
                'nome' => 'Endpoint: Painel Geral de Espera',
                'rota' => '/api/v1/painel/espera',
                'chaveValidacao' => 'pacientes'
            ],
            [
                'id' => 'endpoint_chamada',
                'nome' => 'Endpoint: Painel de Chamada (Telão TV)',
                'rota' => '/api/v1/painel/chamada/ultimas',
                'chaveValidacao' => 'todasChamadas'
            ],
            [
                'id' => 'endpoint_medicos',
                'nome' => 'Endpoint: Painel dos Médicos',
                'rota' => '/api/v1/painel/medicos',
                'chaveValidacao' => 'medicos'
            ],
            [
                'id' => 'endpoint_triagem',
                'nome' => 'Endpoint: Painel de Triagem',
                'rota' => '/api/v1/painel/triagem',
                'chaveValidacao' => 'totalFilaTriagem'
            ],
            [
                'id' => 'endpoint_dashboard',
                'nome' => 'Endpoint: Dashboard Executivo',
                'rota' => '/api/v1/painel/dashboard',
                'chaveValidacao' => 'resumo'
            ],
            [
                'id' => 'endpoint_aguardando',
                'nome' => 'Endpoint: Painel de Aguardando (SLA)',
                'rota' => '/api/v1/painel/aguardando',
                'chaveValidacao' => 'kpis'
            ],
            [
                'id' => 'endpoint_finalizados',
                'nome' => 'Endpoint: Painel de Finalizados (Pós-Atendimento)',
                'rota' => '/api/v1/painel/finalizados',
                'chaveValidacao' => 'graficos'
            ],
            [
                'id' => 'endpoint_sla_config',
                'nome' => 'Endpoint: Configurações de SLA',
                'rota' => '/api/v1/painel/sla-config',
                'chaveValidacao' => 'regrasSla'
            ],
        ];

        $resultados = [];
        $appBaseUrl = rtrim($appBaseUrl, '/');

        foreach ($endpoints as $ep) {
            $inicio = microtime(true);
            $url = $appBaseUrl . $ep['rota'];

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 4.0,
                    'headers' => ['Accept' => 'application/json']
                ]);

                $statusCode = $response->getStatusCode();
                $tempoMs = (int) ((microtime(true) - $inicio) * 1000);

                if ($statusCode === 200) {
                    $json = $response->toArray(false);
                    $sucesso = $json['sucesso'] ?? false;
                    $temChave = isset($json[$ep['chaveValidacao']]);

                    if ($sucesso && $temChave) {
                        $resultados[] = [
                            'id' => $ep['id'],
                            'nome' => $ep['nome'],
                            'descricao' => "Rota: {$ep['rota']}",
                            'status' => 'success',
                            'tempoMs' => $tempoMs,
                            'detalhes' => "200 OK | JSON válido retornado em {$tempoMs}ms.",
                            'dados' => [
                                'rota' => $ep['rota'],
                                'httpStatus' => 200,
                                'timestamp' => $json['timestamp'] ?? null,
                                'resumoContagem' => is_array($json[$ep['chaveValidacao']] ?? null) ? count($json[$ep['chaveValidacao']]) : $json[$ep['chaveValidacao']]
                            ]
                        ];
                    } else {
                        $resultados[] = [
                            'id' => $ep['id'],
                            'nome' => $ep['nome'],
                            'descricao' => "Rota: {$ep['rota']}",
                            'status' => 'warning',
                            'tempoMs' => $tempoMs,
                            'detalhes' => "200 OK porém estrutura JSON não contém '{$ep['chaveValidacao']}'.",
                            'dados' => ['json' => $json]
                        ];
                    }
                } else {
                    $resultados[] = [
                        'id' => $ep['id'],
                        'nome' => $ep['nome'],
                        'descricao' => "Rota: {$ep['rota']}",
                        'status' => 'error',
                        'tempoMs' => $tempoMs,
                        'detalhes' => "Erro HTTP {$statusCode} ao consultar rota.",
                        'dados' => ['httpStatus' => $statusCode]
                    ];
                }
            } catch (\Throwable $e) {
                $tempoMs = (int) ((microtime(true) - $inicio) * 1000);
                $resultados[] = [
                    'id' => $ep['id'],
                    'nome' => $ep['nome'],
                    'descricao' => "Rota: {$ep['rota']}",
                    'status' => 'error',
                    'tempoMs' => $tempoMs,
                    'detalhes' => 'Exceção: ' . $e->getMessage(),
                    'dados' => null
                ];
            }
        }

        return $resultados;
    }

    /**
     * Testa variáveis de ambiente, diretórios e extensões do PHP.
     */
    public function testarAmbienteServidor(): array
    {
        $resultados = [];

        // 1. Versão e Extensões PHP
        $extNecessarias = ['pdo_mysql', 'curl', 'mbstring', 'intl', 'json', 'openssl', 'ctype', 'iconv'];
        $extAusentes = array_filter($extNecessarias, fn($ext) => !extension_loaded($ext));
        $resultados[] = [
            'id' => 'env_php',
            'nome' => 'Ambiente PHP ' . PHP_VERSION,
            'descricao' => 'Verifica se as extensões vitais do PHP estão carregadas.',
            'status' => count($extAusentes) === 0 ? 'success' : 'error',
            'tempoMs' => 1,
            'detalhes' => count($extAusentes) === 0
                ? "Todas as extensões (" . implode(', ', $extNecessarias) . ") estão ativas."
                : "Extensões ausentes: " . implode(', ', $extAusentes),
            'dados' => [
                'phpVersion' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'memoryLimit' => ini_get('memory_limit'),
                'maxExecutionTime' => ini_get('max_execution_time') . 's'
            ]
        ];

        // 2. Permissões de Escrita nos Diretórios
        $diretorios = [
            'var/cache' => $this->projectDir . '/var/cache',
            'var/log' => $this->projectDir . '/var/log',
            'public' => $this->projectDir . '/public',
        ];

        $dirsSemPermissao = [];
        foreach ($diretorios as $nome => $path) {
            if (!is_dir($path) || !is_writable($path)) {
                $dirsSemPermissao[] = $nome;
            }
        }

        $resultados[] = [
            'id' => 'env_permissoes',
            'nome' => 'Permissões de Escrita em Diretórios',
            'descricao' => 'Verifica se os diretórios var/cache, var/log e public possuem permissão de gravação.',
            'status' => count($dirsSemPermissao) === 0 ? 'success' : 'error',
            'tempoMs' => 1,
            'detalhes' => count($dirsSemPermissao) === 0
                ? "Diretórios de cache, log e public possuem permissão de escrita (OK)."
                : "Diretórios sem permissão de escrita: " . implode(', ', $dirsSemPermissao),
            'dados' => $diretorios
        ];

        return $resultados;
    }

    /**
     * Obtém contadores e estatísticas gerais do banco de dados.
     */
    public function obterEstatisticasBanco(): array
    {
        $conn = $this->em->getConnection();
        $hoje = (new \DateTime())->format('Y-m-d');

        try {
            $totalAgendamentos = (int) $conn->fetchOne("SELECT COUNT(*) FROM agendamento");
            $agendamentosHoje = (int) $conn->fetchOne("SELECT COUNT(*) FROM agendamento WHERE DATE(data_hora_agendada) = '{$hoje}' OR DATE(horario_chegada) = '{$hoje}'");
            $totalPacientes = (int) $conn->fetchOne("SELECT COUNT(*) FROM paciente");
            $totalMedicos = (int) $conn->fetchOne("SELECT COUNT(*) FROM medico");
            $totalChamadas = (int) $conn->fetchOne("SELECT COUNT(*) FROM chamada_telao");
            $totalLogsApi = (int) $conn->fetchOne("SELECT COUNT(*) FROM log_sync_api");

            $config = $this->configRepo->getObterOuCriarConfiguracao();

            return [
                'totalAgendamentos' => $totalAgendamentos,
                'agendamentosHoje' => $agendamentosHoje,
                'totalPacientes' => $totalPacientes,
                'totalMedicos' => $totalMedicos,
                'totalChamadas' => $totalChamadas,
                'totalLogsApi' => $totalLogsApi,
                'modoSimulacao' => $config->isModoSimulacao(),
                'statusConexao' => $config->getStatusConexao(),
                'ultimoSyncEm' => $config->getUltimoSyncEm() ? $config->getUltimoSyncEm()->format('d/m/Y H:i:s') : 'Nunca',
                'apiBaseUrl' => $config->getApiBaseUrl(),
                'apiUsuario' => $config->getApiUsuario(),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
