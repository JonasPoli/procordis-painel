<?php

namespace App\Tests\Service;

use App\Service\AnamneseEstatisticaService;
use PHPUnit\Framework\TestCase;

/**
 * Combinações contam quem tem TODOS os itens, com ou sem outros (nunca o conjunto exato).
 */
class AnamneseCombinacoesTest extends TestCase
{
    public function testDuplasETriosContamQuemTemTodos(): void
    {
        $pacientes = [[1, 2], [2, 1, 3], [1, 2, 3, 4], [2, 3], [5]];

        $r = AnamneseEstatisticaService::combinacoesFrequentes($pacientes, [2 => 5, 3 => 3]);

        $this->assertSame([
            ['ids' => [1, 2], 'n' => 3],    // conjunto exato {1,2} seria 1: aqui contam os 3 que têm 1 e 2
            ['ids' => [2, 3], 'n' => 3],
            ['ids' => [1, 3], 'n' => 2],
            ['ids' => [1, 4], 'n' => 1],
            ['ids' => [2, 4], 'n' => 1],
            ['ids' => [1, 2, 3], 'n' => 2],
            ['ids' => [1, 2, 4], 'n' => 1],
            ['ids' => [1, 3, 4], 'n' => 1],
        ], $r);
    }

    public function testItemSozinhoEPacienteSemItens(): void
    {
        $r = AnamneseEstatisticaService::combinacoesFrequentes([[7], [], [7, 7, 8]], [1 => 4, 2 => 4]);

        $this->assertSame([['ids' => [7], 'n' => 2], ['ids' => [8], 'n' => 1], ['ids' => [7, 8], 'n' => 1]], $r);
    }
}
