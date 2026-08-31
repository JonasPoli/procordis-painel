<?php

namespace App\Command;

use App\Service\BackupRestoreService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backup:export',
    description: 'Gera um pacote de backup completo (.procordis.bak) com 100% dos dados e logins do sistema.',
)]
class BackupExportCommand extends Command
{
    public function __construct(
        private BackupRestoreService $backupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_OPTIONAL, 'Caminho de saída para salvar o arquivo .procordis.bak');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $caminhoFile = $input->getOption('file');

        $io->title('Gerando Backup Completo do Sistema Procordis Painel...');

        try {
            $arquivoGerado = $this->backupService->gerarBackup($caminhoFile);
            $tamanhoBytes = filesize($arquivoGerado);
            $tamanhoMb = round($tamanhoBytes / (1024 * 1024), 2);

            $io->success([
                'Backup gerado com sucesso!',
                "Arquivo: {$arquivoGerado}",
                "Tamanho: {$tamanhoMb} MB ({$tamanhoBytes} bytes)"
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error("Falha ao gerar o backup: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
