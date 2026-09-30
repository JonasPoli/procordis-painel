<?php

namespace App\Tests\Controller;

use App\Entity\ClassificacaoEstudo;
use App\Service\AnamneseAnaliseService;
use App\Service\AnamneseEstatisticaService;
use App\Tests\Support\AnamneseAmostra;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class AnamneseAdminControllerTest extends WebTestCase
{
    /**
     * @dataProvider rotas
     */
    public function testRotasExigemLogin(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        $this->assertTrue(in_array($client->getResponse()->getStatusCode(), [302, 401], true), 'Área de anamnese deve exigir autenticação');
    }

    public static function rotas(): array
    {
        return [
            ['/admin/anamnese'],
            ['/admin/anamnese/catalogo'],
            ['/admin/anamnese/sincronizacao'],
            ['/admin/anamnese/exportar.csv'],
            ['/admin/anamnese/relatorio'],
            ['/admin/anamnese/exportar-tabela.csv?tabela=perfis'],
        ];
    }

    /**
     * @dataProvider tamanhosDoCatalogo
     */
    public function testRelatorioA4PaginaATabelaCompleta(int $itens, int $folhas): void
    {
        $html = $this->renderizarRelatorio(self::dadosDeExemplo($itens));

        $this->assertSame($folhas, substr_count($html, '<section class="folha">'));
        $this->assertStringContainsString("Página 1 de {$folhas}", $html);
        $this->assertStringContainsString("Página {$folhas} de {$folhas}", $html);
        $this->assertSame(13, substr_count($html, '<svg class="grafico"'), 'Os 13 gráficos são desenhados em SVG no servidor');
        $this->assertSame(9, substr_count($html, '<table class="matriz">'));
        $this->assertStringNotContainsString('chart.js', $html, 'O relatório não depende do Chart.js');
        $this->assertSame($itens, substr_count($html, '<tr class="item">'), 'Todos os itens aparecem na tabela completa');
        foreach (['Perfil cardiovascular registrado', 'Evolução entre anamneses do mesmo paciente', 'Multimorbidade por faixa etária', 'Multimorbidade por sexo', 'Perfil clínico por sexo e faixa etária', 'Perfis clínicos agrupados', 'Carga clínica por procedimento', 'Associação entre comorbidades', 'Evolução mensal da carga clínica', 'Novos registros por tipo de condição', 'Qualidade e completude dos dados'] as $secao) {
            $this->assertStringContainsString($secao, $html);
        }
    }

    public static function tamanhosDoCatalogo(): array
    {
        return [
            'uma folha de tabela' => [36, 16],
            'duas folhas de tabela' => [37, 17],
            'segunda folha cheia' => [72, 17],
            'três folhas de tabela' => [73, 18],
        ];
    }

    public function testRelatorioA4SemDadosTemUmaFolha(): void
    {
        $dados = self::dadosDeExemplo(0);
        $dados['kpis']['pacientes'] = 0;

        $html = $this->renderizarRelatorio($dados);

        $this->assertSame(1, substr_count($html, '<section class="folha">'));
        $this->assertStringContainsString('Nenhuma anamnese encontrada', $html);
        $this->assertStringContainsString('Página 1 de 1', $html);
        $this->assertStringNotContainsString('<svg class="grafico"', $html);
    }

    public function testRelatorioA4DescreveRecorteEAvisaSimulacao(): void
    {
        $html = $this->renderizarRelatorio(self::dadosDeExemplo(30), ['sexo' => 'Feminino', 'tipo' => 'sus', 'medico' => 7], true);

        $this->assertStringContainsString('Sexo: Feminino · Atendimento: SUS · Médico: Dra. Helena Vasconcelos', $html);
        $this->assertStringContainsString('DADOS SIMULADOS', $html);
    }

    public function testPainelMostraAsAnalisesComplementares(): void
    {
        static::bootKernel();
        $container = static::getContainer();
        $container->get('request_stack')->push(Request::create('/admin/anamnese', 'GET', ['periodo' => '6m', 'medico' => '7']));
        $amostra = AnamneseAmostra::base();

        $html = $container->get('twig')->render('admin/anamnese/painel.html.twig', [
            'dados' => self::dadosDeExemplo(30),
            'analise' => $container->get(AnamneseAnaliseService::class)->calcular($amostra['catalogo'], $amostra['examesP'], $amostra['novos'], $amostra['meses']),
            'filtros' => ['inicio' => new \DateTime('2026-04-01'), 'fim' => new \DateTime('2026-09-30'), 'periodo' => '6m', 'sexo' => null, 'faixa' => null, 'tipo' => null, 'procedimento' => null, 'medico' => 7],
            'opcoes' => ['procedimentos' => [], 'medicos' => [7 => 'Dra. Helena Vasconcelos'], 'faixas' => AnamneseEstatisticaService::FAIXAS, 'tipos' => AnamneseEstatisticaService::TIPOS_ATENDIMENTO, 'primeiraData' => '2025-01-01', 'ultimaData' => '2026-09-30'],
            'ultimaExecucao' => null,
            'modoSimulacao' => false,
        ]);

        foreach (['bloco-cardiovascular', 'bloco-evolucao', 'bloco-multi-faixa', 'bloco-multi-sexo', 'bloco-sexo-faixa', 'bloco-perfis', 'bloco-procedimentos', 'bloco-associacao', 'bloco-carga-mensal', 'bloco-novos-tipo', 'bloco-qualidade'] as $bloco) {
            $this->assertStringContainsString('id="' . $bloco . '"', $html);
        }
        $this->assertStringContainsString('<option value="7" selected>Dra. Helena Vasconcelos</option>', $html, 'Filtro por médico');
        $this->assertStringContainsString('/admin/anamnese/exportar-tabela.csv?periodo=6m&amp;medico=7&amp;tabela=perfis', $html, 'Exportação mantém os filtros');
        $this->assertStringContainsString('/admin/anamnese/relatorio?periodo=6m&amp;medico=7', $html, 'Relatório A4 mantém os filtros');
        $this->assertStringNotContainsString('escore de risco', mb_strtolower($html));
    }

    private function renderizarRelatorio(array $dados, array $recorte = [], bool $modoSimulacao = false): string
    {
        static::bootKernel();
        $amostra = $dados['kpis']['pacientes'] > 0 ? AnamneseAmostra::base() : ['catalogo' => [], 'examesP' => [], 'novos' => [], 'meses' => []];

        return static::getContainer()->get('twig')->render('admin/anamnese/relatorio.html.twig', [
            'dados' => $dados,
            'analise' => static::getContainer()->get(AnamneseAnaliseService::class)->calcular($amostra['catalogo'], $amostra['examesP'], $amostra['novos'], $amostra['meses']),
            'medicos' => [7 => 'Dra. Helena Vasconcelos'],
            'filtros' => $recorte + [
                'inicio' => new \DateTime('2025-10-01 00:00:00'),
                'fim' => new \DateTime('2026-09-30 23:59:59'),
                'periodo' => '12m',
                'sexo' => null,
                'faixa' => null,
                'tipo' => null,
                'procedimento' => null,
                'medico' => null,
            ],
            'tipos' => AnamneseEstatisticaService::TIPOS_ATENDIMENTO,
            'ultimaExecucao' => null,
            'modoSimulacao' => $modoSimulacao,
            'emitidoEm' => new \DateTimeImmutable('2026-09-30 14:35:00'),
        ]);
    }

    /**
     * Dados fictícios no mesmo formato de AnamneseEstatisticaService::obterPainel(), sem banco.
     * Usa o pior caso de tamanho: 12 meses, 4 tipos de atendimento, 8 procedimentos, 10 comorbidades.
     */
    public static function dadosDeExemplo(int $itens): array
    {
        $pacientes = 3482;
        $base = [
            ['Hipertensão Arterial Sistêmica', 'comorbidade', 61.8], ['Vacina Covid Dose 3', 'vacina', 48.9],
            ['Dislipidemia', 'comorbidade', 44.2], ['Sedentarismo', 'fator_risco', 37.5],
            ['Diabetes Mellitus Tipo 2', 'comorbidade', 28.7], ['Uso de Betabloqueador', 'medicacao', 24.1],
            ['Histórico Familiar de Doença Cardiovascular', 'fator_risco', 22.6], ['Obesidade', 'comorbidade', 19.3],
            ['Dor Torácica', 'sintoma', 17.8], ['Tabagismo', 'fator_risco', 14.4],
            ['Doença Arterial Coronariana', 'comorbidade', 12.9], ['Dispneia aos Esforços', 'sintoma', 11.2],
            ['Fibrilação Atrial', 'comorbidade', 8.6], ['Insuficiência Cardíaca', 'comorbidade', 7.4],
            ['IAM Prévio', 'comorbidade', 6.1], ['Doença Renal Crônica', 'comorbidade', 4.8],
            ['AVC Prévio', 'comorbidade', 3.9], ['Etilismo', 'fator_risco', 3.2], ['Marca-passo', 'outros', 2.1],
        ];
        $prevalencia = [];
        for ($i = 0; $i < $itens; $i++) {
            [$nome, $categoria, $pct] = $base[$i] ?? ['Item de Catálogo ' . ($i + 1), 'outros', round(40 / ($i + 1), 1)];
            $prevalencia[] = ['id' => $i + 1, 'nome' => $nome, 'categoria' => $categoria, 'n' => (int) round($pct * $pacientes / 100), 'pct' => $pct];
        }

        $comorbidades = ['Hipertensão Arterial Sistêmica', 'Dislipidemia', 'Diabetes Mellitus Tipo 2', 'Obesidade', 'Doença Arterial Coronariana', 'Fibrilação Atrial', 'Insuficiência Cardíaca', 'IAM Prévio', 'Doença Renal Crônica', 'AVC Prévio'];
        $topo = ['Hipertensão Arterial Sistêmica', 'Dislipidemia', 'Sedentarismo', 'Diabetes Mellitus Tipo 2', 'Uso de Betabloqueador', 'Histórico Familiar de Doença Cardiovascular', 'Obesidade', 'Dor Torácica', 'Tabagismo', 'Doença Arterial Coronariana'];
        $meses = ['out/25', 'nov/25', 'dez/25', 'jan/26', 'fev/26', 'mar/26', 'abr/26', 'mai/26', 'jun/26', 'jul/26', 'ago/26', 'set/26'];

        // Percentuais determinísticos (sem aleatoriedade), decrescentes por linha e variando por coluna
        $grade = fn (array $linhas, int $colunas, float $inicio) => array_map(
            fn (string $nome, int $i) => ['nome' => $nome, 'valores' => array_map(fn (int $j) => round(max(0.4, $inicio / (1 + $i * 0.45) * (0.55 + (($i * 3 + $j * 5) % 9) / 10)), 1), range(0, $colunas - 1))],
            $linhas,
            array_keys($linhas)
        );

        $matriz = [];
        foreach ($comorbidades as $a => $_) {
            foreach ($comorbidades as $b => $_) {
                $matriz[$a][$b] = $a === $b ? null : round(72 / (1 + $b * 0.5) * (0.6 + (($a + $b) % 5) / 10), 1);
            }
        }

        $tendencia = $grade(array_slice($comorbidades, 0, 5), 12, 58);
        $tendencia[4]['valores'][2] = null; // mês com menos de 20 pacientes
        $tendencia[4]['valores'][3] = null;

        $ranking = fn (array $nomes) => array_map(
            fn (string $nome, int $i) => ['nome' => $nome, 'total' => 900 - $i * 95, 'com' => (int) round((900 - $i * 95) * (0.93 - $i * 0.06)), 'pct' => round((0.93 - $i * 0.06) * 100, 1)],
            $nomes,
            array_keys($nomes)
        );
        $procedimentos = ['Ecocardiograma Transtorácico', 'Eletrocardiograma', 'Teste Ergométrico', 'Holter 24 Horas', 'MAPA 24 Horas', 'Ecocardiograma com Estresse Farmacológico', 'Cintilografia de Perfusão Miocárdica', 'Doppler de Carótidas e Vertebrais'];

        $com = [412, 398, 301, 377, 405, 431, 446, 452, 418, 463, 470, 389];
        $sem = [88, 74, 102, 69, 61, 58, 49, 51, 66, 40, 37, 45];

        return [
            'kpis' => [
                'pacientes' => $pacientes,
                'exames' => 4962,
                'cobertura' => 87.0,
                'mediaComorbidades' => 1.84,
                'multimorbidadePct' => 52.3,
                'novosDiagnosticos' => 318,
                'itemMaisPrevalente' => $prevalencia[0] ?? null,
                'pacientesRetorno' => 1127,
            ],
            'prevalencia' => $prevalencia,
            'multimorbidade' => ['rotulos' => [0, 1, 2, 3, 4, '5+'], 'valores' => [612, 1049, 903, 541, 262, 115], 'pct' => [17.6, 30.1, 25.9, 15.5, 7.5, 3.3]],
            'combinacoes' => array_map(
                fn (int $i) => ['itens' => array_slice($comorbidades, 0, 2 + $i % 4), 'n' => 420 - $i * 45, 'pct' => round((420 - $i * 45) * 100 / $pacientes, 1)],
                range(0, 8)
            ),
            'coocorrencia' => ['itens' => $comorbidades, 'matriz' => $matriz],
            'porSexo' => ['grupos' => ['Feminino', 'Masculino'], 'totais' => [1846, 1636], 'itens' => $grade($topo, 2, 60)],
            'porFaixa' => ['grupos' => AnamneseEstatisticaService::FAIXAS, 'totais' => [38, 142, 287, 498, 801, 912, 584, 220], 'itens' => $grade($topo, 8, 60)],
            'porTipo' => ['grupos' => array_values(AnamneseEstatisticaService::TIPOS_ATENDIMENTO), 'totais' => [2710, 1594, 431, 227], 'itens' => $grade(array_slice($topo, 0, 8), 4, 60)],
            'porProcedimento' => ['grupos' => $procedimentos, 'totais' => [1320, 1104, 766, 590, 451, 322, 240, 169], 'itens' => $grade(array_slice($topo, 0, 8), 8, 60)],
            'piramide' => ['faixas' => AnamneseEstatisticaService::FAIXAS, 'Feminino' => [17, 80, 161, 270, 431, 478, 297, 112], 'Masculino' => [21, 62, 126, 228, 370, 434, 287, 108]],
            'vacina' => [
                'temDados' => true,
                'rotulos' => ['Não declarada', '1ª dose', '2ª dose', '3ª dose', '4ª dose', '5ª dose'],
                'faixas' => AnamneseEstatisticaService::FAIXAS,
                'series' => [
                    [62.0, 41.0, 35.0, 30.0, 26.0, 21.0, 18.0, 16.0],
                    [10.0, 8.0, 6.0, 5.0, 4.0, 3.0, 2.0, 2.0],
                    [18.0, 22.0, 20.0, 17.0, 14.0, 11.0, 9.0, 8.0],
                    [8.0, 20.0, 25.0, 28.0, 29.0, 28.0, 25.0, 22.0],
                    [2.0, 7.0, 11.0, 15.0, 19.0, 24.0, 28.0, 30.0],
                    [0.0, 2.0, 3.0, 5.0, 8.0, 13.0, 18.0, 22.0],
                ],
                'geral' => [940, 139, 487, 905, 662, 349],
                'pctTresMais' => 55.0,
                'pctDeclarada' => 73.0,
            ],
            'tendencia' => ['meses' => $meses, 'series' => $tendencia],
            'novos' => [
                'meses' => $meses,
                'valores' => [19, 24, 12, 27, 31, 22, 35, 29, 26, 33, 38, 22],
                'itens' => array_map(fn (string $nome, int $i) => ['nome' => $nome, 'n' => 74 - $i * 7], $comorbidades, array_keys($comorbidades)),
            ],
            'cobertura' => [
                'total' => 87.0,
                'atendidos' => array_sum($com) + array_sum($sem),
                'comAnamnese' => array_sum($com),
                'mensal' => ['meses' => $meses, 'com' => $com, 'sem' => $sem],
                'porProcedimento' => $ranking($procedimentos),
                'porMedico' => $ranking(['Dra. Ana Beatriz Figueiredo', 'Dr. Carlos Eduardo Nogueira', 'Dra. Helena Vasconcelos', 'Dr. Marcos Antônio Pereira de Albuquerque', 'Dra. Renata Sampaio', 'Dr. Tiago Lacerda']),
            ],
            'categorias' => ClassificacaoEstudo::CATEGORIAS,
        ];
    }
}
