<?php

namespace App\Service;

use App\Repository\ConfiguracaoIntegracaoRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente HTTP "cru" dos endpoints de agendamento usados no painel de atendimentos
 * (carga histórica e sondagem). Reaproveita a autenticação do MedwareApiClientService.
 *
 * Diferente dos outros clientes, NÃO grava em api_captura: quem chama decide onde guardar o corpo
 * (a carga histórica tem tabela própria, a sondagem não guarda nada).
 * Ver docs/integracao-medware-atendimentos.md.
 */
class MedwareAgendamentoApiClient
{
    public const ENDPOINT_LISTAR = '/Medware/Agendamento/Listar';
    public const ENDPOINT_LISTAR_RESUMIDO = '/Medware/Agendamento/ListarResumido';
    public const ENDPOINT_PROCEDIMENTOS = '/Medware/ProcedPlanoOp/Listar';
    public const FORMATO_DATA = 'd/m/Y';

    /** pageSize padrão documentado no Swagger. */
    public const PAGE_SIZE_PADRAO_API = 500;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ConfiguracaoIntegracaoRepository $configRepository,
        private MedwareApiClientService $medwareClient,
    ) {
    }

    public function isModoSimulacao(): bool
    {
        return $this->configRepository->getObterOuCriarConfiguracao()->isModoSimulacao();
    }

    /**
     * Agendamentos (ativos e cancelados) de um período pela data agendada.
     */
    public function listar(\DateTimeInterface $inicio, \DateTimeInterface $fim, int $pageSize, array $filtros = []): array
    {
        return $this->get(self::ENDPOINT_LISTAR, array_merge([
            'dataInicio' => $inicio->format(self::FORMATO_DATA),
            'dataFim' => $fim->format(self::FORMATO_DATA),
            'pageSize' => $pageSize,
        ], $filtros));
    }

    /**
     * GET autenticado com retentativa e renovação de token no 401.
     *
     * @return array{ok: bool, status: int, corpo: ?string, dados: mixed, erro: ?string, tempoMs: int, tentativas: int}
     */
    public function get(string $path, array $query, int $maxTentativas = 3, float $timeout = 120.0): array
    {
        if ($this->isModoSimulacao()) {
            return ['ok' => false, 'status' => 0, 'corpo' => null, 'dados' => null, 'erro' => 'Integração em modo simulação', 'tempoMs' => 0, 'tentativas' => 0];
        }

        $config = $this->configRepository->getObterOuCriarConfiguracao();
        if (!$config->getApiToken() && !$this->medwareClient->autenticar()) {
            return ['ok' => false, 'status' => 401, 'corpo' => null, 'dados' => null, 'erro' => 'Falha na autenticação da API', 'tempoMs' => 0, 'tentativas' => 0];
        }

        $ultimoErro = null;
        $status = 0;
        $inicio = microtime(true);
        for ($tentativa = 1; $tentativa <= $maxTentativas; $tentativa++) {
            try {
                $response = $this->httpClient->request('GET', $this->url($path), [
                    'headers' => ['Authorization' => 'Bearer ' . $config->getApiToken(), 'Accept' => 'application/json'],
                    'query' => $query,
                    'timeout' => $timeout,
                    'verify_peer' => false,
                    'verify_host' => false,
                ]);
                $status = $response->getStatusCode();

                if ($status === 401 && $tentativa < $maxTentativas) {
                    $this->medwareClient->autenticar();
                    $config = $this->configRepository->getObterOuCriarConfiguracao();
                    continue;
                }

                $corpo = $response->getContent(false);
                if ($status === 200) {
                    $dados = json_decode($corpo, true);
                    if (is_array($dados)) {
                        return ['ok' => true, 'status' => 200, 'corpo' => $corpo, 'dados' => $dados, 'erro' => null, 'tempoMs' => $this->ms($inicio), 'tentativas' => $tentativa];
                    }
                    $ultimoErro = 'Resposta não é JSON válido';
                } else {
                    $ultimoErro = 'HTTP ' . $status . ': ' . mb_substr($corpo, 0, 300);
                    if ($status === 429) {
                        $espera = (int) ($response->getHeaders(false)['retry-after'][0] ?? 0);
                        sleep(min(max($espera, 5), 120));
                    }
                }
            } catch (\Throwable $e) {
                $ultimoErro = $e->getMessage();
            }

            if ($tentativa < $maxTentativas) {
                usleep(1000000 * $tentativa);
            }
        }

        return ['ok' => false, 'status' => $status, 'corpo' => null, 'dados' => null, 'erro' => $path . ' → ' . $ultimoErro, 'tempoMs' => $this->ms($inicio), 'tentativas' => $maxTentativas];
    }

    private function url(string $path): string
    {
        $base = rtrim($this->configRepository->getObterOuCriarConfiguracao()->getApiBaseUrl(), '/');
        if (!str_ends_with(strtolower($base), '/api')) {
            $base .= '/api';
        }

        return $base . '/' . ltrim($path, '/');
    }

    private function ms(float $inicio): int
    {
        return (int) ((microtime(true) - $inicio) * 1000);
    }
}
