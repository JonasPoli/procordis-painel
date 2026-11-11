<?php

namespace App\Repository;

use App\Entity\AnamneseTermo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnamneseTermo>
 */
class AnamneseTermoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnamneseTermo::class);
    }

    /** @return list<AnamneseTermo> na ordem de cadastro (a ordem desempata regras em conflito) */
    public function todos(): array
    {
        return $this->findBy([], ['id' => 'ASC']);
    }
}
