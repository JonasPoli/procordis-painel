<?php

namespace App\Tests\Service;

use App\Service\AnamneseCondicoes;
use PHPUnit\Framework\TestCase;

class AnamneseCondicoesTest extends TestCase
{
    /**
     * @dataProvider nomesECondicoes
     */
    public function testCondicaoPeloNomeDoItem(string $nome, ?string $esperada): void
    {
        $this->assertSame($esperada, AnamneseCondicoes::condicao($nome));
    }

    public static function nomesECondicoes(): array
    {
        return [
            ['HIPERTENSÃO', 'hipertensao'],
            ['Hipertensão Arterial Sistêmica', 'hipertensao'],
            ['HAS', 'hipertensao'],
            ['HIPERTENSÃO PULMONAR', null],
            ['DIABETES', 'diabetes'],
            ['DM TIPO 2', 'diabetes'],
            ['PRÉ-DIABETES', null],
            ['INFARTO PRÉVIO', 'infarto'],
            ['IAM', 'infarto'],
            ['HISTÓRICO FAMILIAR DE INFARTO', null],
            ['AVC PRÉVIO', 'avc'],
            ['DERRAME', null],
            ['CATETERISMO', 'cateterismo'],
            ['ANGIOPLASTIA COM STENT', 'angioplastia'],
            ['DISLIPIDEMIA', null],
            ['CHASSI', null],
        ];
    }

    /**
     * @dataProvider nomesETipos
     */
    public function testTipoDeCondicaoDosNovosRegistros(string $nome, string $esperado): void
    {
        $this->assertSame($esperado, AnamneseCondicoes::tipo($nome));
    }

    public static function nomesETipos(): array
    {
        return [
            ['DIABETES', 'cardiometabolico'],
            ['DISLIPIDEMIA', 'cardiometabolico'],
            ['OBESIDADE', 'cardiometabolico'],
            ['INFARTO PRÉVIO', 'cardiovascular'],
            ['ARRITMIA', 'cardiovascular'],
            ['MARCAPASSO', 'cardiovascular'],
            ['DERRAME', 'cardiovascular'],
            ['DOENÇA DE CHAGAS', 'infeccioso_historico'],
            ['HISTÓRICO FAMILIAR DE DOENÇA CARDÍACA', 'infeccioso_historico'],
            ['HIPOTIREOIDISMO', 'outros'],
            ['TABAGISMO', 'outros'],
        ];
    }

    public function testVacinasNaoEntramNasCondicoes(): void
    {
        $mapa = AnamneseCondicoes::itensPorCondicao([
            1 => ['nome' => 'HIPERTENSÃO', 'categoria' => 'comorbidade'],
            2 => ['nome' => 'HAS', 'categoria' => 'comorbidade'],
            3 => ['nome' => 'VACINA DM', 'categoria' => 'vacina'],
        ]);

        $this->assertSame([1, 2], $mapa['hipertensao']);
        $this->assertSame([], $mapa['diabetes']);
        $this->assertSame([], $mapa['cateterismo']);
    }

    public function testSinalizaItensPossivelmenteEquivalentes(): void
    {
        $catalogo = [
            1 => ['nome' => 'AVC', 'categoria' => 'comorbidade'],
            2 => ['nome' => 'DERRAME', 'categoria' => 'comorbidade'],
            3 => ['nome' => 'QUIMIO', 'categoria' => 'comorbidade'],
            4 => ['nome' => 'TRATAMENTO QUIMIO', 'categoria' => 'comorbidade'],
            5 => ['nome' => 'VACINA DA COVID DOSE 1', 'categoria' => 'vacina'],
            6 => ['nome' => 'VACINA DA COVID DOSE 2', 'categoria' => 'vacina'],
            7 => ['nome' => 'DIABETES', 'categoria' => 'comorbidade'],
            8 => ['nome' => 'DIABETE', 'categoria' => 'comorbidade'],
            9 => ['nome' => 'INFARTO', 'categoria' => 'comorbidade'],
            10 => ['nome' => 'HISTÓRICO FAMILIAR DE INFARTO', 'categoria' => 'fator_risco'],
            11 => ['nome' => 'TABAGISMO', 'categoria' => 'fator_risco'],
        ];

        $pares = array_map(fn ($p) => $p['a'] . '-' . $p['b'], AnamneseCondicoes::itensSemelhantes($catalogo));

        $this->assertSame(['1-2', '3-4', '7-8'], $pares);
    }
}
