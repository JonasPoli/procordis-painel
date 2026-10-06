<?php

namespace App\Command;

use App\Service\MedwareSondagemService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:medware:sondar',
    description: 'Sondagem somente leitura da API Medware (estrutura, pageSize, histórico, volume, procedimentos). Não grava nada no banco nem exibe dados pessoais.',
)]
class MedwareSondarCommand extends Command
{
    public function __construct(
        private MedwareSondagemService $sondagem,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ano-inicial', null, InputOption::VALUE_REQUIRED, 'Primeiro ano a testar na profundidade do histórico', 2000)
            ->addOption('sem-volume', null, InputOption::VALUE_NONE, 'Não consulta o volume por ano (ListarResumido)')
            ->addOption('saida', null, InputOption::VALUE_REQUIRED, 'Arquivo JSON do relatório (padrão: var/medware-sondagem/sondagem-<data>.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sondagem da API Medware — painel de atendimentos');

        $relatorio = $this->sondagem->executar([
            'anoInicial' => (int) $input->getOption('ano-inicial'),
            'comVolume' => !$input->getOption('sem-volume'),
        ], fn (string $msg) => $io->writeln('<comment>' . $msg . '</comment>'));

        if (isset($relatorio['erro'])) {
            $io->error($relatorio['erro']);

            return Command::FAILURE;
        }

        $saida = $input->getOption('saida') ?: $this->projectDir . '/var/medware-sondagem/sondagem-' . date('Ymd-His') . '.json';
        @mkdir(dirname($saida), 0775, true);
        file_put_contents($saida, json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $a = $relatorio['amostra'];
        $io->section('1. Amostra ' . $a['periodo']);
        $io->writeln(sprintf('Nível superior: %s — %d agendamentos (%s ms)%s', $a['nivelSuperior'] ?? '-', $a['qtd'], $a['tempoMs'], $a['erro'] ? ' — ERRO: ' . $a['erro'] : ''));
        $io->table(['Campo', 'Tipos', 'Preenchidos', 'Formatos'], array_map(
            fn ($campo, $c) => [$campo, implode('|', $c['tipos']), $c['preenchidos'], implode(' ; ', $c['formatos'])],
            array_keys($a['estrutura']), $a['estrutura']
        ));
        foreach ($a['valores'] as $campo => $valores) {
            $io->writeln("<info>$campo</info>: " . implode(', ', array_map(fn ($v, $n) => "$v ($n)", array_keys($valores), $valores)));
        }

        $p = $relatorio['pageSize'];
        $io->section('2. pageSize');
        $io->writeln(sprintf('Últimos 30 dias: %s registros com 500, %s com %d → %s. Máximo em um dia: %s.', $p['qtdCom500'] ?? 'erro', $p['qtdComGrande'] ?? 'erro', $p['pageSizeGrande'], $p['conclusao'], $p['maxPorDia'] ?? '-'));

        $c = $relatorio['cancelados'];
        $io->section('3. Ativos x cancelados em ' . $c['dia']);
        $io->writeln(sprintf('Listar sem status: %s | ativos: %s | cancelados: %s | ListarResumido sem status: %s', $c['listarSemStatus'] ?? 'erro', $c['listarAtivos'] ?? 'erro', $c['listarCancelados'] ?? 'erro', $c['resumidoSemStatus'] ?? 'erro'));

        $h = $relatorio['profundidade'];
        $io->section('4. Profundidade do histórico');
        $io->writeln(implode(' | ', array_map(fn ($ano, $s) => "$ano: $s", array_keys($h['porAno']), $h['porAno'])));
        $io->writeln(sprintf('Primeiro ano com dados: %s — primeiro mês: %s', $h['primeiroAno'] ?? 'nenhum', $h['primeiroMes'] ?? '-'));

        if (isset($relatorio['volumePorAno'])) {
            $io->section('5. Volume por ano (ListarResumido)');
            $io->table(['Ano', 'Total', 'Ativos atendidos/liberados (4,5)', 'Situação:estágio'], array_map(
                fn ($ano, $v) => isset($v['erro']) ? [$ano, 'erro', '', $v['erro']] : [$ano, $v['total'], $v['ativosAtendidosOuLiberados'], implode(' ', array_map(fn ($k, $n) => "$k=$n", array_keys($v['porSituacaoEstagio']), $v['porSituacaoEstagio']))],
                array_keys($relatorio['volumePorAno']), $relatorio['volumePorAno']
            ));
        }

        $u = $relatorio['ultimaDataHora'];
        $io->section('6. ultimaDataHora = ' . $u['parametro']);
        $io->writeln(sprintf('%s registros%s. Por mês agendado: %s', $u['qtd'] ?? 'erro', $u['erro'] ? ' — ' . $u['erro'] : '', implode(', ', array_map(fn ($m, $n) => "$m=$n", array_keys($u['porMesAgendado']), $u['porMesAgendado']))));

        $pr = $relatorio['procedimentos'];
        $io->section('7. Procedimentos');
        if (isset($pr['erro'])) {
            $io->writeln('Erro: ' . $pr['erro']);
        } else {
            $io->writeln(sprintf('%d registros, %d procedimentos distintos.', $pr['registros'], $pr['distintos']));
            $io->table(['cod', 'código', 'descrição', 'consulta', 'tipo', 'planos'], array_map(fn ($x) => [
                $x['codProcedimento'], $x['codigoProcedimento'], $x['descricao'], var_export($x['consulta'], true), $x['tipoProcedimento'], $x['planos'],
            ], $pr['lista']));
        }

        $io->success('Relatório salvo em ' . $saida);

        return Command::SUCCESS;
    }
}
