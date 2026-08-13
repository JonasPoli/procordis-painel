<?php

namespace App\Command;

use App\Service\DataSimulatorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:simulate-data',
    description: 'Simula a chegada, recepção, triagem, chamadas no telão e atendimentos médicos em tempo real para o Procordis Painel.',
)]
class SimulateDataCommand extends Command
{
    public function __construct(
        private DataSimulatorService $simulatorService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('step', 's', InputOption::VALUE_OPTIONAL, 'Quantidade de passos de tempo (minutos) a simular', 1)
            ->addOption('reset', 'r', InputOption::VALUE_NONE, 'Reseta os dados de agendamentos e chamadas antes de simular')
            ->addOption('loop', 'l', InputOption::VALUE_NONE, 'Executa em loop contínuo a cada N segundos')
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Atraso em segundos entre iterações no modo loop', 3);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('reset')) {
            $io->warning('Resetando dados da simulação...');
            $this->simulatorService->resetarDadosSimulacao();
            $io->success('Dados de teste limpos com sucesso.');
        }

        $steps = (int) $input->getOption('step');
        $isLoop = $input->getOption('loop');
        $delay = (int) $input->getOption('delay');

        if ($isLoop) {
            $io->info("Iniciando simulador em modo LOOP contínuo (intervalo {$delay}s). Pressione Ctrl+C para parar.");
            while (true) {
                $logs = $this->simulatorService->simularPassoMinuto(1);
                $agora = (new \DateTime())->format('H:i:s');
                if (count($logs) > 0) {
                    $io->writeln("<comment>[{$agora}] Movimentos gerados:</comment>");
                    foreach ($logs as $log) {
                        $io->writeln("  - {$log}");
                    }
                } else {
                    $io->writeln("<info>[{$agora}] Simulador executado - sem alterações nesta iteração.</info>");
                }
                sleep($delay);
            }
        } else {
            $io->title("Executando {$steps} passo(s) da simulação de atendimento...");
            for ($i = 1; $i <= $steps; $i++) {
                $logs = $this->simulatorService->simularPassoMinuto(1);
                $io->section("Passo {$i} de {$steps}");
                if (count($logs) > 0) {
                    foreach ($logs as $log) {
                        $io->writeln("  - {$log}");
                    }
                } else {
                    $io->writeln("  Nenhum novo movimento.");
                }
            }
            $io->success('Simulação concluída com sucesso!');
        }

        return Command::SUCCESS;
    }
}
