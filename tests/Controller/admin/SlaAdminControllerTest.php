<?php

namespace App\Tests\Controller\admin;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SlaAdminControllerTest extends WebTestCase
{
    private function createAdminClient()
    {
        $client = static::createClient();
        $userRepository = static::getContainer()->get(UserRepository::class);
        $adminUser = $userRepository->findOneBy(['username' => 'admin']);

        if (!$adminUser) {
            $user = new \App\Entity\User();
            $user->setUsername('admin');
            $user->setPassword('password');
            $user->setRoles(['ROLE_ADMIN']);
            $em = static::getContainer()->get('doctrine.orm.entity_manager');
            $em->persist($user);
            $em->flush();
            $adminUser = $user;
        }

        $client->loginUser($adminUser);
        return $client;
    }

    public function testIndex(): void
    {
        $client = $this->createAdminClient();
        $client->request('GET', '/admin/sla/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Parâmetros & Regras de Tempo (SLA)');
    }

    public function testNewRouteAvailable(): void
    {
        $client = $this->createAdminClient();
        $client->request('GET', '/admin/sla/novo');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Nova Regra de SLA');
    }
}
