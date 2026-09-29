<?php

namespace App\Command;

use App\Service\AnamneseSimuladorService;
use App\Service\AnamneseSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Agendamento sugerido (crontab do servidor):
 *   0 7,12,18 * * *  php bin/console app:anamnese:sync --modo=incremental --origem=cron
 *   0 2 * * *        php bin/console app:anamnese:sync --modo=completo --origem=cron
 */
#[AsCommand(
    name: 'app:anamnese:sync',
    description: 'Sincroniza a anamnese (ClassificacaoEstudo) da API Procordis: incremental (novos dados) ou completo (todo o histórico + reconciliação).',
)]
class AnamneseSyncCommand extends Command
{
    public function __construct(
        private AnamneseSyncService $sync,
        private AnamneseSimuladorService $simulador,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('modo', 'm', InputOption::VALUE_REQUIRED, 'incremental | completo', 'incremental')
            ->addOption('dias', null, InputOption::VALUE_REQUIRED, 'Incremental: quantos dias para trás reler', 7)
            ->addOption('desde', null, InputOption::VALUE_REQUIRED, 'Completo: data inicial do histórico (Y-m-d)', '2000-01-01')
            ->addOption('dias-agendamentos', null, InputOption::VALUE_REQUIRED, 'Completo: dias recentes de agendamentos a atualizar', 30)
            ->addOption('max-dias-backfill', null, InputOption::VALUE_REQUIRED, 'Completo: máximo de dias antigos de agendamentos a carregar por execução', 300)
            ->addOption('origem', null, InputOption::VALUE_REQUIRED, 'cron | manual | cli', 'cli')
            ->addOption('forcar', null, InputOption::VALUE_NONE, 'Ignora a trava de execução em andamento')
            ->addOption('regerar-simulacao', null, InputOption::VALUE_NONE, 'Modo simulação: apaga e gera de novo a base simulada de anamnese');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        if ($input->getOption('regerar-simulacao')) {
            $removidos = $this->simulador->removerBaseSimulada();
            $io->note("Base simulada anterior removida ({$removidos} pacientes).");
        }

        $modo = (string) $input->getOption('modo');
        $io->title('Sincronização da anamnese — modo ' . $modo);

        try {
            $execucao = $this->sync->executar($modo, (string) $input->getOption('origem'), [
                'dias' => (int) $input->getOption('dias'),
                'desde' => (string) $input->getOption('desde'),
                'diasAgendamentos' => (int) $input->getOption('dias-agendamentos'),
                'maxDiasBackfill' => (int) $input->getOption('max-dias-backfill'),
                'forcar' => (bool) $input->getOption('forcar'),
            ], fn (string $msg) => $io->writeln('<info>[' . date('H:i:s') . ']</info> ' . $msg));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $resumo = $execucao->getResumo() ?? [];
        $totais = $resumo['totais'] ?? [];
        $io->definitionList(
            ['Execução' => '#' . $execucao->getId() . ' (' . $execucao->getStatus() . ')'],
            ['Duração' => ($execucao->getDuracaoSegundos() ?? 0) . 's'],
            ['Exames com anamnese' => $totais['exames'] ?? '-'],
            ['Pacientes' => $totais['pacientes'] ?? '-'],
            ['Classificações ativas' => $totais['classificacoesAtivas'] ?? '-'],
            ['Exames sem agendamento local' => $totais['semAgendamento'] ?? '-'],
        );

        if ($execucao->getErros()) {
            $io->warning($execucao->getErros());
        }

        return $execucao->getStatus() === 'erro' ? Command::FAILURE : Command::SUCCESS;
    }
}
