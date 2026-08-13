<?php

namespace App\Repository;

use App\Entity\Agendamento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agendamento>
 */
class AgendamentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agendamento::class);
    }

    /**
     * Retorna os pacientes aguardando atendimento ordenados pelo tempo de espera (horário de chegada).
     */
    public function findAguardandoAtendimento(?int $medicoId = null, ?int $especialidadeId = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.paciente', 'p')->addSelect('p')
            ->leftJoin('a.medico', 'm')->addSelect('m')
            ->leftJoin('a.especialidade', 'e')->addSelect('e')
            ->leftJoin('a.setorSala', 's')->addSelect('s')
            ->where('a.status IN (:statuses)')
            ->setParameter('statuses', ['aguardando_triagem', 'em_triagem', 'aguardando_medico', 'em_exame'])
            ->orderBy('a.prioridade', 'DESC')
            ->addOrderBy('a.horarioChegada', 'ASC');

        if ($medicoId) {
            $qb->andWhere('m.id = :medicoId')->setParameter('medicoId', $medicoId);
        }

        if ($especialidadeId) {
            $qb->andWhere('e.id = :espId')->setParameter('espId', $especialidadeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Retorna o agendamento do paciente que está aguardando há mais tempo.
     */
    public function findPacienteMaiorTempoEspera(): ?Agendamento
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.paciente', 'p')->addSelect('p')
            ->leftJoin('a.medico', 'm')->addSelect('m')
            ->leftJoin('a.especialidade', 'e')->addSelect('e')
            ->where('a.status IN (:statuses)')
            ->setParameter('statuses', ['aguardando_triagem', 'aguardando_medico'])
            ->andWhere('a.horarioChegada IS NOT NULL')
            ->orderBy('a.horarioChegada', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retorna contagens resumidas por status do dia.
     */
    public function getResumoMetricasHoje(): array
    {
        $hojeInicio = new \DateTime('today midnight');
        $hojeFim = new \DateTime('today 23:59:59');

        $rows = $this->createQueryBuilder('a')
            ->select('a.status, COUNT(a.id) as total')
            ->where('a.dataHoraAgendada BETWEEN :inicio AND :fim')
            ->setParameter('inicio', $hojeInicio)
            ->setParameter('fim', $hojeFim)
            ->groupBy('a.status')
            ->getQuery()
            ->getResult();

        $map = [
            'agendado' => 0,
            'aguardando_triagem' => 0,
            'em_triagem' => 0,
            'aguardando_medico' => 0,
            'em_consulta' => 0,
            'em_exame' => 0,
            'finalizado' => 0,
            'cancelado' => 0,
            'ausente' => 0,
            'desistencia' => 0,
        ];

        foreach ($rows as $r) {
            if (isset($map[$r['status']])) {
                $map[$r['status']] = (int) $r['total'];
            }
        }

        return $map;
    }
}
