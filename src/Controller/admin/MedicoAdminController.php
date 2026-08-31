<?php

namespace App\Controller\admin;

use App\Repository\EspecialidadeRepository;
use App\Repository\MedicoRepository;
use App\Repository\SetorSalaRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Service\DataSimulatorService;

#[Route('/admin/medico', name: 'app_admin_medico_')]
class MedicoAdminController extends AbstractController
{
    public function __construct(
        private MedicoRepository $medicoRepo,
        private EspecialidadeRepository $especialidadeRepo,
        private SetorSalaRepository $setorSalaRepo,
        private DataSimulatorService $simulatorService
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        // Limpar automaticamente médicos duplicados se existirem
        $this->simulatorService->deduplicarMedicosBanco();

        $medicos = $this->medicoRepo->findAll();
        $especialidades = $this->especialidadeRepo->findAll();
        $salas = $this->setorSalaRepo->findAll();

        return $this->render('admin/medico/index.html.twig', [
            'medicos' => $medicos,
            'especialidades' => $especialidades,
            'salas' => $salas,
        ]);
    }
}
