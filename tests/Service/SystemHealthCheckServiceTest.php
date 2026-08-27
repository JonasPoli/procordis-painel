<?php

namespace App\Tests\Service;

use App\Service\SystemHealthCheckService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SystemHealthCheckServiceTest extends KernelTestCase
{
    private ?SystemHealthCheckService $healthService = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->healthService = $container->get(SystemHealthCheckService::class);
    }

    public function testDatabaseCheck(): void
    {
        $res = $this->healthService->testarBancoDados();
        $this->assertIsArray($res);
        $this->assertNotEmpty($res);
        $this->assertEquals('success', $res[0]['status']);
    }

    public function testAmbienteCheck(): void
    {
        $res = $this->healthService->testarAmbienteServidor();
        $this->assertIsArray($res);
        $this->assertNotEmpty($res);
    }

    public function testEstatisticasBanco(): void
    {
        $stats = $this->healthService->obterEstatisticasBanco();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('totalAgendamentos', $stats);
        $this->assertArrayHasKey('totalPacientes', $stats);
    }
}
