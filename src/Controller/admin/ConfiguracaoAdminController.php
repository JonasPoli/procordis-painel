<?php

namespace App\Controller\admin;

use App\Repository\ConfiguracaoIntegracaoRepository;
use App\Repository\LogSyncApiRepository;
use App\Service\MedwareApiClientService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/configuracao', name: 'app_admin_configuracao_')]
class ConfiguracaoAdminController extends AbstractController
{
    public function __construct(
        private ConfiguracaoIntegracaoRepository $configRepo,
        private LogSyncApiRepository $logRepo,
        private MedwareApiClientService $medwareClient,
        private EntityManagerInterface $em
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $config = $this->configRepo->getObterOuCriarConfiguracao();
        $logs = $this->logRepo->findBy([], ['id' => 'DESC'], 30);

        if ($request->isMethod('POST')) {
            $baseUrl = $request->request->get('apiBaseUrl');
            $usuario = $request->request->get('apiUsuario');
            $senha = $request->request->get('apiSenha');
            $modoSimulacao = $request->request->get('modoSimulacao') === '1';

            $config->setApiBaseUrl($baseUrl);
            $config->setApiUsuario($usuario);
            if ($senha) {
                $config->setApiSenha($senha);
            }
            $config->setModoSimulacao($modoSimulacao);

            $this->em->flush();
            $this->addFlash('success', 'Configurações de integração atualizadas!');

            if (!$modoSimulacao && $usuario && $senha) {
                $sucesso = $this->medwareClient->autenticar();
                if ($sucesso) {
                    $this->addFlash('success', 'Conexão com a API Medware estabelecida com sucesso!');
                } else {
                    $this->addFlash('danger', 'Falha ao autenticar na API Medware. Verifique as credenciais.');
                }
            }
        }

        return $this->render('admin/configuracao/index.html.twig', [
            'config' => $config,
            'logs' => $logs,
        ]);
    }
}
