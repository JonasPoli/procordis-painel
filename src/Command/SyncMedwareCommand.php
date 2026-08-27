<?php

namespace App\Command;

use App\Service\MedwareApiClientService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-medware',
    description: 'Sincroniza os agendamentos e status da API Medware Procordis com a base de dados local.',
)]
class SyncMedwareCommand extends Command
{
    public function __construct(
        private MedwareApiClientService $medwareClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loop', 'l', InputOption::VALUE_NONE, 'Executa a sincronização em loop contínuo')
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Intervalo em segundos entre sincronizações no modo loop', 5)
            ->addOption('date', null, InputOption::VALUE_OPTIONAL, 'Data específica para sincronização (formato Y-m-d ou d/m/Y)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isLoop = $input->getOption('loop');
        $delay = (int) $input->getOption('delay');
        $dateStr = $input->getOption('date');

        $data = null;
        if ($dateStr) {
            $data = \DateTime::createFromFormat('d/m/Y', $dateStr) ?: \DateTime::createFromFormat('Y-m-d', $dateStr);
            if (!$data) {
                $io->error('Formato de data inválido. Use Y-m-d ou d/m/Y.');
                return Command::FAILURE;
            }
        }

        if ($isLoop) {
            $io->info("Iniciando sincronização contínua com a API Medware (intervalo {$delay}s). Pressione Ctrl+C para parar.");
            while (true) {
                $agora = (new \DateTime())->format('H:i:s');
                $res = $this->medwareClient->sincronizarAgendamentosHoje($data);

                if (isset($res['erro'])) {
                    $io->warning("[{$agora}] " . $res['erro']);
                } else {
                    $io->writeln("<info>[{$agora}] Sincronizado:</info> Total: {$res['total']} | Novos: {$res['novos']} | Atualizados: {$res['atualizados']}");
                }
                sleep($delay);
            }
        } else {
            $io->title('Iniciando sincronização com a API Medware Procordis...');
            $res = $this->medwareClient->sincronizarAgendamentosHoje($data);

            if (isset($res['erro'])) {
                $io->error($res['erro']);
                return Command::FAILURE;
            }

            $io->success("Sincronização concluída com sucesso! Total de registros: {$res['total']} (Novos: {$res['novos']}, Atualizados: {$res['atualizados']})");
        }

        return Command::SUCCESS;
    }
}
