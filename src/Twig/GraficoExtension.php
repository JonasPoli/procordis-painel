<?php
declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/*****************
 *  to use in twig:
 *  {% set e = escala_eixo(maiorValor, 5, 100) %}
 *  {{ e.max }} · {{ e.passo }} · {% for m in e.marcas %}…{% endfor %}
******************/

class GraficoExtension extends AbstractExtension
{
    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('escala_eixo', [$this, 'escalaEixo']),
        ];
    }

    /**
     * Eixo com marcas "redondas" (1, 2, 2,5 ou 5 × 10ⁿ) que cobre de 0 até o maior valor.
     *
     * @param int        $alvo    quantidade aproximada de intervalos
     * @param float|null $teto    limite do eixo (ex.: 100 em percentuais)
     * @param bool       $inteiro marcas sempre inteiras (contagens)
     *
     * @return array{max: float, passo: float, marcas: list<float>}
     */
    public function escalaEixo(int|float|null $maior, int $alvo = 5, ?float $teto = null, bool $inteiro = false): array
    {
        $maior = (float) $maior;
        if ($maior <= 0) {
            return ['max' => 1.0, 'passo' => 1.0, 'marcas' => [0.0, 1.0]];
        }

        $bruto = $maior / max(1, $alvo);
        $grandeza = 10 ** floor(log10($bruto));
        if ($inteiro) {
            $grandeza = max(1.0, $grandeza);
        }

        $passo = 10 * $grandeza;
        foreach ($inteiro ? [1, 2, 5] : [1, 2, 2.5, 5] as $m) {
            if ($bruto <= $m * $grandeza + 1e-9) {
                $passo = $m * $grandeza;
                break;
            }
        }

        $max = ceil($maior / $passo - 1e-9) * $passo;
        if ($teto !== null) {
            $max = min($max, $teto);
        }

        $marcas = [];
        for ($i = 0; $i * $passo <= $max + 1e-9; $i++) {
            $marcas[] = round($i * $passo, 6);
        }

        return ['max' => round($max, 6), 'passo' => round($passo, 6), 'marcas' => $marcas];
    }
}
