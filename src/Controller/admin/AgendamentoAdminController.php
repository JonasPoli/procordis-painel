<?php

namespace App\Controller\admin;

use App\Repository\AgendamentoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use Symfony\Component\HttpFoundation\Request;

#[Route('/admin/agendamento', name: 'app_admin_agendamento_')]
class AgendamentoAdminController extends AbstractController
{
    public function __construct(
        private AgendamentoRepository $agendamentoRepo
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $dtInicioStr = $request->query->get('dataInicio');
        $dtFimStr = $request->query->get('dataFim');
        $statusStr = $request->query->get('status');
        $busca = trim((string) $request->query->get('busca', ''));

        $sort = $request->query->get('sort', 'data');
        $dir = strtolower($request->query->get('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $qb = $this->agendamentoRepo->createQueryBuilder('a')
            ->leftJoin('a.paciente', 'p')->addSelect('p')
            ->leftJoin('a.medico', 'm')->addSelect('m')
            ->leftJoin('a.especialidade', 'e')->addSelect('e');

        switch ($sort) {
            case 'paciente':
                $qb->orderBy('p.nomeCompleto', $dir);
                break;
            case 'procedimento':
                $qb->orderBy('a.procedimentoNome', $dir);
                break;
            case 'medico':
                $qb->orderBy('m.nome', $dir);
                break;
            case 'convenio':
                $qb->orderBy('a.convenioNome', $dir);
                break;
            case 'status':
                $qb->orderBy('a.status', $dir);
                break;
            case 'data':
            default:
                $qb->orderBy('a.dataHoraAgendada', $dir);
                break;
        }

        if (!empty($dtInicioStr)) {
            $dtInicio = \DateTime::createFromFormat('Y-m-d', $dtInicioStr);
            if ($dtInicio) {
                $qb->andWhere('a.dataHoraAgendada >= :dtInicio')
                   ->setParameter('dtInicio', $dtInicio->setTime(0, 0, 0));
            }
        }

        if (!empty($dtFimStr)) {
            $dtFim = \DateTime::createFromFormat('Y-m-d', $dtFimStr);
            if ($dtFim) {
                $qb->andWhere('a.dataHoraAgendada <= :dtFim')
                   ->setParameter('dtFim', $dtFim->setTime(23, 59, 59));
            }
        }

        if (!empty($statusStr)) {
            $qb->andWhere('a.status = :st')
               ->setParameter('st', $statusStr);
        }

        if (!empty($busca)) {
            $qb->andWhere('(p.nomeCompleto LIKE :b OR p.cpf LIKE :b OR a.codigoAgendamento LIKE :b OR a.procedimentoNome LIKE :b OR m.nome LIKE :b)')
               ->setParameter('b', '%' . $busca . '%');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Clone para contar o total exato de registros filtrados
        $countQb = clone $qb;
        $totalItems = (int) $countQb->select('COUNT(DISTINCT a.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($totalItems / $limit));

        $agendamentos = $qb->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->render('admin/agendamento/index.html.twig', [
            'agendamentos' => $agendamentos,
            'dataInicio' => $dtInicioStr,
            'dataFim' => $dtFimStr,
            'status' => $statusStr,
            'busca' => $busca,
            'sort' => $sort,
            'dir' => strtolower($dir),
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'limit' => $limit,
        ]);
    }
}
