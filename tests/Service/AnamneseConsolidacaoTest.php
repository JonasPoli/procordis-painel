<?php

namespace App\Tests\Service;

use App\Entity\AnamneseTermo;
use App\Entity\ClassificacaoEstudo;
use App\Entity\Paciente;
use App\Service\AnamneseEstatisticaService;
use App\Service\AnamneseItemService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Os itens de um termo do thesaurus (COVID 2020, 2021, 2022 = "Covid") contam como um só em todas as estatísticas:
 * prevalência, multimorbidade, novos diagnósticos, panorama do item e CSV de pesquisa.
 *
 * Paciente 1: exame 1 com Diabetes + Covid 2020 + Covid 2021; exame 2 com Diabetes + Covid 2022.
 * Paciente 2: Covid 2021.  Paciente 3: Diabetes; depois Diabetes + Derrame (novo diagnóstico).
 */
class AnamneseConsolidacaoTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var array<string, int> nome do item => id */
    private array $ids = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);

        $this->em->persist((new AnamneseTermo())->setNome('Covid')->setCategoria('comorbidade')->setPadroes('COVID ####'));
        $itens = [];
        foreach (['COVID 2020', 'COVID 2021', 'COVID 2022', 'DIABETES', 'DERRAME', 'VACINA DA COVID DOSE 1'] as $i => $nome) {
            $itens[$nome] = (new ClassificacaoEstudo())->setCodClassificacao(10 + $i)->setNome($nome)->setCategoria(ClassificacaoEstudo::sugerirCategoria($nome));
            $this->em->persist($itens[$nome]);
        }
        $pacientes = [];
        foreach ([[1, 'F', '1960-01-01'], [2, 'M', '1970-01-01'], [3, 'F', '1980-01-01']] as [$cod, $sexo, $nasc]) {
            $pacientes[$cod] = (new Paciente())->setNomeCompleto('PACIENTE ' . $cod)->setSexo($sexo)->setDataNascimento(new \DateTime($nasc))->setCodPaciente($cod);
            $this->em->persist($pacientes[$cod]);
        }
        $this->em->flush();
        foreach ($itens as $nome => $c) {
            $this->ids[$nome] = $c->getId();
        }

        $exames = [
            ['AG1', 1, '2026-03-10 09:00:00', ['DIABETES', 'COVID 2020', 'COVID 2021', 'VACINA DA COVID DOSE 1']],
            ['AG2', 1, '2026-06-10 09:00:00', ['DIABETES', 'COVID 2022']],
            ['AG3', 2, '2026-05-05 09:00:00', ['COVID 2021']],
            ['AG4', 3, '2026-04-04 09:00:00', ['DIABETES']],
            ['AG5', 3, '2026-07-07 09:00:00', ['DIABETES', 'DERRAME']],
        ];
        $conn = $this->em->getConnection();
        foreach ($exames as [$ag, $cod, $data, $nomes]) {
            foreach ($nomes as $nome) {
                $conn->insert('exame_classificacao', [
                    'paciente_id' => $pacientes[$cod]->getId(), 'classificacao_id' => $this->ids[$nome], 'cod_agendamento' => $ag,
                    'cod_paciente' => $cod, 'data_exame' => $data, 'primeiro_visto_em' => $data, 'ultimo_visto_em' => $data,
                ]);
            }
        }
    }

    public function testPainelContaOTermoComoUmItem(): void
    {
        $d = static::getContainer()->get(AnamneseEstatisticaService::class)->obterPainel($this->filtros());

        $nomes = array_column($d['prevalencia'], 'nome');
        $this->assertContains('Covid', $nomes);
        $this->assertSame([], array_values(array_filter($nomes, fn ($n) => preg_match('/Covid 20\d\d/', $n))), 'Sem linhas por ano');
        $covid = $d['prevalencia'][array_search('Covid', $nomes, true)];
        $this->assertSame(2, $covid['n'], 'Pacientes 1 e 2, cada um uma vez');
        $this->assertSame(['Covid 2020', 'Covid 2021', 'Covid 2022'], $covid['variantes']);
        $this->assertSame($this->ids['COVID 2020'], $covid['id'], 'Chave do termo = menor id dos seus itens');

        // Paciente 1: Diabetes + Covid = 2 (sem a consolidação seriam 4); paciente 2: 1; paciente 3: 2
        $this->assertSame([0, 1, 2, 0, 0, 0], $d['multimorbidade']['valores']);
        // Covid 2022 no 2º exame do paciente 1 não é novo diagnóstico; Derrame do paciente 3 é
        $this->assertSame(1, $d['kpis']['novosDiagnosticos']);
        $this->assertSame([['nome' => 'Derrame', 'n' => 1]], $d['novos']['itens']);
        $this->assertSame([['id' => $this->ids['COVID 2020'], 'nome' => 'Covid', 'variantes' => ['Covid 2020', 'Covid 2021', 'Covid 2022']]], $d['consolidados']);
        $this->assertTrue($d['vacina']['temDados'], 'A vacina continua fora do termo e no gráfico de dose');
    }

    public function testPanoramaDeUmAnoAbreOTermo(): void
    {
        $item = static::getContainer()->get(AnamneseItemService::class);

        $p = $item->obter($this->filtros(), $this->ids['COVID 2021']);
        $this->assertSame($this->ids['COVID 2020'], $p['item']['id']);
        $this->assertSame('Covid', $p['item']['nome']);
        $this->assertSame(['Covid 2020', 'Covid 2021', 'Covid 2022'], $p['item']['variantes']);
        $this->assertSame(2, $p['com']);

        $diabetes = $item->obter($this->filtros(), $this->ids['DIABETES']);
        $associados = array_column($diabetes['associados'], 'nome');
        $this->assertSame(1, count(array_keys($associados, 'Covid', true)), 'Covid aparece uma vez entre os itens associados ao Diabetes');
        $this->assertSame([], array_values(array_filter($associados, fn ($n) => preg_match('/Covid 20\d\d/', $n))));
        $this->assertSame([], $diabetes['item']['variantes']);
    }

    public function testCombinacoesContamQuemTemTodosEBatemComAMatriz(): void
    {
        $f = $this->filtros();
        $painel = static::getContainer()->get(AnamneseEstatisticaService::class)->obterPainel($f);
        $assoc = static::getContainer()->get(\App\Service\AnamneseAnaliseService::class)->obter($f)['associacao'];
        $i = array_search('Diabetes', $assoc['itens'], true);
        $j = array_search('Covid', $assoc['itens'], true);

        // Paciente 1 tem Diabetes + Covid; o paciente 3 tem Diabetes + Derrame. Cada dupla tem 1 paciente.
        $duplas = array_column(array_map(fn ($c) => ['k' => implode(' + ', $c['itens']), 'pct' => $c['pct'], 'n' => $c['n']], $painel['combinacoes']), null, 'k');
        $this->assertSame(1, $duplas['Diabetes + Covid']['n'] ?? $duplas['Covid + Diabetes']['n']);
        $this->assertSame($assoc['total'][$i][$j], $duplas['Diabetes + Covid']['pct'] ?? $duplas['Covid + Diabetes']['pct'], 'Mesmo % do "% sobre o total" da matriz');

        // No panorama de Diabetes, a Covid aparece com o % condicional da matriz (linha Diabetes → coluna Covid)
        $p = static::getContainer()->get(AnamneseItemService::class)->obter($f, $this->ids['DIABETES']);
        $covid = current(array_filter($p['combinacoes'], fn ($c) => $c['itens'] === ['Covid']));
        $this->assertSame($assoc['condicional'][$i][$j], $covid['pct']);
    }

    public function testCsvDePesquisaTemUmaColunaCovid(): void
    {
        $linhas = iterator_to_array(static::getContainer()->get(AnamneseEstatisticaService::class)->linhasExportacao($this->filtros(), 'segredo'), false);
        $cabecalho = $linhas[0];

        $this->assertContains('Covid', $cabecalho);
        $this->assertSame([], array_values(array_filter($cabecalho, fn ($c) => preg_match('/COVID 20\d\d/', (string) $c))));
        $col = array_search('Covid', $cabecalho, true);
        $porExame = array_column(array_slice($linhas, 1), $col, 0);
        $this->assertSame(['AG1' => 1, 'AG4' => 0, 'AG3' => 1, 'AG2' => 1, 'AG5' => 0], $porExame);
        $qtdComorb = array_column(array_slice($linhas, 1), array_search('qtd_comorbidades', $cabecalho, true), 0);
        $this->assertSame(2, $qtdComorb['AG1'], 'Diabetes + Covid (a vacina não é comorbidade)');
    }

    private function filtros(): array
    {
        return ['inicio' => new \DateTime('2026-01-01 00:00:00'), 'fim' => new \DateTime('2026-12-31 23:59:59')];
    }
}
