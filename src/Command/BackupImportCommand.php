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
    name: 'app:backup:import',
    description: 'Restaura 100% dos dados do sistema a partir de um arquivo .procordis.bak via terminal.',
)]
class BackupImportCommand extends Command
{
    public function __construct(
        private BackupRestoreService $backupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Caminho do arquivo .procordis.bak para restauração')
            ->addOption('clean', 'c', InputOption::VALUE_NONE, 'Realiza limpeza prévia total do banco de dados antes da restauração');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $caminhoFile = $input->getOption('file');
        $modoLimpo = (bool) $input->getOption('clean');

        if (!$caminhoFile) {
            $io->error('Especifique o arquivo com --file=/caminho/backup.procordis.bak');
            return Command::FAILURE;
        }

        if (!file_exists($caminhoFile)) {
            $io->error("Arquivo não encontrado em: {$caminhoFile}");
            return Command::FAILURE;
        }

        $msg = $modoLimpo ? 'ATENÇÃO: Restauração limpa selecionada! O banco atual será zerado.' : 'Restauração incremental selecionada.';
        $io->warning($msg);

        if ($input->isInteractive()) {
            if (!$io->confirm('Deseja realmente prosseguir com a restauração?', false)) {
                $io->info('Operação cancelada pelo usuário.');
                return Command::SUCCESS;
            }
        }

        $io->title('Iniciando Restauração Total do Sistema...');

        try {
            $res = $this->backupService->restaurarBackup($caminhoFile, $modoLimpo);
            $totais = $res['totais'];

            $io->success([
                'Restauração concluída com sucesso!',
                "Users (Logins ADM): {$totais['users']}",
                "Configurações: {$totais['configuracoes']}",
                "Pacientes: {$totais['pacientes']}",
                "Médicos: {$totais['medicos']}",
                "Agendamentos: {$totais['agendamentos']}",
                "Etapas/Anamneses: {$totais['etapasHistorico']}",
                "Senhas/Chamadas: " . ($totais['senhas'] + $totais['chamadas']),
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error("Falha ao restaurar backup: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
