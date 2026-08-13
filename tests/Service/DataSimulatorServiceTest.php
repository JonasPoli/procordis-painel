<?php

namespace App\Tests\Service;

use App\Repository\AgendamentoRepository;
use App\Service\DataSimulatorService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DataSimulatorServiceTest extends KernelTestCase
{
    private ?DataSimulatorService $simulator = null;
    private ?AgendamentoRepository $agendamentoRepo = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->simulator = $container->get(DataSimulatorService::class);
        $this->agendamentoRepo = $container->get(AgendamentoRepository::class);
    }

    public function testSimularPassoMinuto(): void
    {
        $logs = $this->simulator->simularPassoMinuto(1);
        $this->assertIsArray($logs);
        $this->assertNotEmpty($logs);
    }

    public function testResetarDadosSimulacao(): void
    {
        $this->simulator->resetarDadosSimulacao();
        $logs = $this->simulator->simularPassoMinuto(1);
        $this->assertIsArray($logs);
    }

    public function testPacientesTemposEsperaVariados(): void
    {
        $this->simulator->garantirPacientesFilaVariada();

        $agendamentos = $this->agendamentoRepo->findBy(['status' => ['aguardando_triagem', 'aguardando_medico']]);
        
        $temMaisDe15m = false;
        $temMaisDe2h = false;

        foreach ($agendamentos as $ag) {
            $tempo = $ag->getTempoEsperaMinutos() ?? 0;
            if ($tempo >= 15) {
                $temMaisDe15m = true;
            }
            if ($tempo >= 120) {
                $temMaisDe2h = true;
            }
        }

        $this->assertTrue($temMaisDe15m, 'Deveria existir paciente aguardando há mais de 15 minutos');
        $this->assertTrue($temMaisDe2h, 'Deveria existir paciente aguardando há mais de 2 horas (120min)');
    }
}
