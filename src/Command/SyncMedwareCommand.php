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
            ->addOption('date', null, InputOption::VALUE_OPTIONAL, 'Data específica para sincronização (formato Y-m-d ou d/m/Y)')
            ->addOption('days', 'D', InputOption::VALUE_OPTIONAL, 'Quantidade de dias anteriores para sincronizar em lote histórico', 1)
            ->addOption('start-date', null, InputOption::VALUE_OPTIONAL, 'Data inicial do período histórico (Y-m-d ou d/m/Y)')
            ->addOption('end-date', null, InputOption::VALUE_OPTIONAL, 'Data final do período histórico (Y-m-d ou d/m/Y)')
            ->addOption('years', 'Y', InputOption::VALUE_OPTIONAL, 'Anos para trás a importar (ex: 20 para os últimos 20 anos)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isLoop = $input->getOption('loop');
        $delay = (int) $input->getOption('delay');
        $dateStr = $input->getOption('date');
        $days = (int) $input->getOption('days');

        $data = null;
        if ($dateStr) {
            $data = \DateTime::createFromFormat('d/m/Y', $dateStr) ?: \DateTime::createFromFormat('Y-m-d', $dateStr);
            if (!$data) {
                $io->error('Formato de data inválido. Use Y-m-d ou d/m/Y.');
                return Command::FAILURE;
            }
        }

        $years = $input->getOption('years');
        $startDateStr = $input->getOption('start-date');
        $endDateStr = $input->getOption('end-date');

        // Se especificou anos ou intervalo de datas
        if ($years || $startDateStr) {
            $dtFim = $endDateStr ? (\DateTime::createFromFormat('d/m/Y', $endDateStr) ?: \DateTime::createFromFormat('Y-m-d', $endDateStr)) : new \DateTime();
            if ($startDateStr) {
                $dtInicio = \DateTime::createFromFormat('d/m/Y', $startDateStr) ?: \DateTime::createFromFormat('Y-m-d', $startDateStr);
            } else {
                $numYears = (int) $years;
                $dtInicio = (clone $dtFim)->modify("-{$numYears} years");
            }

            if (!$dtInicio || !$dtFim) {
                $io->error('Datas inválidas especificadas.');
                return Command::FAILURE;
            }

            $io->title("Iniciando Carga Histórica da API Medware de {$dtInicio->format('d/m/Y')} até {$dtFim->format('d/m/Y')}...");

            $cursor = clone $dtInicio;
            $totalGeral = 0;
            $novosGeral = 0;
            $atualizadosGeral = 0;
            $diasCount = 0;

            while ($cursor <= $dtFim) {
                $diaFormatado = $cursor->format('d/m/Y');
                $io->write("Importando {$diaFormatado}... ");

                $res = $this->medwareClient->sincronizarAgendamentosHoje($cursor);

                if (isset($res['erro'])) {
                    $io->writeln("<comment>{$res['erro']}</comment>");
                } else {
                    $totalGeral += $res['total'];
                    $novosGeral += $res['novos'];
                    $atualizadosGeral += $res['atualizados'];
                    $io->writeln("<info>OK ({$res['total']} registros)</info>");
                }

                $diasCount++;
                $cursor->modify('+1 day');
            }

            $io->success("Carga Histórica Concluída! {$diasCount} dia(s) processado(s). Total: {$totalGeral} (Novos: {$novosGeral}, Atualizados: {$atualizadosGeral})");
            return Command::SUCCESS;
        }

        if ($days > 1) {
            $io->title("Sincronizando histórico dos últimos {$days} dias com a API Medware...");
            $totalGeral = 0;
            $novosGeral = 0;
            $atualizadosGeral = 0;

            for ($i = $days - 1; $i >= 0; $i--) {
                $dt = (new \DateTime())->modify("-{$i} days");
                $diaFormatado = $dt->format('d/m/Y');
                $io->write("Sincronizando {$diaFormatado}... ");
                $res = $this->medwareClient->sincronizarAgendamentosHoje($dt);

                if (isset($res['erro'])) {
                    $io->writeln("<comment>{$res['erro']}</comment>");
                } else {
                    $totalGeral += $res['total'];
                    $novosGeral += $res['novos'];
                    $atualizadosGeral += $res['atualizados'];
                    $io->writeln("<info>OK ({$res['total']} registros)</info>");
                }
            }

            $io->success("Carga histórica concluída! Total processado: {$totalGeral} (Novos: {$novosGeral}, Atualizados: {$atualizadosGeral})");
            return Command::SUCCESS;
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
