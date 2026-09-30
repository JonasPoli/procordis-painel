<?php

namespace App\Tests\Service;

use App\Service\AnamneseEstatisticaService;
use App\Service\AnamneseItemService;
use App\Tests\Support\AnamneseAmostra;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Panorama de um item sobre uma base pequena, com respostas conferidas à mão (item: Diabetes).
 */
class AnamneseItemServiceTest extends TestCase
{
    private const DIABETES = 2;

    private const CATALOGO = [
        1 => ['nome' => 'HIPERTENSÃO', 'categoria' => 'comorbidade', 'dose' => null],
        2 => ['nome' => 'DIABETES', 'categoria' => 'comorbidade', 'dose' => null],
        3 => ['nome' => 'INFARTO PRÉVIO', 'categoria' => 'comorbidade', 'dose' => null],
        6 => ['nome' => 'TABAGISMO', 'categoria' => 'fator_risco', 'dose' => null],
        7 => ['nome' => 'VACINA DA COVID DOSE 1', 'categoria' => 'vacina', 'dose' => 1],
        13 => ['nome' => 'DIABETE', 'categoria' => 'comorbidade', 'dose' => null],
    ];

    private AnamneseItemService $servico;
    private array $exames;
    private array $p;

    protected function setUp(): void
    {
        $this->exames = [
            // Paciente 1 (F, 65): diabetes aparece na segunda anamnese
            101 => self::exame(1, '2026-01-10 08:00:00', [1], 'Feminino', 65),
            102 => self::exame(1, '2026-03-10 08:00:00', [1, 2, 7], 'Feminino', 65),
            // Paciente 2 (M, 72): diabetes e infarto
            103 => self::exame(2, '2026-02-01 09:00:00', [2, 3], 'Masculino', 72),
            // Paciente 3 (F, 30): sem diabetes
            105 => self::exame(3, '2026-02-05 10:00:00', [6], 'Feminino', 30),
            // Paciente 4 (M, 50): diabetes na primeira anamnese e não repetida na segunda
            106 => self::exame(4, '2026-02-20 11:00:00', [1, 2], 'Masculino', 50),
            107 => self::exame(4, '2026-03-25 11:00:00', [1], 'Masculino', 50),
        ];
        $this->servico = new AnamneseItemService(new AnamneseEstatisticaService($this->createMock(EntityManagerInterface::class)));
        $this->p = $this->calcular(self::DIABETES);
    }

    public function testIndicadoresDoItem(): void
    {
        $this->assertSame('Diabetes', $this->p['item']['nome']);
        $this->assertSame(4, $this->p['pacientes']);
        $this->assertSame(3, $this->p['com']);
        $this->assertSame(1, $this->p['sem']);
        $this->assertSame(75.0, $this->p['prevalencia']);
        $this->assertSame(1, $this->p['posicao']);
        $this->assertSame(3, $this->p['examesCom'], 'Exames em que o item foi marcado');
        $this->assertSame(50.0, $this->p['pctExames']);
        $this->assertSame(1, $this->p['novos']);
    }

    public function testComparaQuemTemComQuemNaoTem(): void
    {
        $com = $this->p['perfilCom'];

        $this->assertSame(62.3, $com['idadeMedia']);
        $this->assertSame(65.0, $com['idadeMediana']);
        $this->assertSame(33.3, $com['pctFeminino']);
        $this->assertSame(1.0, $com['outrasMedia'], 'O próprio item não conta entre as outras comorbidades');
        $this->assertSame(0.0, $com['pct2Outras']);
        $this->assertSame(33.3, $com['pctEvento']);
        $this->assertSame(30.0, $this->p['perfilSem']['idadeMediana']);
        $this->assertSame(0.0, $this->p['perfilSem']['outrasMedia']);
        $this->assertSame([0, 3, 0, 0, 0], $this->p['outras']['com']['n']);
    }

    public function testPrevalenciaPorFaixaSexoEMes(): void
    {
        $faixa6069 = $this->p['porFaixa'][5];

        $this->assertSame('60–69', $faixa6069['faixa']);
        $this->assertSame(['n' => 1, 'total' => 1, 'pct' => null], $faixa6069['Feminino'], 'Grupo com menos de 10 pacientes fica sem percentual');
        $this->assertSame(['Feminino', 'Masculino'], array_column($this->p['porSexo'], 'nome'));
        $this->assertSame([50.0, 100.0], array_column($this->p['porSexo'], 'pct'));
        $this->assertSame([1, 3, 2], $this->p['mensal']['pacientes']);
        $this->assertSame([0, 2, 1], $this->p['mensal']['com']);
        $this->assertSame([null, null, null], $this->p['mensal']['pct']);
        $this->assertSame([0, 0, 1], $this->p['mensal']['novos']);
    }

    public function testItensAssociadosECombinacoes(): void
    {
        $this->assertSame(
            [['Hipertensão', 2, 66.7, 0, 0.0, 66.7], ['Infarto Prévio', 1, 33.3, 0, 0.0, 33.3], ['Tabagismo', 0, 0.0, 1, 100.0, -100.0]],
            array_map(fn ($a) => [$a['nome'], $a['nCom'], $a['pctCom'], $a['nSem'], $a['pctSem'], $a['diferenca']], $this->p['associados'])
        );
        $this->assertSame(
            [['itens' => ['Hipertensão'], 'n' => 2, 'pct' => 66.7], ['itens' => ['Infarto Prévio'], 'n' => 1, 'pct' => 33.3]],
            $this->p['combinacoes']
        );
        $this->assertSame(['Cardiometabólico com histórico cardiovascular' => 1, 'Cardiometabólico' => 2], array_filter(array_column($this->p['perfis'], 'n', 'nome')));
    }

    public function testConsistenciaEntreAnamneses(): void
    {
        $k = $this->p['consistencia'];

        $this->assertSame(2, $k['acompanhados']);
        $this->assertSame(0, $k['emTodas']);
        $this->assertSame(1, $k['apareceuDepois']);
        $this->assertSame(59.0, $k['diasAteAparecer']);
        $this->assertSame(1, $k['naoRepetido']);
    }

    public function testGruposEItensParecidos(): void
    {
        $this->assertSame([['nome' => 'Dr. A', 'n' => 3, 'total' => 4, 'pct' => 75.0, 'pequena' => true]], $this->p['porMedico']);
        $this->assertSame('Eletrocardiograma', $this->p['porProcedimento'][0]['nome']);
        $this->assertSame([['id' => 13, 'nome' => 'Diabete', 'n' => 0, 'motivo' => 'Termos que costumam designar a mesma condição']], $this->p['semelhantes']);
    }

    public function testSemItemDevolveSoAsOpcoes(): void
    {
        $semEscolha = $this->calcular(null);
        $semPacientes = $this->calcular(13);

        $this->assertNull($semEscolha['item']);
        $this->assertSame(['Diabetes', 'Hipertensão', 'Infarto Prévio', 'Tabagismo', 'Vacina da Covid Dose 1'], array_column($semEscolha['opcoes'], 'nome'));
        $this->assertSame(0, $semPacientes['com']);
        $this->assertArrayNotHasKey('prevalencia', $semPacientes);
        $this->assertNull($this->calcular(999)['item']);
    }

    public function testExportacaoEAmostraGrande(): void
    {
        $linhas = $this->servico->linhasExportacao($this->p);
        $this->assertSame(['item', 'Diabetes'], $linhas[0]);
        $this->assertContains(['pacientes_com_o_item', 3], $linhas);
        $this->assertContains(['Hipertensão', 'Comorbidade', 2, '66,7', 0, '0,0', '66,7'], $linhas);

        // Base maior: os totais fecham e nada quebra
        $b = AnamneseAmostra::base();
        $p = $this->servico->calcular($b['catalogo'], $b['examesP'], $b['novos'], $b['meses'], 2);
        $this->assertSame($p['pacientes'], $p['com'] + $p['sem']);
        $this->assertSame($p['com'], array_sum($p['outras']['com']['n']));
        $this->assertSame($p['com'], array_sum(array_column($p['perfis'], 'n')));
        $this->assertNotEmpty($this->servico->linhasExportacao($p));
    }

    private function calcular(?int $item): array
    {
        return $this->servico->calcular(self::CATALOGO, $this->exames, [['ag' => 102, 'cid' => self::DIABETES]], ['2026-01', '2026-02', '2026-03'], $item);
    }

    private static function exame(int $pid, string $dt, array $cids, string $sexo, int $idade): array
    {
        return [
            'pid' => $pid,
            'dt' => $dt,
            'mes' => substr($dt, 0, 7),
            'sexo' => $sexo,
            'idade' => $idade,
            'faixa' => AnamneseEstatisticaService::FAIXAS[max(0, min(7, intdiv($idade, 10) - 1))],
            'proc' => 'ELETROCARDIOGRAMA',
            'tipo' => 'sus',
            'medico' => 9,
            'medicoNome' => 'Dr. A',
            'temAgendamento' => true,
            'temProcedimento' => true,
            'cids' => array_fill_keys($cids, true),
        ];
    }
}
