<?php

namespace App\Tests\Service;

use App\Entity\AtendimentoCapturaDia;
use App\Entity\ConfiguracaoIntegracao;
use App\Repository\AtendimentoCapturaDiaRepository;
use App\Service\AtendimentoHistoricoService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Carga histórica bruta contra uma API simulada: gravação por dia, aumento de pageSize em retorno truncado,
 * retomada (dias completos são pulados) e preservação de dia completo quando a recaptura falha.
 */
class AtendimentoHistoricoServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var array<string, int> data (d/m/Y) => quantidade de agendamentos no dia */
    private array $volumePorDia = [];
    /** @var array<int, array{dataInicio: ?string, pageSize: ?string}> */
    private array $requisicoes = [];
    private bool $falhar = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $container->set('http_client', new MockHttpClient(fn (string $method, string $url) => $this->responder($url)));

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
    }

    public function testCapturaDiaCompleto(): void
    {
        $this->volumePorDia = ['05/10/2026' => 3];

        $r = $this->servico()->capturarDia(new \DateTimeImmutable('2026-10-05'), 1000);

        $this->assertTrue($r['completo']);
        $this->assertSame(3, $r['qtd']);
        $dia = $this->capturaDe('2026-10-05');
        $this->assertTrue($dia->isCompleto());
        $this->assertCount(3, $dia->getItens());
        $this->assertSame(200, $dia->getHttpStatus());
    }

    public function testRetornoTruncadoAumentaPageSize(): void
    {
        $this->volumePorDia = ['05/10/2026' => 5];

        $r = $this->servico()->capturarDia(new \DateTimeImmutable('2026-10-05'), 2, 100);

        $this->assertTrue($r['completo']);
        $this->assertSame(5, $r['qtd']);
        $this->assertSame(['2', '8'], array_column($this->requisicoes, 'pageSize'));
    }

    public function testTruncadoNoLimiteFicaIncompleto(): void
    {
        $this->volumePorDia = ['05/10/2026' => 5];

        $r = $this->servico()->capturarDia(new \DateTimeImmutable('2026-10-05'), 2, 4);

        $this->assertFalse($r['completo']);
        $this->assertStringStartsWith('Retorno truncado', $r['erro']);
        $this->assertFalse($this->capturaDe('2026-10-05')->isCompleto());
    }

    public function testRetomadaPulaDiasCompletos(): void
    {
        $this->volumePorDia = ['01/10/2026' => 1, '02/10/2026' => 2, '03/10/2026' => 0];
        $servico = $this->servico();
        $de = new \DateTimeImmutable('2026-10-01');
        $ate = new \DateTimeImmutable('2026-10-03');

        $primeira = $servico->capturarPeriodo($de, $ate, ['pausaMs' => 0, 'limiteDias' => 2]);
        $this->assertSame(['dias' => 3, 'pulados' => 0, 'capturados' => 2, 'completos' => 2, 'registros' => 3], array_intersect_key($primeira, array_flip(['dias', 'pulados', 'capturados', 'completos', 'registros'])));

        $this->requisicoes = [];
        $segunda = $servico->capturarPeriodo($de, $ate, ['pausaMs' => 0]);
        $this->assertSame(2, $segunda['pulados']);
        $this->assertSame(1, $segunda['capturados']);
        $this->assertSame(['03/10/2026'], array_column($this->requisicoes, 'dataInicio'));

        $refeita = $servico->capturarPeriodo($de, $ate, ['pausaMs' => 0, 'refazer' => true]);
        $this->assertSame(3, $refeita['capturados']);
    }

    public function testFalhaNaoApagaDiaCompleto(): void
    {
        $this->volumePorDia = ['05/10/2026' => 2];
        $servico = $this->servico();
        $servico->capturarDia(new \DateTimeImmutable('2026-10-05'));

        $this->falhar = true;
        $r = $servico->capturarDia(new \DateTimeImmutable('2026-10-05'));
        $this->assertFalse($r['completo']);
        $this->assertNotNull($r['erro']);

        $dia = $this->capturaDe('2026-10-05');
        $this->assertTrue($dia->isCompleto());
        $this->assertCount(2, $dia->getItens());
    }

    public function testFalhaEmDiaNovoRegistraErro(): void
    {
        $this->falhar = true;

        $resumo = $this->servico()->capturarPeriodo(new \DateTimeImmutable('2026-10-05'), new \DateTimeImmutable('2026-10-05'), ['pausaMs' => 0]);

        $this->assertCount(1, $resumo['erros']);
        $dia = $this->capturaDe('2026-10-05');
        $this->assertFalse($dia->isCompleto());
        $this->assertSame(500, $dia->getHttpStatus());
        $this->assertNull($dia->getPayload());
    }

    /**
     * @dataProvider casosTruncamento
     */
    public function testRetornoTruncado(int $qtd, int $pageSize, bool $esperado): void
    {
        $this->assertSame($esperado, AtendimentoHistoricoService::retornoTruncado($qtd, $pageSize));
    }

    public static function casosTruncamento(): iterable
    {
        yield 'abaixo do pageSize' => [499, 1000, false];
        yield 'igual ao pageSize' => [1000, 1000, true];
        yield 'exatamente 500 com pageSize maior (servidor pode ignorar o pageSize)' => [500, 1000, true];
        yield '500 com pageSize 500' => [500, 500, true];
        yield 'vazio' => [0, 1000, false];
    }

    private function servico(): AtendimentoHistoricoService
    {
        return static::getContainer()->get(AtendimentoHistoricoService::class);
    }

    private function capturaDe(string $data): AtendimentoCapturaDia
    {
        $this->em->clear();
        $dia = static::getContainer()->get(AtendimentoCapturaDiaRepository::class)->findOneBy(['data' => new \DateTime($data)]);
        $this->assertNotNull($dia, "Dia $data não capturado");

        return $dia;
    }

    private function responder(string $url): MockResponse
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->requisicoes[] = ['dataInicio' => $q['dataInicio'] ?? null, 'pageSize' => $q['pageSize'] ?? null];

        if ($this->falhar) {
            return new MockResponse('erro interno', ['http_code' => 500]);
        }

        $qtd = min($this->volumePorDia[$q['dataInicio'] ?? ''] ?? 0, (int) ($q['pageSize'] ?? 500));
        $itens = [];
        for ($i = 1; $i <= $qtd; $i++) {
            $itens[] = [
                'codAgendamento' => $i,
                'dataHoraAgendada' => $q['dataInicio'] . ' 08:00',
                'codStatusAgendamento' => 4,
                'procedimentoPlanoOperadora' => ['codProcedimento' => 10, 'descricaoProcedimento' => 'ECG'],
            ];
        }

        return new MockResponse(json_encode($itens), ['http_code' => 200]);
    }
}
