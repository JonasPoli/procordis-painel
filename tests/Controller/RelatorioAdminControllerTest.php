<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RelatorioAdminControllerTest extends WebTestCase
{
    public function testRelatorioProcedimentosAcessoSemErros(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/relatorios/procedimentos');

        // Se redirecionar para login ou carregar 200/302
        $this->assertTrue(in_array($client->getResponse()->getStatusCode(), [200, 302, 401]));
    }

    public function testRelatorioAnamnesesAcessoSemErros(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/relatorios/anamneses');

        $this->assertTrue(in_array($client->getResponse()->getStatusCode(), [200, 302, 401]));
    }
}
