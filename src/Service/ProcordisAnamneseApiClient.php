<?php

namespace App\Service;

use App\Repository\ConfiguracaoIntegracaoRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente HTTP dos endpoints de anamnese (ClassificacaoEstudo) da API Procordis.
 * Reaproveita a autenticação/token do MedwareApiClientService e grava toda resposta na camada bruta.
 *
 * ATENÇÃO: os nomes dos parâmetros de período/paginação abaixo seguem o padrão do JSON de resposta
 * (pagina, tamanhoPagina). Se a API usar outros nomes ou outro formato de data, ajuste só estas constantes.
 */
class ProcordisAnamneseApiClient
{
    public const ENDPOINT_CATALOGO = '/ClassificacaoEstudo';
    public const ENDPOINT_PERIODO = '/Paciente/ClassificacoesEstudo';
    public const ENDPOINT_PACIENTE = '/Paciente/%d/ClassificacoesEstudo';

    public const PARAM_DATA_INICIO = 'dataInicio';
    public const PARAM_DATA_FIM = 'dataFim';
    public const PARAM_PAGINA = 'pagina';
    public const PARAM_TAMANHO_PAGINA = 'tamanhoPagina';
    public const FORMATO_DATA = 'd/m/Y';

    public const TAMANHO_PAGINA_PADRAO = 2000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ConfiguracaoIntegracaoRepository $configRepository,
        private MedwareApiClientService $medwareClient,
        private ApiCapturaService $captura,
    ) {
    }

    public function isModoSimulacao(): bool
    {
        return $this->configRepository->getObterOuCriarConfiguracao()->isModoSimulacao();
    }

    /**
     * Catálogo de classificações. Retorna a lista de itens (array de arrays).
     *
     * @return array{ok: bool, itens: array, erro: ?string}
     */
    public function listarCatalogo(): array
    {
        $r = $this->get(self::ENDPOINT_CATALOGO, []);
        if (!$r['ok']) {
            return ['ok' => false, 'itens' => [], 'erro' => $r['erro']];
        }

        return ['ok' => true, 'itens' => $this->extrairItens($r['dados']), 'erro' => null];
    }

    /**
     * Uma página de classificações por período.
     *
     * @return array{ok: bool, itens: array, total: ?int, pagina: int, tamanhoPagina: int, erro: ?string}
     */
    public function listarPorPeriodo(\DateTimeInterface $inicio, \DateTimeInterface $fim, int $pagina = 1, int $tamanhoPagina = self::TAMANHO_PAGINA_PADRAO): array
    {
        $r = $this->get(self::ENDPOINT_PERIODO, [
            self::PARAM_DATA_INICIO => $inicio->format(self::FORMATO_DATA),
            self::PARAM_DATA_FIM => $fim->format(self::FORMATO_DATA),
            self::PARAM_PAGINA => $pagina,
            self::PARAM_TAMANHO_PAGINA => $tamanhoPagina,
        ]);

        if (!$r['ok']) {
            return ['ok' => false, 'itens' => [], 'total' => null, 'pagina' => $pagina, 'tamanhoPagina' => $tamanhoPagina, 'erro' => $r['erro']];
        }

        $dados = $r['dados'];

        return [
            'ok' => true,
            'itens' => $this->extrairItens($dados),
            'total' => is_array($dados) && isset($dados['total']) ? (int) $dados['total'] : null,
            'pagina' => (int) ($dados['pagina'] ?? $pagina),
            'tamanhoPagina' => (int) ($dados['tamanhoPagina'] ?? $tamanhoPagina),
            'erro' => null,
        ];
    }

    /**
     * Classificações de todos os exames de um paciente.
     *
     * @return array{ok: bool, itens: array, erro: ?string}
     */
    public function listarPorPaciente(int $codPaciente): array
    {
        $r = $this->get(sprintf(self::ENDPOINT_PACIENTE, $codPaciente), []);

        return ['ok' => $r['ok'], 'itens' => $r['ok'] ? $this->extrairItens($r['dados']) : [], 'erro' => $r['erro']];
    }

    /**
     * @return array{ok: bool, dados: mixed, status: int, erro: ?string}
     */
    private function get(string $path, array $query, int $maxTentativas = 3): array
    {
        if ($this->isModoSimulacao()) {
            return ['ok' => false, 'dados' => null, 'status' => 0, 'erro' => 'Integração em modo simulação'];
        }

        $config = $this->configRepository->getObterOuCriarConfiguracao();
        if (!$config->getApiToken() && !$this->medwareClient->autenticar()) {
            return ['ok' => false, 'dados' => null, 'status' => 401, 'erro' => 'Falha na autenticação da API'];
        }

        $ultimoErro = null;
        $status = 0;
        for ($tentativa = 1; $tentativa <= $maxTentativas; $tentativa++) {
            try {
                $response = $this->httpClient->request('GET', $this->url($path), [
                    'headers' => ['Authorization' => 'Bearer ' . $config->getApiToken(), 'Accept' => 'application/json'],
                    'query' => $query,
                    'timeout' => 60.0,
                    'verify_peer' => false,
                    'verify_host' => false,
                ]);
                $status = $response->getStatusCode();

                if ($status === 401 && $tentativa < $maxTentativas) {
                    $this->medwareClient->autenticar();
                    $config = $this->configRepository->getObterOuCriarConfiguracao();
                    continue;
                }

                if ($status === 200) {
                    $corpo = $response->getContent(false);
                    $dados = json_decode($corpo, true);
                    if (!is_array($dados)) {
                        $ultimoErro = 'Resposta não é JSON válido';
                    } else {
                        $this->captura->registrar($path, $query, $corpo, 200, count($this->extrairItens($dados)));

                        return ['ok' => true, 'dados' => $dados, 'status' => 200, 'erro' => null];
                    }
                } else {
                    $ultimoErro = 'HTTP ' . $status . ': ' . mb_substr($response->getContent(false), 0, 300);
                }
            } catch (\Throwable $e) {
                $ultimoErro = $e->getMessage();
            }

            if ($tentativa < $maxTentativas) {
                usleep(500000 * $tentativa);
            }
        }

        return ['ok' => false, 'dados' => null, 'status' => $status, 'erro' => $path . ' → ' . $ultimoErro];
    }

    private function url(string $path): string
    {
        $base = rtrim($this->configRepository->getObterOuCriarConfiguracao()->getApiBaseUrl(), '/');
        if (!str_ends_with(strtolower($base), '/api')) {
            $base .= '/api';
        }

        return $base . '/' . ltrim($path, '/');
    }

    /** Aceita tanto lista pura quanto objeto paginado ({itens: [...]}, {items: [...]}, {data: [...]}). */
    private function extrairItens(mixed $dados): array
    {
        if (!is_array($dados)) {
            return [];
        }
        foreach (['itens', 'items', 'data', 'dados', 'resultado'] as $k) {
            if (isset($dados[$k]) && is_array($dados[$k])) {
                return $dados[$k];
            }
        }

        return array_is_list($dados) ? $dados : [];
    }
}
