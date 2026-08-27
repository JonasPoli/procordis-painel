<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PainelVisualControllerTest extends WebTestCase
{
    public function testPainelEsperaRoute(): void
    {
        $client = static::createClient();
        $client->request('GET', '/painel/espera');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('header img');
    }

    public function testPainelChamadaRoute(): void
    {
        $client = static::createClient();
        $client->request('GET', '/painel/chamada');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('header img');
    }

    public function testPainelMedicosRoute(): void
    {
        $client = static::createClient();
        $client->request('GET', '/painel/medicos');
        $this->assertResponseIsSuccessful();
    }

    public function testPainelTriagemRoute(): void
    {
        $client = static::createClient();
        $client->request('GET', '/painel/triagem');
        $this->assertResponseIsSuccessful();
    }

    public function testPainelDashboardRoute(): void
    {
        $client = static::createClient();
        $client->request('GET', '/painel/dashboard');
        $this->assertResponseIsSuccessful();
    }

    public function testPainelAguardandoRoute(): void
    {
        $client = static::createClient();
        $client->request('GET', '/painel/aguardando');
        $this->assertResponseIsSuccessful();
    }

    public function testPainelFinalizadosRoute(): void
    {
        $client = static::createClient();
        $client->request('GET', '/painel/finalizados');
        $this->assertResponseIsSuccessful();
    }
}

