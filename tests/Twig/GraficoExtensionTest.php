<?php

namespace App\Tests\Twig;

use App\Twig\GraficoExtension;
use PHPUnit\Framework\TestCase;

class GraficoExtensionTest extends TestCase
{
    /**
     * @dataProvider escalas
     */
    public function testEscalaEixo(array $args, float $max, float $passo, int $marcas): void
    {
        $e = (new GraficoExtension())->escalaEixo(...$args);

        $this->assertEqualsWithDelta($max, $e['max'], 1e-6);
        $this->assertEqualsWithDelta($passo, $e['passo'], 1e-6);
        $this->assertCount($marcas, $e['marcas']);
        $this->assertSame(0.0, $e['marcas'][0]);
        $this->assertEqualsWithDelta($max, end($e['marcas']), 1e-6, 'A última marca fecha o eixo');
        $this->assertGreaterThanOrEqual($args[0] ?? 0, $e['max'], 'O eixo cobre o maior valor');
    }

    public static function escalas(): array
    {
        return [
            'percentual comum' => [[62.4, 5, 100.0], 80.0, 20.0, 5],
            'percentual alto não passa de 100' => [[97.3, 5, 100.0], 100.0, 20.0, 6],
            'percentual exato em 100' => [[100.0, 5, 100.0], 100.0, 20.0, 6],
            'percentual pequeno usa decimais' => [[0.8, 4, 100.0], 0.8, 0.2, 5],
            'contagem grande' => [[1234, 4, null, true], 1500.0, 500.0, 4],
            'contagem pequena não fraciona' => [[3, 4, null, true], 3.0, 1.0, 4],
            'contagem de um' => [[1, 4, null, true], 1.0, 1.0, 2],
            'sem dados' => [[0, 4, null, true], 1.0, 1.0, 2],
            'nulo' => [[null], 1.0, 1.0, 2],
        ];
    }
}
