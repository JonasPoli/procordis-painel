<?php

namespace App\Controller\admin;

use App\Repository\PacienteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/paciente', name: 'app_admin_paciente_')]
class PacienteAdminController extends AbstractController
{
    public function __construct(
        private PacienteRepository $pacienteRepo
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $pacientes = $this->pacienteRepo->findBy([], ['id' => 'DESC'], 100);

        return $this->render('admin/paciente/index.html.twig', [
            'pacientes' => $pacientes,
        ]);
    }
}
