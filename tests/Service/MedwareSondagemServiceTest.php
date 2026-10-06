<?php

namespace App\Tests\Service;

use App\Service\MedwareSondagemService;
use PHPUnit\Framework\TestCase;

/**
 * O relatório de sondagem não pode carregar dados pessoais: só estrutura, formatos mascarados
 * e valores dos campos da lista CAMPOS_SEGUROS.
 */
class MedwareSondagemServiceTest extends TestCase
{
    private function itens(): array
    {
        return [
            [
                'codAgendamento' => 101,
                'dataHoraAgendada' => '05/10/2026 08:00',
                'codStatusAgendamento' => 4,
                'status' => -1,
                'obs' => 'paciente com dor no peito',
                'paciente' => ['codPaciente' => 555, 'nome' => 'MARIA DA SILVA', 'cpf' => '12345678900', 'dataNascimento' => '01/02/1950', 'sexo' => 'F', 'telefones' => [['numero' => '16999990000']]],
                'medico' => ['codMedico' => 7, 'nome' => 'DR FULANO', 'especialidade' => 'CARDIOLOGIA'],
                'procedimentoPlanoOperadora' => ['codProcedimento' => 10, 'descricaoProcedimento' => 'ELETROCARDIOGRAMA', 'consulta' => false, 'codigoTuss' => '40101010'],
            ],
            [
                'codAgendamento' => 102,
                'dataHoraAgendada' => '05/10/2026 09:00',
                'codStatusAgendamento' => 5,
                'status' => 0,
                'obs' => '',
                'paciente' => ['codPaciente' => 556, 'nome' => 'JOSE SOUZA', 'cpf' => null, 'dataNascimento' => '', 'sexo' => 'M', 'telefones' => []],
                'medico' => ['codMedico' => 8, 'nome' => 'DRA BELTRANA', 'especialidade' => 'CARDIOLOGIA'],
                'procedimentoPlanoOperadora' => ['codProcedimento' => 20, 'descricaoProcedimento' => 'CONSULTA', 'consulta' => true, 'codigoTuss' => '10101012'],
            ],
        ];
    }

    public function testEstruturaDescreveCamposSemValores(): void
    {
        $estrutura = MedwareSondagemService::descreverEstrutura($this->itens());

        $this->assertSame(['int'], $estrutura['codAgendamento']['tipos']);
        $this->assertSame('2/2', $estrutura['codAgendamento']['preenchidos']);
        $this->assertSame('1/2', $estrutura['paciente.cpf']['preenchidos']);
        $this->assertEqualsCanonicalizing(['string', 'null'], $estrutura['paciente.cpf']['tipos']);
        $this->assertSame(['99/99/9999 99:99'], $estrutura['dataHoraAgendada']['formatos']);
        $this->assertSame(['99/99/9999'], $estrutura['paciente.dataNascimento']['formatos']);
        $this->assertArrayHasKey('paciente.telefones[].numero', $estrutura);
        $this->assertSame([], $estrutura['paciente.nome']['formatos']);
    }

    public function testRelatorioNaoContemDadosPessoais(): void
    {
        $json = json_encode([
            MedwareSondagemService::descreverEstrutura($this->itens()),
            MedwareSondagemService::valoresSeguros($this->itens()),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['MARIA', 'JOSE', '12345678900', '1950', '16999990000', 'DR FULANO', 'BELTRANA', 'dor no peito', '555'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $json, "Relatório expôs: $proibido");
        }
    }

    public function testValoresSegurosContamDistribuicao(): void
    {
        $valores = MedwareSondagemService::valoresSeguros($this->itens());

        $this->assertSame(['4' => 1, '5' => 1], $valores['codStatusAgendamento']);
        $this->assertSame(["'CARDIOLOGIA'" => 2], $valores['medico.especialidade']);
        $this->assertArrayHasKey("'ELETROCARDIOGRAMA'", $valores['procedimentoPlanoOperadora.descricaoProcedimento']);
        $this->assertArrayNotHasKey('paciente.nome', $valores);
    }

    public function testVolumeResumidoSeparaCanceladosERealizados(): void
    {
        $volume = MedwareSondagemService::volumeResumido([
            ['codAgendamento' => 1, 'codStatusAgendamento' => 4, 'status' => -1],
            ['codAgendamento' => 2, 'codStatusAgendamento' => 5, 'status' => -1],
            ['codAgendamento' => 3, 'codStatusAgendamento' => 4, 'status' => 0],
            ['codAgendamento' => 4, 'codStatusAgendamento' => 6, 'status' => -1],
        ]);

        $this->assertSame(4, $volume['total']);
        $this->assertSame(2, $volume['ativosAtendidosOuLiberados']);
        $this->assertSame(['ativo:4' => 1, 'ativo:5' => 1, 'ativo:6' => 1, 'cancelado:4' => 1], $volume['porSituacaoEstagio']);
    }

    public function testCatalogoAgrupaPorProcedimento(): void
    {
        $catalogo = MedwareSondagemService::catalogoProcedimentos([
            ['codProcedimento' => 10, 'descricaoProcedimento' => 'ECG', 'codPlano' => 1, 'consulta' => false],
            ['codProcedimento' => 10, 'descricaoProcedimento' => 'ECG', 'codPlano' => 2, 'consulta' => false],
            ['codProcedimento' => 20, 'descricaoProcedimento' => 'CONSULTA', 'codPlano' => 1, 'consulta' => true],
        ]);

        $this->assertSame(3, $catalogo['registros']);
        $this->assertSame(2, $catalogo['distintos']);
        $this->assertSame(2, $catalogo['lista'][0]['planos']);
    }
}
