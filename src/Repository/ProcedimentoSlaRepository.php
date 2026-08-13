<?php

namespace App\Repository;

use App\Entity\ProcedimentoSla;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcedimentoSla>
 */
class ProcedimentoSlaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcedimentoSla::class);
    }
}
