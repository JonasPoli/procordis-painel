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
}
