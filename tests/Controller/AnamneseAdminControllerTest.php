<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AnamneseAdminControllerTest extends WebTestCase
{
    /**
     * @dataProvider rotas
     */
    public function testRotasExigemLogin(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        $this->assertTrue(in_array($client->getResponse()->getStatusCode(), [302, 401], true), 'Área de anamnese deve exigir autenticação');
    }

    public static function rotas(): array
    {
        return [
            ['/admin/anamnese'],
            ['/admin/anamnese/catalogo'],
            ['/admin/anamnese/sincronizacao'],
            ['/admin/anamnese/exportar.csv'],
        ];
    }
}
