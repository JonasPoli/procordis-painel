<?php

namespace App\Tests\Service;

use App\Entity\ConfiguracaoIntegracao;
use App\Entity\ExameClassificacao;
use App\Entity\Paciente;
use App\Entity\PacienteHistorico;
use App\Service\AnamneseSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Sincronização da anamnese contra uma API simulada (MockHttpClient):
 * gravação, histórico de nome, marcação de removidos e reaparecimento.
 */
class AnamneseSyncServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $itensApi = [];
    private array $requisicoes = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $container->set('http_client', new MockHttpClient(fn (string $method, string $url) => $this->responder($method, $url)));

        $this->em = $container->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);

        $config = new ConfiguracaoIntegracao();
        $config->setModoSimulacao(false);
        $config->setApiUsuario('teste');
        $config->setApiSenha('teste');
        $config->setApiToken('token-fake');
        $this->em->persist($config);
        $this->em->flush();

        $this->itensApi = [
            $this->item(7329, 'PACIENTE A', 47259, '22/12/2025 15:30', 20, 'VACINA DA COVID DOSE 5', '20'),
            $this->item(19580, 'PACIENTE B', 45624, '22/12/2025 14:10', 2, 'DIABETES'),
            $this->item(19580, 'PACIENTE B', 45624, '22/12/2025 14:10', 3, 'HIPERTENSÃO'),
            $this->item(19580, 'PACIENTE B SOBRENOME', 45624, '22/12/2025 14:10', 19, 'VACINA DA COVID DOSE 4', '19'),
            $this->item(17691, 'PACIENTE C', 47631, '22/12/2025 10:50', 2, 'DIABETES'),
        ];
    }

    public function testSincronizaGravaHistoricoERemocoes(): void
    {
        /** @var AnamneseSyncService $sync */
        $sync = static::getContainer()->get(AnamneseSyncService::class);

        // 1ª execução
        $exec = $sync->executar('completo', 'cli', ['desde' => '2025-01-01', 'diasAgendamentos' => 0, 'maxDiasBackfill' => 0]);
        $this->assertSame('sucesso', $exec->getStatus(), (string) $exec->getErros());

        $repo = $this->em->getRepository(ExameClassificacao::class);
        $this->assertCount(5, $repo->findAll());
        $this->assertCount(3, $this->em->getRepository(Paciente::class)->findAll(), 'Pacientes identificados pelo código, sem duplicar');

        // Nome divergente dentro da mesma resposta não gera histórico
        $this->assertCount(0, $this->em->getRepository(PacienteHistorico::class)->findAll());

        // Catálogo recebeu categorias sugeridas
        $conn = $this->em->getConnection();
        $this->assertSame('vacina', $conn->fetchOne('SELECT categoria FROM classificacao_estudo WHERE cod_classificacao = 20'));
        $this->assertSame('comorbidade', $conn->fetchOne('SELECT categoria FROM classificacao_estudo WHERE cod_classificacao = 2'));

        // 2ª execução: hipertensão some da API e o paciente C muda de nome
        $this->itensApi = array_values(array_filter($this->itensApi, fn ($i) => $i['codClassificacao'] !== 3));
        $this->itensApi[3]['nomePaciente'] = 'PACIENTE C RENOMEADO';
        sleep(1);
        $exec = $sync->executar('completo', 'cli', ['desde' => '2025-01-01', 'diasAgendamentos' => 0, 'maxDiasBackfill' => 0]);
        $this->assertSame('sucesso', $exec->getStatus(), (string) $exec->getErros());

        $this->em->clear();
        $removidos = $conn->fetchFirstColumn('SELECT c.cod_classificacao FROM exame_classificacao ec JOIN classificacao_estudo c ON c.id = ec.classificacao_id WHERE ec.removido_em IS NOT NULL');
        $this->assertSame([3], array_map('intval', $removidos));
        $this->assertCount(5, $repo->findAll(), 'Nada é apagado fisicamente');

        $hist = $this->em->getRepository(PacienteHistorico::class)->findAll();
        $this->assertCount(1, $hist);
        $this->assertSame('PACIENTE C', $hist[0]->getValorAnterior());
        $this->assertSame('PACIENTE C RENOMEADO', $hist[0]->getValorNovo());

        // 3ª execução: hipertensão volta
        $this->itensApi[] = $this->item(19580, 'PACIENTE B', 45624, '22/12/2025 14:10', 3, 'HIPERTENSÃO');
        sleep(1);
        $sync->executar('incremental', 'cli', ['dias' => 400]);
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM exame_classificacao WHERE removido_em IS NOT NULL'));

        // A resposta bruta foi guardada (sem duplicar quando igual)
        $this->assertGreaterThan(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM api_captura WHERE endpoint = '/Paciente/ClassificacoesEstudo'"));

        // Paginação: tamanhoPagina foi enviado
        $this->assertNotEmpty(array_filter($this->requisicoes, fn ($u) => str_contains($u, 'tamanhoPagina=')));
    }

    public function testApiVaziaNaoApagaDados(): void
    {
        $sync = static::getContainer()->get(AnamneseSyncService::class);
        // 30 itens no período
        $this->itensApi = [];
        for ($i = 1; $i <= 30; $i++) {
            $this->itensApi[] = $this->item(1000 + $i, 'P' . $i, 5000 + $i, '10/03/2025 09:00', 2, 'DIABETES');
        }
        $sync->executar('completo', 'cli', ['desde' => '2025-01-01', 'diasAgendamentos' => 0, 'maxDiasBackfill' => 0]);

        $this->itensApi = [];
        sleep(1);
        $exec = $sync->executar('completo', 'cli', ['desde' => '2025-01-01', 'diasAgendamentos' => 0, 'maxDiasBackfill' => 0]);

        $this->assertSame('parcial', $exec->getStatus());
        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM exame_classificacao WHERE removido_em IS NOT NULL'));
    }

    private function responder(string $method, string $url): MockResponse
    {
        $this->requisicoes[] = $url;
        $path = parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        if (str_ends_with($path, '/api/ClassificacaoEstudo')) {
            $cat = [];
            foreach ($this->itensApi as $i) {
                $cat[$i['codClassificacao']] = ['codClassificacao' => $i['codClassificacao'], 'classificacao' => $i['classificacao'], 'codigo' => $i['codigo'], 'tipo' => 0];
            }

            return new MockResponse(json_encode(array_values($cat)));
        }

        if (str_ends_with($path, '/api/Paciente/ClassificacoesEstudo')) {
            $ini = \DateTime::createFromFormat('!d/m/Y', $q['dataInicio']);
            $fim = \DateTime::createFromFormat('!d/m/Y', $q['dataFim'])->setTime(23, 59, 59);
            $noPeriodo = array_values(array_filter($this->itensApi, function ($i) use ($ini, $fim) {
                $d = \DateTime::createFromFormat('d/m/Y H:i', $i['dataExame']);

                return $d >= $ini && $d <= $fim;
            }));
            $pagina = (int) $q['pagina'];
            // página pequena para exercitar a paginação
            $tam = 2;

            return new MockResponse(json_encode([
                'pagina' => $pagina,
                'tamanhoPagina' => $tam,
                'total' => count($noPeriodo),
                'itens' => array_slice($noPeriodo, ($pagina - 1) * $tam, $tam),
            ]));
        }

        if (str_contains($path, '/Acesso/login')) {
            return new MockResponse(json_encode(['token' => 'token-fake']));
        }

        return new MockResponse('[]');
    }

    private function item(int $codPac, string $nome, int $codAg, string $data, int $codClass, string $classif, ?string $codigo = null): array
    {
        return [
            'codPaciente' => $codPac, 'nomePaciente' => $nome, 'codAgendamento' => $codAg, 'dataExame' => $data,
            'codClassificacao' => $codClass, 'classificacao' => $classif, 'codigo' => $codigo, 'tipo' => 0,
        ];
    }
}
