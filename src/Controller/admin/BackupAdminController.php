<?php

namespace App\Controller\admin;

use App\Service\BackupRestoreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        $diag = null;
        try {
            $diag = $this->backupService->diagnosticarAmbiente();
        } catch (\Throwable $e) {
            $diag = [
                'timestamp' => date('Y-m-d H:i:s'),
                'ambiente' => [
                    'php_version' => PHP_VERSION,
                    'sapi' => PHP_SAPI,
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                    'open_basedir' => ini_get('open_basedir') ?: '(livre)',
                    'extensoes' => [
                        'zip' => class_exists('ZipArchive') ? 'Instalada' : 'Não instalada',
                        'zlib_gz' => function_exists('gzopen') ? 'Instalada' : 'Não instalada',
                        'pdo_mysql' => extension_loaded('pdo_mysql') ? 'Instalada' : 'Não instalada',
                        'json' => function_exists('json_encode') ? 'Instalada' : 'Não instalada',
                        'mbstring' => extension_loaded('mbstring') ? 'Instalada' : 'Não instalada',
                    ],
                ],
                'pastas' => [
                    'var_backup' => ['caminho' => 'var/backup', 'gravavel' => true],
                    'sys_temp' => ['caminho' => sys_get_temp_dir(), 'gravavel' => true],
                ],
                'tabelas' => [],
                'teste_geracao' => ['sucesso' => false, 'erro' => $e->getMessage()],
                'ultimos_logs_erro' => [$e->getMessage()],
            ];
        }

        return $this->render('admin/backup/index.html.twig', [
            'diag' => $diag,
        ]);
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
    public function diagnosticoApi(): Response
    {
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        try {
            $diag = $this->backupService->diagnosticarAmbiente();
            $json = json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            return new Response($json ?: '{"sucesso":false,"erro":"Falha na serialização JSON"}', 200, [
                'Content-Type' => 'application/json; charset=utf-8'
            ]);
        } catch (\Throwable $e) {
            $erroArray = [
                'sucesso' => false,
                'erro' => mb_convert_encoding($e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine(), 'UTF-8', 'UTF-8'),
                'trace' => mb_convert_encoding($e->getTraceAsString(), 'UTF-8', 'UTF-8'),
            ];
            $json = json_encode($erroArray, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return new Response($json ?: '{"sucesso":false,"erro":"Erro fatal"}', 200, [
                'Content-Type' => 'application/json; charset=utf-8'
            ]);
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
