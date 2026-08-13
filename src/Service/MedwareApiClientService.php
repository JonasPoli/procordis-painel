<?php

namespace App\Service;

use App\Entity\LogSyncApi;
use App\Repository\ConfiguracaoIntegracaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MedwareApiClientService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ConfiguracaoIntegracaoRepository $configRepository,
        private EntityManagerInterface $em
    ) {
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

        $inicio = microtime(true);
        try {
            $response = $this->httpClient->request('POST', rtrim($config->getApiBaseUrl(), '/') . '/Acesso/login', [
                'json' => [
                    'identificacao' => $config->getApiUsuario(),
                    'senha' => $config->getApiSenha()
                ],
                'timeout' => 5.0
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
            $url = rtrim($config->getApiBaseUrl(), '/') . '/Medware/Agendamento/Listar';
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $config->getApiToken()
                ],
                'query' => [
                    'dataInicio' => $dataInicio->format('Y-m-d H:i:s'),
                    'dataFim' => $dataFim->format('Y-m-d H:i:s')
                ],
                'timeout' => 5.0
            ]);

            $status = $response->getStatusCode();
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);

            if ($status === 200) {
                $items = $response->toArray();
                $this->registrarLog('/Medware/Agendamento/Listar', 'GET', 200, $tempoMs, null, count($items));
                return $items;
            }

            $this->registrarLog('/Medware/Agendamento/Listar', 'GET', $status, $tempoMs, 'Resposta sem sucesso', 0);
            return [];
        } catch (\Throwable $e) {
            $tempoMs = (int) ((microtime(true) - $inicio) * 1000);
            $this->registrarLog('/Medware/Agendamento/Listar', 'GET', 500, $tempoMs, $e->getMessage(), 0);
            return [];
        }
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
