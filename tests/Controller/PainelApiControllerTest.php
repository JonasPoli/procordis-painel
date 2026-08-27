<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PainelApiControllerTest extends WebTestCase
{
    public function testPainelEsperaEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/painel/espera');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['sucesso']);
        $this->assertArrayHasKey('pacientes', $data);
    }

    public function testPainelChamadaUltimasEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/painel/chamada/ultimas');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['sucesso']);
        $this->assertArrayHasKey('todasChamadas', $data);
    }

    public function testPainelMedicosEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/painel/medicos');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['sucesso']);
        $this->assertArrayHasKey('medicos', $data);
    }

    public function testPainelDashboardEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/painel/dashboard');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['sucesso']);
        $this->assertArrayHasKey('tempoMedioEsperaMinutos', $data);
    }

    public function testPainelAguardandoEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/painel/aguardando');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['sucesso']);
        $this->assertArrayHasKey('kpis', $data);
        $this->assertArrayHasKey('pacientes', $data);
    }

    public function testPainelFinalizadosEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/painel/finalizados');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['sucesso']);
        $this->assertArrayHasKey('graficos', $data);
        $this->assertArrayHasKey('pacientes', $data);
    }

    public function testPainelSlaConfigEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/painel/sla-config');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['sucesso']);
        $this->assertArrayHasKey('regrasSla', $data);
    }

    public function testAnonimizacaoPacienteDeslogado(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/painel/espera');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['sucesso']);

        if (!empty($data['pacientes'])) {
            foreach ($data['pacientes'] as $p) {
                // Quando deslogado, o nome do paciente deve ser mascarado como Senha N... ou P...
                $this->assertStringStartsWith('Senha', $p['pacienteNome']);
            }
        }
    }
}
