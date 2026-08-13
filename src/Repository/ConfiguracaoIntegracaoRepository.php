<?php

namespace App\Repository;

use App\Entity\ConfiguracaoIntegracao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfiguracaoIntegracao>
 */
class ConfiguracaoIntegracaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoIntegracao::class);
    }

    public function getObterOuCriarConfiguracao(): ConfiguracaoIntegracao
    {
        $config = $this->findOneBy([]);
        if (!$config) {
            $config = new ConfiguracaoIntegracao();
            $this->getEntityManager()->persist($config);
            $this->getEntityManager()->flush();
        }
        return $config;
    }
}
