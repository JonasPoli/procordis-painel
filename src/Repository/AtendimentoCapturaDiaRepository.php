<?php

namespace App\Repository;

use App\Entity\AtendimentoCapturaDia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AtendimentoCapturaDia>
 */
class AtendimentoCapturaDiaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AtendimentoCapturaDia::class);
    }

    /**
     * Datas (Y-m-d) já capturadas por completo no intervalo — usadas para retomar a carga de onde parou.
     *
     * @return array<string, true>
     */
    public function datasCompletas(\DateTimeInterface $de, \DateTimeInterface $ate): array
    {
        $datas = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT data FROM atendimento_captura_dia WHERE completo = 1 AND data BETWEEN ? AND ?',
            [$de->format('Y-m-d'), $ate->format('Y-m-d')]
        );

        return array_fill_keys(array_map(fn ($d) => substr((string) $d, 0, 10), $datas), true);
    }

    /** Resumo da camada bruta: dias, dias completos, registros e intervalo coberto. */
    public function resumo(): array
    {
        return $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) dias, COALESCE(SUM(completo), 0) dias_completos, COALESCE(SUM(qtd_registros), 0) registros, MIN(data) primeira_data, MAX(data) ultima_data FROM atendimento_captura_dia'
        ) ?: [];
    }
}
