<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava a resposta bruta de cada chamada à API (camada "raw").
 * Se a mesma consulta (endpoint + parâmetros) devolver exatamente o mesmo conteúdo da última captura,
 * apenas atualiza a data de verificação — assim o histórico cresce só quando algo muda.
 *
 * Usa DBAL direto para não depender do estado do EntityManager (outros serviços fazem clear()).
 */
class ApiCapturaService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function registrar(string $endpoint, array $parametros, string $corpo, int $httpStatus = 200, int $qtdRegistros = 0): void
    {
        try {
            $conn = $this->em->getConnection();
            ksort($parametros);
            $chave = sha1($endpoint . '|' . json_encode($parametros));
            $hash = hash('sha256', $corpo);
            $agora = (new \DateTime())->format('Y-m-d H:i:s');

            $ultima = $conn->fetchAssociative(
                'SELECT id, hash_conteudo FROM api_captura WHERE chave_consulta = ? ORDER BY id DESC LIMIT 1',
                [$chave]
            );

            if ($ultima && $ultima['hash_conteudo'] === $hash) {
                $conn->executeStatement(
                    'UPDATE api_captura SET ultima_verificacao_em = ?, vezes_verificado = vezes_verificado + 1 WHERE id = ?',
                    [$agora, $ultima['id']]
                );

                return;
            }

            $conn->insert('api_captura', [
                'endpoint' => mb_substr($endpoint, 0, 255),
                'parametros' => json_encode($parametros, JSON_UNESCAPED_UNICODE),
                'chave_consulta' => $chave,
                'hash_conteudo' => $hash,
                'payload' => $corpo,
                'qtd_registros' => $qtdRegistros,
                'http_status' => $httpStatus,
                'capturado_em' => $agora,
                'ultima_verificacao_em' => $agora,
                'vezes_verificado' => 1,
            ]);
        } catch (\Throwable) {
            // A captura bruta nunca pode derrubar a sincronização.
        }
    }
}
