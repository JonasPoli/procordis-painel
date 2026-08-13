<?php

namespace App\Repository;

use App\Entity\SenhaAtendimento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SenhaAtendimento>
 */
class SenhaAtendimentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SenhaAtendimento::class);
    }
}
