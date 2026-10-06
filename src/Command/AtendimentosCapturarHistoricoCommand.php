<?php

namespace App\Command;

use App\Repository\AtendimentoCapturaDiaRepository;
use App\Service\AtendimentoHistoricoService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:atendimentos:capturar-historico',
    description: 'Captura bruta de /Medware/Agendamento/Listar dia a dia em atendimento_captura_dia (retoma de onde parou).',
)]
class AtendimentosCapturarHistoricoCommand extends Command
{
    public function __construct(
        private AtendimentoHistoricoService $historico,
        private AtendimentoCapturaDiaRepository $repo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('de', null, InputOption::VALUE_REQUIRED, 'Data inicial (Y-m-d ou d/m/Y)')
            ->addOption('ate', null, InputOption::VALUE_REQUIRED, 'Data final (Y-m-d ou d/m/Y); padrão: ontem')
            ->addOption('recentes', null, InputOption::VALUE_REQUIRED, 'Recaptura os últimos N dias até ontem (rotina diária; implica --refazer)')
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'pageSize inicial', 1000)
            ->addOption('page-size-max', null, InputOption::VALUE_REQUIRED, 'pageSize máximo ao refazer dias truncados', 20000)
            ->addOption('refazer', null, InputOption::VALUE_NONE, 'Recaptura também os dias já completos')
            ->addOption('pausa-ms', null, InputOption::VALUE_REQUIRED, 'Pausa entre dias (ms)', 200)
            ->addOption('limite-dias', null, InputOption::VALUE_REQUIRED, 'Captura no máximo N dias nesta execução')
            ->addOption('resumo', null, InputOption::VALUE_NONE, 'Só mostra o resumo da camada bruta, sem chamar a API');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('resumo')) {
            $this->mostrarResumo($io);

            return Command::SUCCESS;
        }

        $ontem = new \DateTimeImmutable('yesterday');
        $refazer = (bool) $input->getOption('refazer');
        if ($n = $input->getOption('recentes')) {
            $de = $ontem->modify('-' . (max(1, (int) $n) - 1) . ' days');
            $ate = $ontem;
            $refazer = true;
        } else {
            $de = $this->data($input->getOption('de'));
            $ate = $input->getOption('ate') ? $this->data($input->getOption('ate')) : $ontem;
            if (!$de || !$ate) {
                $io->error('Informe --de (e opcionalmente --ate) em Y-m-d ou d/m/Y, ou use --recentes=N.');

                return Command::INVALID;
            }
        }
        if ($de > $ate) {
            $io->error('--de é posterior a --ate.');

            return Command::INVALID;
        }

        $io->title(sprintf('Captura de atendimentos: %s a %s%s', $de->format('d/m/Y'), $ate->format('d/m/Y'), $refazer ? ' (refazendo dias completos)' : ''));

        $resumo = $this->historico->capturarPeriodo($de, $ate, [
            'pageSize' => (int) $input->getOption('page-size'),
            'pageSizeMax' => (int) $input->getOption('page-size-max'),
            'refazer' => $refazer,
            'pausaMs' => (int) $input->getOption('pausa-ms'),
            'limiteDias' => $input->getOption('limite-dias') !== null ? (int) $input->getOption('limite-dias') : null,
        ], function (array $r, int $i, int $total) use ($io) {
            $situacao = $r['completo'] ? 'ok' : ($r['erro'] ?? 'incompleto');
            if (!$r['completo'] || $io->isVerbose() || $i % 30 === 0 || $i === $total) {
                $io->writeln(sprintf('[%d/%d] %s: %d registros (pageSize %d, %d ms) %s', $i, $total, $r['data'], $r['qtd'], $r['pageSize'], $r['tempoMs'], $situacao));
            }
        });

        $io->newLine();
        $io->definitionList(
            ['Dias no intervalo' => $resumo['dias']],
            ['Pulados (já completos)' => $resumo['pulados']],
            ['Capturados agora' => $resumo['capturados']],
            ['Completos' => $resumo['completos']],
            ['Registros' => $resumo['registros']],
            ['Truncados' => count($resumo['incompletos'])],
            ['Com erro' => count($resumo['erros'])],
        );
        foreach (array_slice($resumo['incompletos'], 0, 50) as $d) {
            $io->writeln('<comment>Truncado: ' . $d . '</comment>');
        }
        foreach (array_slice($resumo['erros'], 0, 50) as $e) {
            $io->writeln('<error>' . $e . '</error>');
        }
        $this->mostrarResumo($io);

        return $resumo['incompletos'] || $resumo['erros'] ? Command::FAILURE : Command::SUCCESS;
    }

    private function mostrarResumo(SymfonyStyle $io): void
    {
        $r = $this->repo->resumo();
        $io->writeln(sprintf(
            '<info>Camada bruta:</info> %d dias (%d completos), %d registros, de %s a %s.',
            $r['dias'] ?? 0, $r['dias_completos'] ?? 0, $r['registros'] ?? 0, $r['primeira_data'] ?? '-', $r['ultima_data'] ?? '-'
        ));
    }

    private function data(?string $valor): ?\DateTimeImmutable
    {
        if (!$valor) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor) ?: \DateTimeImmutable::createFromFormat('!d/m/Y', $valor);

        return $d ?: null;
    }
}
