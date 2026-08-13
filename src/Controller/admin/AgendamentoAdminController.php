<?php

namespace App\Controller\admin;

use App\Repository\AgendamentoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/agendamento', name: 'app_admin_agendamento_')]
class AgendamentoAdminController extends AbstractController
{
    public function __construct(
        private AgendamentoRepository $agendamentoRepo
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $agendamentos = $this->agendamentoRepo->findBy([], ['id' => 'DESC'], 100);

        return $this->render('admin/agendamento/index.html.twig', [
            'agendamentos' => $agendamentos,
        ]);
    }
}
