<?php

namespace App\Command;

use App\Service\AtendimentoConsolidacaoService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:atendimentos:consolidar',
    description: 'Consolida os dias capturados (atendimento_captura_dia) em atendimento, procedimentos e categorias.',
)]
class AtendimentosConsolidarCommand extends Command
{
    public function __construct(private AtendimentoConsolidacaoService $consolidacao)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('todos', null, InputOption::VALUE_NONE, 'Reprocessa todos os dias, não só os pendentes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $r = $this->consolidacao->processarPendentes((bool) $input->getOption('todos'), fn (string $m) => $io->writeln($m));

        $io->success(sprintf(
            '%d dias consolidados: %d agendamentos (%d realizados), %d removidos, %d itens ignorados.',
            $r['dias'], $r['agendamentos'], $r['realizados'], $r['removidos'], $r['ignorados']
        ));

        return Command::SUCCESS;
    }
}
