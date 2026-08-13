<?php

namespace App\Controller\admin;

use App\Repository\ConfiguracaoIntegracaoRepository;

use App\Service\DataSimulatorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/simulador', name: 'app_admin_simulador_')]
class SimuladorAdminController extends AbstractController
{
    public function __construct(
        private DataSimulatorService $simulatorService,
        private ConfiguracaoIntegracaoRepository $configRepo,
        private EntityManagerInterface $em
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $config = $this->configRepo->getObterOuCriarConfiguracao();
        $logs = [];

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'passo') {
                $logs = $this->simulatorService->simularPassoMinuto(1);
                $this->addFlash('success', 'Passo de 1 minuto simulado com sucesso!');
            } elseif ($action === 'reset') {
                $this->simulatorService->resetarDadosSimulacao();
                $this->addFlash('warning', 'Dados de simulação foram resetados!');
            } elseif ($action === 'toggle_modo') {
                $config->setModoSimulacao(!$config->isModoSimulacao());
                $this->em->flush();
                $this->addFlash('info', 'Modo de fonte de dados alterado para: ' . ($config->isModoSimulacao() ? 'Simulador Local' : 'API Medware Real'));
            }
        }

        return $this->render('admin/simulador/index.html.twig', [
            'config' => $config,
            'logs' => $logs,
        ]);
    }
}
