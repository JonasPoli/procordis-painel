<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SincronizacaoAdminControllerTest extends WebTestCase
{
    public function testCentralSincronismoAcessoSemErros(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/sincronizacao');

        $this->assertTrue(in_array($client->getResponse()->getStatusCode(), [200, 302, 401]));
    }

    public function testIniciarSincronizacaoEndpoint(): void
    {
        $client = static::createClient();
        $client->request('POST', '/admin/sincronizacao/iniciar', [
            'dataInicio' => '2026-08-01',
            'dataFim' => '2026-08-05'
        ]);

        $this->assertTrue(in_array($client->getResponse()->getStatusCode(), [200, 302, 401]));
    }
}
