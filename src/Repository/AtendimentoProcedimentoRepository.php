<?php

namespace App\Repository;

use App\Entity\AtendimentoProcedimento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AtendimentoProcedimento>
 */
class AtendimentoProcedimentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AtendimentoProcedimento::class);
    }
}
