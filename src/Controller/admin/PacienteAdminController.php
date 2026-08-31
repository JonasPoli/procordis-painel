<?php

namespace App\Controller\admin;

use App\Repository\PacienteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Repository\AgendamentoRepository;
use Symfony\Component\HttpFoundation\Request;

#[Route('/admin/paciente', name: 'app_admin_paciente_')]
class PacienteAdminController extends AbstractController
{
    public function __construct(
        private PacienteRepository $pacienteRepo,
        private AgendamentoRepository $agendamentoRepo
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $busca = trim((string) $request->query->get('busca', ''));

        $qb = $this->pacienteRepo->createQueryBuilder('p')
            ->orderBy('p.id', 'DESC');

        if (!empty($busca)) {
            $qb->andWhere('p.nomeCompleto LIKE :b OR p.cpf LIKE :b OR p.codigoExterno LIKE :b OR p.celular LIKE :b')
               ->setParameter('b', '%' . $busca . '%');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $countQb = clone $qb;
        $totalItems = (int) $countQb->select('COUNT(DISTINCT p.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($totalItems / $limit));

        $pacientes = $qb->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->render('admin/paciente/index.html.twig', [
            'pacientes' => $pacientes,
            'busca' => $busca,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'limit' => $limit,
        ]);
    }

    #[Route('/{id}/prontuario', name: 'prontuario', methods: ['GET'])]
    public function prontuario(int $id): Response
    {
        $paciente = $this->pacienteRepo->find($id);
        if (!$paciente) {
            throw $this->createNotFoundException('Paciente não encontrado.');
        }

        $agendamentos = $this->agendamentoRepo->createQueryBuilder('a')
            ->leftJoin('a.medico', 'm')->addSelect('m')
            ->leftJoin('a.especialidade', 'e')->addSelect('e')
            ->leftJoin('a.historicoEtapas', 'h')->addSelect('h')
            ->where('a.paciente = :paciente')
            ->setParameter('paciente', $paciente)
            ->orderBy('a.dataHoraAgendada', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/paciente/prontuario.html.twig', [
            'paciente' => $paciente,
            'agendamentos' => $agendamentos,
        ]);
    }
}
