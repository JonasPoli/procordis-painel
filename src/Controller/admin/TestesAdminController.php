<?php

namespace App\Controller\admin;

use App\Service\DataSimulatorService;
use App\Service\MedwareApiClientService;
use App\Service\SystemHealthCheckService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/testes', name: 'app_admin_testes_')]
class TestesAdminController extends AbstractController
{
    public function __construct(
        private SystemHealthCheckService $healthService,
        private MedwareApiClientService $medwareClient,
        private DataSimulatorService $simulatorService
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $appBaseUrl = $request->getSchemeAndHttpHost();
        $dadosDiagnostico = $this->healthService->executarTodosTestes($appBaseUrl);

        return $this->render('admin/testes/index.html.twig', [
            'diagnostico' => $dadosDiagnostico,
        ]);
    }

    #[Route('/executar', name: 'executar', methods: ['GET', 'POST'])]
    public function executar(Request $request): JsonResponse
    {
        $appBaseUrl = $request->getSchemeAndHttpHost();
        $dados = $this->healthService->executarTodosTestes($appBaseUrl);

        return new JsonResponse([
            'sucesso' => true,
            'data' => $dados,
        ]);
    }

    #[Route('/sincronizar', name: 'sincronizar', methods: ['POST'])]
    public function sincronizar(): JsonResponse
    {
        $res = $this->medwareClient->sincronizarAgendamentosHoje();
        return new JsonResponse([
            'sucesso' => !isset($res['erro']),
            'resultado' => $res,
        ]);
    }

    #[Route('/simular-passo', name: 'simular_passo', methods: ['POST'])]
    public function simularPasso(): JsonResponse
    {
        $logs = $this->simulatorService->simularPassoMinuto(1);
        return new JsonResponse([
            'sucesso' => true,
            'logs' => $logs,
        ]);
    }
}
