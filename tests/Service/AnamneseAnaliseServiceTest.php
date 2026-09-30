<?php

namespace App\Tests\Service;

use App\Service\AnamneseAnaliseService;
use App\Service\AnamneseEstatisticaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Cálculo das análises complementares sobre uma base pequena, com respostas conferidas à mão.
 */
class AnamneseAnaliseServiceTest extends TestCase
{
    private const CATALOGO = [
        1 => ['nome' => 'HIPERTENSÃO', 'categoria' => 'comorbidade', 'dose' => null],
        2 => ['nome' => 'DIABETES', 'categoria' => 'comorbidade', 'dose' => null],
        3 => ['nome' => 'INFARTO PRÉVIO', 'categoria' => 'comorbidade', 'dose' => null],
        4 => ['nome' => 'AVC', 'categoria' => 'comorbidade', 'dose' => null],
        5 => ['nome' => 'DERRAME', 'categoria' => 'comorbidade', 'dose' => null],
        6 => ['nome' => 'TABAGISMO', 'categoria' => 'fator_risco', 'dose' => null],
        7 => ['nome' => 'VACINA DA COVID DOSE 1', 'categoria' => 'vacina', 'dose' => 1],
        8 => ['nome' => 'VACINA DA COVID DOSE 2', 'categoria' => 'vacina', 'dose' => 2],
        9 => ['nome' => 'QUIMIO', 'categoria' => 'comorbidade', 'dose' => null],
        10 => ['nome' => 'TRATAMENTO QUIMIO', 'categoria' => 'comorbidade', 'dose' => null],
        11 => ['nome' => 'CATETERISMO', 'categoria' => 'comorbidade', 'dose' => null],
        12 => ['nome' => 'ANGIOPLASTIA', 'categoria' => 'comorbidade', 'dose' => null],
    ];

    private array $a;

    protected function setUp(): void
    {
        $exames = [
            // Paciente 1 (F, 65): duas anamneses em dias diferentes; diabetes aparece na segunda
            101 => self::exame(1, '2026-01-10 08:00:00', [1], 'Feminino', 65),
            102 => self::exame(1, '2026-03-10 08:00:00', [1, 2, 7, 8], 'Feminino', 65),
            // Paciente 2 (M, 72): dois exames iguais no mesmo dia e procedimento
            103 => self::exame(2, '2026-02-01 09:00:00', [1, 3], 'Masculino', 72),
            104 => self::exame(2, '2026-02-01 09:30:00', [1, 3], 'Masculino', 72),
            // Paciente 3 (F, 30): só fator de risco
            105 => self::exame(3, '2026-02-05 10:00:00', [6], 'Feminino', 30),
            // Paciente 4: sem sexo, sem idade e sem agendamento vinculado
            106 => ['proc' => 'SEM AGENDAMENTO VINCULADO', 'tipo' => 'nd', 'medico' => null, 'temAgendamento' => false, 'temProcedimento' => false]
                + self::exame(4, '2026-02-20 11:00:00', [4], 'Não informado', null),
        ];
        $novos = [['ag' => 102, 'cid' => 2]];

        $servico = new AnamneseAnaliseService(new AnamneseEstatisticaService($this->createMock(EntityManagerInterface::class)));
        $this->a = $servico->calcular(self::CATALOGO, $exames, $novos, ['2026-01', '2026-02', '2026-03']);
    }

    public function testContaPacientesUmaVezEResumeACarga(): void
    {
        $this->assertSame(4, $this->a['pacientes']);
        $this->assertSame(6, $this->a['exames']);
        // comorbidades por paciente: 2, 2, 0, 1
        $this->assertSame(1.25, $this->a['geral']['media']);
        $this->assertSame(1.5, $this->a['geral']['mediana']);
        $this->assertSame(50.0, $this->a['geral']['pct2']);
        $this->assertSame(0.0, $this->a['geral']['pct4']);
        $this->assertSame(2, $this->a['geral']['nEvento']);
    }

    public function testPerfilCardiovascularRegistrado(): void
    {
        $c = $this->a['cardio'];

        // condições por paciente: 2 (hipertensão + diabetes), 2 (hipertensão + infarto), 0, 1 (AVC)
        $this->assertSame([1, 1, 2, 0, 0], $c['valores']);
        $this->assertSame([25.0, 25.0, 50.0, 0.0, 0.0], $c['pct']);
        $this->assertSame(3, $c['comAlguma']);
        $this->assertSame(['hipertensao' => 2, 'diabetes' => 1, 'infarto' => 1, 'avc' => 1, 'cateterismo' => 0, 'angioplastia' => 0], array_column($c['condicoes'], 'n', 'chave'));
        $this->assertSame(['Infarto Prévio'], $c['condicoes'][2]['itens'], 'Mostra os itens do catálogo considerados');
        $this->assertSame(['AVC'], $c['condicoes'][3]['itens'], '"Derrame" não é juntado ao AVC automaticamente');
        $this->assertSame([], $c['semItem']);
        $this->assertSame(['Feminino', 'Masculino', 'Não informado'], $c['porSexo']['grupos']);
        $this->assertSame([2, 1, 1], $c['porSexo']['totais']);
        $this->assertNull($c['porSexo']['itens'][0]['valores'][0], 'Grupo com menos de 10 pacientes fica sem percentual');
        $this->assertSame([1, 0, 0], $c['porSexo']['itens'][0]['n']);
    }

    public function testEvolucaoEntreAnamnesesUsaDiasDiferentes(): void
    {
        $e = $this->a['evolucao'];

        $this->assertSame(1, $e['acompanhados'], 'Dois exames no mesmo dia não formam acompanhamento');
        $this->assertSame(0, $e['semAlteracao']);
        $this->assertSame(1, $e['comNovo']);
        $this->assertSame(0, $e['comNaoRepetido']);
        $this->assertSame(1.0, $e['mediaNovos'], 'Vacinas não contam como novo item');
        $this->assertSame(59.0, $e['intervaloMedio']);
        $this->assertSame(59.0, $e['intervaloMediano']);
        $this->assertSame([['nome' => 'Diabetes', 'categoria' => 'Comorbidade', 'n' => 1, 'pct' => 100.0]], $e['itens']);
    }

    public function testPerfisSaoMutuamenteExclusivos(): void
    {
        $perfis = array_column($this->a['perfis'], 'n', 'chave');

        $this->assertSame(4, array_sum($perfis));
        $this->assertSame(1, $perfis['cardiometabolico']);
        $this->assertSame(1, $perfis['cardiometabolico_historico']);
        $this->assertSame(1, $perfis['cardiovascular']);
        $this->assertSame(1, $perfis['baixa_carga']);
    }

    /**
     * @dataProvider perfis
     */
    public function testClassificacaoDoPerfil(int $comorbidades, array $condicoes, string $esperado): void
    {
        $this->assertSame($esperado, AnamneseAnaliseService::classificarPerfil($comorbidades, array_fill_keys($condicoes, true)));
    }

    public static function perfis(): array
    {
        return [
            'sem registros' => [0, [], 'baixa_carga'],
            'uma comorbidade qualquer' => [1, ['diabetes'], 'baixa_carga'],
            'só hipertensão' => [1, ['hipertensao'], 'hipertensivo'],
            'hipertensão e diabetes' => [2, ['hipertensao', 'diabetes'], 'cardiometabolico'],
            'só evento' => [1, ['infarto'], 'cardiovascular'],
            'cateterismo com diabetes' => [2, ['cateterismo', 'diabetes'], 'cardiometabolico_historico'],
            'cinco comorbidades prevalecem' => [5, ['hipertensao', 'avc'], 'alta_multimorbidade'],
            'três comorbidades sem critério' => [3, ['diabetes'], 'demais'],
        ];
    }

    public function testQualidadeSoSinaliza(): void
    {
        $q = $this->a['qualidade'];
        $faltando = array_column($q['campos'], 'faltando', 'campo');

        $this->assertSame(6, $q['anamneses']);
        $this->assertSame(['Idade (data de nascimento)' => 1, 'Sexo' => 1, 'Agendamento vinculado' => 1, 'Procedimento' => 1, 'Tipo de atendimento' => 1, 'Médico' => 1], $faltando);
        $this->assertSame(83.3, $q['campos'][0]['pct']);
        $this->assertSame(2, $q['pacientesRepetidos']);
        $this->assertSame(1, $q['pacientesMesmoDia']);
        $this->assertSame(['grupos' => 1, 'exames' => 2], $q['duplicidades']);
        $this->assertSame(1, $q['multiplasDoses']['exames']);
        $this->assertSame(
            [['AVC', 'Derrame'], ['Quimio', 'Tratamento Quimio']],
            array_map(fn ($s) => [$s['a'], $s['b']], $q['semelhantes'])
        );
    }

    public function testAssociacaoTrazAsTresMedidas(): void
    {
        $as = $this->a['associacao'];
        $h = array_search('Hipertensão', $as['itens'], true);
        $d = array_search('Diabetes', $as['itens'], true);

        $this->assertSame(1, $as['n'][$h][$d]);
        $this->assertSame(50.0, $as['condicional'][$h][$d], 'Dos 2 com hipertensão, 1 tem diabetes');
        $this->assertSame(100.0, $as['condicional'][$d][$h]);
        $this->assertSame(25.0, $as['total'][$h][$d]);
        $this->assertNull($as['n'][$h][$h]);
    }

    public function testMesesComPoucosPacientesNaoSaoMedidos(): void
    {
        $m = $this->a['mensal'];

        $this->assertSame(['jan/26', 'fev/26', 'mar/26'], $m['meses']);
        $this->assertSame([1, 3, 1], $m['pacientes']);
        $this->assertSame([null, null, null], $m['media']);
        $this->assertSame([null, null, null], $m['pctEvento']);
    }

    public function testNovosRegistrosPorTipo(): void
    {
        $nt = $this->a['novosTipo'];

        $this->assertSame(1, $nt['total']);
        $this->assertSame(['cardiometabolico' => 1, 'cardiovascular' => 0, 'infeccioso_historico' => 0, 'outros' => 0], array_column($nt['grupos'], 'total', 'chave'));
        $this->assertSame([0, 0, 1], $nt['grupos'][0]['valores']);
        $this->assertSame('Diabetes', $nt['itens'][0]['nome']);
    }

    public function testTabelasParaExportacao(): void
    {
        $servico = new AnamneseAnaliseService(new AnamneseEstatisticaService($this->createMock(EntityManagerInterface::class)));
        $tabelas = $servico->tabelas($this->a);

        $this->assertSame(
            ['perfil-cardiovascular', 'evolucao-itens', 'multimorbidade-faixa', 'multimorbidade-sexo', 'sexo-faixa', 'perfis', 'carga-procedimento', 'qualidade', 'associacao', 'carga-mensal', 'novos-tipo'],
            array_keys($tabelas)
        );
        $this->assertSame(['condicoes_registradas', 'pacientes', 'pct_pacientes'], $tabelas['perfil-cardiovascular']['linhas'][0]);
        $this->assertSame(['2', 2, '50,0'], $tabelas['perfil-cardiovascular']['linhas'][3]);
        $this->assertSame(['Eletrocardiograma', 5, 3, '1,33', '2,0', 2, '66,7', 0, '0,0', 1, '33,3'], $tabelas['carga-procedimento']['linhas'][1]);
    }

    private static function exame(int $pid, string $dt, array $cids, string $sexo, ?int $idade): array
    {
        return [
            'pid' => $pid,
            'dt' => $dt,
            'mes' => substr($dt, 0, 7),
            'sexo' => $sexo,
            'idade' => $idade,
            'faixa' => match (true) {
                $idade === null => 'Sem idade',
                $idade < 18 => '0–17',
                $idade < 30 => '18–29',
                $idade < 40 => '30–39',
                $idade < 50 => '40–49',
                $idade < 60 => '50–59',
                $idade < 70 => '60–69',
                $idade < 80 => '70–79',
                default => '80+',
            },
            'proc' => 'ELETROCARDIOGRAMA',
            'tipo' => 'sus',
            'medico' => 9,
            'temAgendamento' => true,
            'temProcedimento' => true,
            'cids' => array_fill_keys($cids, true),
        ];
    }
}
