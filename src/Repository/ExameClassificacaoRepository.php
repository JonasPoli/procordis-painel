<?php

namespace App\Repository;

use App\Entity\ExameClassificacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExameClassificacao>
 */
class ExameClassificacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExameClassificacao::class);
    }
}
