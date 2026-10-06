<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Séries de atendimentos realizados por período e categoria (gráficos de linha do admin e do site).
 * Só agregados: nenhum dado de paciente sai daqui.
 *
 * O cache é chaveado pela última consolidação + versão do catálogo (categorias/procedimentos);
 * o admin chama invalidar() ao alterar o catálogo.
 */
class AtendimentoSerieService
{
    public const AGRUPAMENTOS = ['dia' => '%Y-%m-%d', 'mes' => '%Y-%m', 'ano' => '%Y'];
    /** Limite de pontos para não devolver séries diárias gigantes. */
    public const MAX_DIAS = 1100;

    private Connection $conn;

    public function __construct(EntityManagerInterface $em, private CacheInterface $cache)
    {
        $this->conn = $em->getConnection();
    }

    /**
     * @param bool $apenasSite só categorias marcadas para exibir no site
     */
    public function serie(string $agrupamento = 'mes', ?\DateTimeInterface $de = null, ?\DateTimeInterface $ate = null, bool $apenasSite = true): array
    {
        if (!isset(self::AGRUPAMENTOS[$agrupamento])) {
            throw new \InvalidArgumentException('Agrupamento inválido: use dia, mes ou ano.');
        }

        [$primeira, $ultima] = $this->limites();
        $ate = \DateTimeImmutable::createFromInterface($ate ?? ($ultima ?? new \DateTimeImmutable('yesterday')))->setTime(0, 0);
        if ($de === null) {
            $de = $agrupamento === 'dia' ? $ate->modify('-89 days') : ($primeira ?? $ate->modify('-1 year'));
        }
        $de = \DateTimeImmutable::createFromInterface($de)->setTime(0, 0);
        if ($agrupamento === 'dia' && $de < $ate->modify('-' . (self::MAX_DIAS - 1) . ' days')) {
            $de = $ate->modify('-' . (self::MAX_DIAS - 1) . ' days');
        }
        if ($de > $ate) {
            $de = $ate;
        }

        $chave = sprintf('atendimentos_serie_%s_%s_%s_%d_%s', $agrupamento, $de->format('Ymd'), $ate->format('Ymd'), (int) $apenasSite, md5($this->versao() . '|' . $this->versaoCatalogo()));

        return $this->cache->get($chave, function (ItemInterface $item) use ($agrupamento, $de, $ate, $apenasSite, $primeira, $ultima) {
            $item->expiresAfter(86400);

            return $this->montar($agrupamento, $de, $ate, $apenasSite, $primeira, $ultima);
        });
    }

    /** Totais por categoria em todo o histórico (cards de destaque). */
    public function totaisPorCategoria(bool $apenasSite = true): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT c.slug, c.nome, c.cor, COUNT(a.id) total
             FROM atendimento_categoria c
             LEFT JOIN atendimento_procedimento p ON p.categoria_id = c.id
             LEFT JOIN atendimento a ON a.procedimento_id = p.id AND a.realizado = 1
             WHERE (? = 0 OR c.exibir_no_site = 1)
             GROUP BY c.id ORDER BY c.ordem, c.nome',
            [(int) $apenasSite]
        );
    }

    private function montar(string $agrupamento, \DateTimeImmutable $de, \DateTimeImmutable $ate, bool $apenasSite, ?\DateTimeImmutable $primeira, ?\DateTimeImmutable $ultima): array
    {
        $categorias = $this->conn->fetchAllAssociative(
            'SELECT id, slug, nome, cor, exibir_no_site FROM atendimento_categoria WHERE (? = 0 OR exibir_no_site = 1) ORDER BY ordem, nome',
            [(int) $apenasSite]
        );

        $linhas = $this->conn->fetchAllAssociative(
            'SELECT DATE_FORMAT(a.data, ?) periodo, p.categoria_id, COUNT(*) qtd
             FROM atendimento a JOIN atendimento_procedimento p ON p.id = a.procedimento_id
             WHERE a.realizado = 1 AND a.data BETWEEN ? AND ?
             GROUP BY periodo, p.categoria_id',
            [self::AGRUPAMENTOS[$agrupamento], $de->format('Y-m-d'), $ate->format('Y-m-d')]
        );
        $pacientes = $this->conn->fetchAllKeyValue(
            'SELECT DATE_FORMAT(a.data, ?) periodo, COUNT(DISTINCT a.cod_paciente)
             FROM atendimento a JOIN atendimento_procedimento p ON p.id = a.procedimento_id JOIN atendimento_categoria c ON c.id = p.categoria_id
             WHERE a.realizado = 1 AND a.data BETWEEN ? AND ? AND (? = 0 OR c.exibir_no_site = 1)
             GROUP BY periodo',
            [self::AGRUPAMENTOS[$agrupamento], $de->format('Y-m-d'), $ate->format('Y-m-d'), (int) $apenasSite]
        );

        $periodos = self::periodos($agrupamento, $de, $ate);
        $indice = array_flip($periodos);
        $porCategoria = [];
        foreach ($linhas as $l) {
            $porCategoria[(int) $l['categoria_id']][$l['periodo']] = (int) $l['qtd'];
        }

        $series = [];
        $total = array_fill(0, count($periodos), 0);
        foreach ($categorias as $c) {
            $valores = array_fill(0, count($periodos), 0);
            foreach ($porCategoria[(int) $c['id']] ?? [] as $periodo => $qtd) {
                if (isset($indice[$periodo])) {
                    $valores[$indice[$periodo]] = $qtd;
                    // O total segue a regra do site: categorias ocultas não entram.
                    if ($c['exibir_no_site']) {
                        $total[$indice[$periodo]] += $qtd;
                    }
                }
            }
            $series[] = ['slug' => $c['slug'], 'nome' => $c['nome'], 'cor' => $c['cor'], 'exibirNoSite' => (bool) $c['exibir_no_site'], 'total' => array_sum($valores), 'valores' => $valores];
        }

        return [
            'agrupamento' => $agrupamento,
            'de' => $de->format('Y-m-d'),
            'ate' => $ate->format('Y-m-d'),
            'periodos' => $periodos,
            'rotulos' => array_map(fn ($p) => self::rotulo($agrupamento, $p), $periodos),
            'series' => $series,
            'total' => ['nome' => 'Total de atendimentos', 'total' => array_sum($total), 'valores' => $total],
            'pacientes' => ['nome' => 'Pacientes distintos', 'valores' => array_map(fn ($p) => (int) ($pacientes[$p] ?? 0), $periodos)],
            'historico' => ['primeiraData' => $primeira?->format('Y-m-d'), 'ultimaData' => $ultima?->format('Y-m-d')],
            'atualizadoEm' => $this->versao(),
            'regra' => 'Agendamentos ativos com estágio Atendido ou Liberado, pela data agendada.',
        ];
    }

    /** @return string[] chaves de período contínuas (sem buracos) entre $de e $ate */
    public static function periodos(string $agrupamento, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        [$passo, $formato, $inicio] = match ($agrupamento) {
            'dia' => ['+1 day', 'Y-m-d', $de],
            'mes' => ['+1 month', 'Y-m', $de->modify('first day of this month')],
            'ano' => ['+1 year', 'Y', $de->setDate((int) $de->format('Y'), 1, 1)],
        };
        $periodos = [];
        for ($c = $inicio; $c <= $ate; $c = $c->modify($passo)) {
            $periodos[] = $c->format($formato);
        }

        return $periodos;
    }

    public static function rotulo(string $agrupamento, string $periodo): string
    {
        $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

        return match ($agrupamento) {
            'dia' => substr($periodo, 8, 2) . '/' . substr($periodo, 5, 2) . '/' . substr($periodo, 0, 4),
            'mes' => $meses[(int) substr($periodo, 5, 2) - 1] . '/' . substr($periodo, 0, 4),
            default => $periodo,
        };
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} primeira e última data com atendimento realizado */
    private function limites(): array
    {
        $r = $this->conn->fetchAssociative('SELECT MIN(data) primeira, MAX(data) ultima FROM atendimento WHERE realizado = 1') ?: [];

        return [
            !empty($r['primeira']) ? new \DateTimeImmutable($r['primeira']) : null,
            !empty($r['ultima']) ? new \DateTimeImmutable($r['ultima']) : null,
        ];
    }

    /** Força a remontagem das séries (usar depois de mudar categorias ou procedimentos). */
    public function invalidar(): void
    {
        $this->cache->delete('atendimentos_catalogo_versao');
    }

    private function versao(): ?string
    {
        $v = $this->conn->fetchOne('SELECT MAX(processado_em) FROM atendimento_captura_dia');

        return $v ? (string) $v : null;
    }

    private function versaoCatalogo(): string
    {
        return $this->cache->get('atendimentos_catalogo_versao', fn () => uniqid('', true));
    }
}
