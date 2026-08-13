<?php

namespace App\Repository;

use App\Entity\ChamadaTelao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChamadaTelao>
 */
class ChamadaTelaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChamadaTelao::class);
    }

    /**
     * Retorna as últimas chamadas ordenadas por data/hora decrescente para exibição no telão.
     */
    public function findUltimasChamadas(int $limit = 5): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.senha', 's')->addSelect('s')
            ->leftJoin('c.agendamento', 'a')->addSelect('a')
            ->leftJoin('c.medico', 'm')->addSelect('m')
            ->leftJoin('c.setorSala', 'sala')->addSelect('sala')
            ->orderBy('c.dataHoraChamada', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
