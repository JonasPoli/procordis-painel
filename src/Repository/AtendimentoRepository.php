<?php

namespace App\Repository;

use App\Entity\Atendimento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Atendimento>
 */
class AtendimentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Atendimento::class);
    }

    /** Indicadores do painel: histórico total e últimos 12 meses completos até $ate. */
    public function indicadores(\DateTimeInterface $ate): array
    {
        $de = \DateTimeImmutable::createFromInterface($ate)->modify('-12 months')->modify('+1 day');

        return $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT
                COALESCE(SUM(realizado), 0) realizados_total,
                COALESCE(SUM(realizado AND data >= :de), 0) realizados_12m,
                COUNT(DISTINCT CASE WHEN realizado = 1 AND data >= :de THEN cod_paciente END) pacientes_12m,
                COALESCE(SUM(cancelado AND data >= :de), 0) cancelados_12m,
                COALESCE(SUM(NOT cancelado AND cod_status_agendamento = 6 AND data >= :de), 0) faltas_12m,
                COUNT(*) agendamentos_total
             FROM atendimento WHERE data <= :ate',
            ['de' => $de->format('Y-m-d'), 'ate' => $ate->format('Y-m-d')]
        ) ?: [];
    }

    /** Realizados por procedimento (para o catálogo do admin). @return array<int, array{total: int, ultimo: ?string}> */
    public function realizadosPorProcedimento(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT procedimento_id, SUM(realizado) total, MAX(CASE WHEN realizado = 1 THEN data END) ultimo FROM atendimento WHERE procedimento_id IS NOT NULL GROUP BY procedimento_id'
        );
        $mapa = [];
        foreach ($linhas as $l) {
            $mapa[(int) $l['procedimento_id']] = ['total' => (int) $l['total'], 'ultimo' => $l['ultimo']];
        }

        return $mapa;
    }
}
