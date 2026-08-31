<?php

namespace App\Controller\admin;

use App\Service\MedwareApiClientService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/sincronizacao', name: 'app_admin_sincronizacao_')]
class SincronizacaoAdminController extends AbstractController
{
    public function __construct(
        private MedwareApiClientService $medwareClient
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/sincronizacao/index.html.twig');
    }

    #[Route('/iniciar', name: 'iniciar', methods: ['POST'])]
    public function iniciar(Request $request): JsonResponse
    {
        $dtInicioStr = $request->request->get('dataInicio', date('Y-m-d', strtotime('-6 months')));
        $dtFimStr = $request->request->get('dataFim', date('Y-m-d'));

        $dtInicio = \DateTime::createFromFormat('Y-m-d', $dtInicioStr) ?: (new \DateTime())->modify('-6 months');
        $dtFim = \DateTime::createFromFormat('Y-m-d', $dtFimStr) ?: new \DateTime();

        // Montar a lista de dias a serem processados
        $dias = [];
        $cursor = clone $dtInicio;
        while ($cursor <= $dtFim) {
            $dias[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }

        $totalDias = count($dias);
        // Estimativa média de 0.8s por dia via API
        $estimativaSegundos = (int) round($totalDias * 0.8);

        return new JsonResponse([
            'sucesso' => true,
            'dias' => $dias,
            'totalDias' => $totalDias,
            'estimativaSegundos' => $estimativaSegundos,
        ]);
    }

    #[Route('/processar-lote', name: 'processar_lote', methods: ['POST'])]
    public function processarLote(Request $request): JsonResponse
    {
        $diaStr = $request->request->get('dia');
        if (!$diaStr) {
            return new JsonResponse(['sucesso' => false, 'erro' => 'Dia não informado'], 400);
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $diaStr);
        if (!$dt) {
            return new JsonResponse(['sucesso' => false, 'erro' => 'Formato de data inválido'], 400);
        }

        $res = $this->medwareClient->sincronizarAgendamentosHoje($dt);

        return new JsonResponse([
            'sucesso' => true,
            'dia' => $diaStr,
            'total' => $res['total'] ?? 0,
            'novos' => $res['novos'] ?? 0,
            'atualizados' => $res['atualizados'] ?? 0,
            'erro' => $res['erro'] ?? null
        ]);
    }
}
