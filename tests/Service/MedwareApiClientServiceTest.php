<?php

namespace App\Tests\Service;

use App\Service\MedwareApiClientService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class MedwareApiClientServiceTest extends KernelTestCase
{
    private ?MedwareApiClientService $client = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->client = $container->get(MedwareApiClientService::class);
    }

    public function testServiceInstance(): void
    {
        $this->assertInstanceOf(MedwareApiClientService::class, $this->client);
    }
}
