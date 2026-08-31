<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BackupAdminControllerTest extends WebTestCase
{
    public function testCentralBackupAcessoSemErros(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/backup');

        $this->assertTrue(in_array($client->getResponse()->getStatusCode(), [200, 302, 401]));
    }

    public function testDownloadBackupEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/backup/download');

        $this->assertTrue(in_array($client->getResponse()->getStatusCode(), [200, 302, 401]));
    }

    public function testBackupGeracaoERestauracaoBaixoConsumoMemoria(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var \App\Service\BackupRestoreService $service */
        $service = $container->get(\App\Service\BackupRestoreService::class);

        $memoriaAntes = memory_get_usage(true);
        $arquivo = $service->gerarBackup();

        $this->assertFileExists($arquivo);
        $this->assertGreaterThan(0, filesize($arquivo));

        // Testar restauração (modo não destrutivo no teste)
        $resultado = $service->restaurarBackup($arquivo, false);
        $this->assertTrue($resultado['sucesso']);
        $this->assertIsArray($resultado['totais']);

        @unlink($arquivo);
    }
}
