<?php

namespace App\Repository;

use App\Entity\AtendimentoCategoria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AtendimentoCategoria>
 */
class AtendimentoCategoriaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AtendimentoCategoria::class);
    }
}
