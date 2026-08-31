<?php

namespace App\Controller\admin;

use App\Service\BackupRestoreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/backup', name: 'app_admin_backup_')]
class BackupAdminController extends AbstractController
{
    public function __construct(
        private BackupRestoreService $backupService
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/backup/index.html.twig');
    }

    #[Route('/download', name: 'download', methods: ['GET'])]
    public function download(): Response
    {
        $logPath = dirname(__DIR__, 3) . '/var/log/backup.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($logPath, "[$timestamp] [INFO] Iniciando solicitação de download de backup...\n", FILE_APPEND);

        try {
            $caminhoArquivo = $this->backupService->gerarBackup();
            $nomeDownload = 'procordis_backup_total_' . date('Ymd_His') . '.procordis.bak';

            if (!file_exists($caminhoArquivo)) {
                throw new \RuntimeException("Arquivo de backup não encontrado no disco.");
            }

            $tamanho = filesize($caminhoArquivo);
            @file_put_contents($logPath, "[$timestamp] [SUCCESS] Backup gerado com sucesso ($tamanho bytes). Enviando ao cliente...\n", FILE_APPEND);

            $conteudo = file_get_contents($caminhoArquivo);
            @unlink($caminhoArquivo);

            $response = new Response($conteudo);
            $response->headers->set('Content-Type', 'application/octet-stream');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $nomeDownload . '"');
            $response->headers->set('Content-Length', (string) strlen($conteudo));
            $response->headers->set('Cache-Control', 'no-cache, private');
            $response->headers->set('Pragma', 'no-cache');

            return $response;
        } catch (\Throwable $e) {
            $msgErro = "[$timestamp] [ERROR] Falha ao gerar backup: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
            @file_put_contents($logPath, $msgErro, FILE_APPEND);

            $this->addFlash('danger', 'Erro ao gerar backup: ' . $e->getMessage());
            return $this->redirectToRoute('app_admin_backup_index');
        }
    }

    #[Route('/diagnostico-api', name: 'diagnostico_api', methods: ['GET'])]
    public function diagnosticoApi(): JsonResponse
    {
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        try {
            $diag = $this->backupService->diagnosticarAmbiente();
            return new JsonResponse($diag);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'sucesso' => false,
                'erro' => $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ], 200);
        }
    }

    #[Route('/restaurar', name: 'restaurar', methods: ['POST'])]
    public function restaurar(Request $request): Response
    {
        $file = $request->files->get('backupFile');
        $modoLimpo = $request->request->get('modoLimpo') === '1';

        if (!$file) {
            $this->addFlash('danger', 'Nenhum arquivo de backup foi selecionado.');
            return $this->redirectToRoute('app_admin_backup_index');
        }

        try {
            $caminhoTemp = $file->getPathname();
            $res = $this->backupService->restaurarBackup($caminhoTemp, $modoLimpo);

            $totais = $res['totais'];
            $msg = "Restauração concluída com sucesso! ({$totais['users']} logins admin, {$totais['pacientes']} pacientes, {$totais['medicos']} médicos, {$totais['agendamentos']} agendamentos e {$totais['etapasHistorico']} anamneses restauradas).";

            $this->addFlash('success', $msg);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Falha ao restaurar o arquivo de backup: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_backup_index');
    }
}
