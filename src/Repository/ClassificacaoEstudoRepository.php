<?php

namespace App\Repository;

use App\Entity\ClassificacaoEstudo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClassificacaoEstudo>
 */
class ClassificacaoEstudoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassificacaoEstudo::class);
    }
}
