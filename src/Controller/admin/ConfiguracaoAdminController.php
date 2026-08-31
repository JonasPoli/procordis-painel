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
            $action = $request->request->get('action', 'save');

            if ($action === 'sync') {
                $res = $this->medwareClient->sincronizarAgendamentosHoje();
                if (isset($res['erro'])) {
                    $this->addFlash('danger', 'Erro na sincronização: ' . $res['erro']);
                } else {
                    $this->addFlash('success', "Sincronização realizada! {$res['total']} registros processados ({$res['novos']} novos, {$res['atualizados']} atualizados).");
                }
            } elseif ($action === 'import_history') {
                $dtInicioStr = $request->request->get('dataInicio', date('Y-m-d', strtotime('-1 year')));
                $dtFimStr = $request->request->get('dataFim', date('Y-m-d'));

                $dtInicio = \DateTime::createFromFormat('Y-m-d', $dtInicioStr) ?: (new \DateTime())->modify('-1 year');
                $dtFim = \DateTime::createFromFormat('Y-m-d', $dtFimStr) ?: new \DateTime();

                $res = $this->medwareClient->sincronizarPeriodoHistorico($dtInicio, $dtFim);
                $this->addFlash('success', "Importação Histórica Concluída! {$res['diasProcessados']} dia(s) processados, totalizando {$res['total']} registros ({$res['novos']} novos e {$res['atualizados']} atualizados).");
            } else {
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

                if (!$modoSimulacao && $usuario && ($senha || $config->getApiSenha())) {
                    $sucesso = $this->medwareClient->autenticar();
                    if ($sucesso) {
                        $this->addFlash('success', 'Conexão com a API Medware estabelecida com sucesso!');
                        // Sincroniza logo após autenticar
                        $res = $this->medwareClient->sincronizarAgendamentosHoje();
                        if (!isset($res['erro'])) {
                            $this->addFlash('info', "Sincronização inicial concluída ({$res['total']} registros).");
                        }
                    } else {
                        $this->addFlash('danger', 'Falha ao autenticar na API Medware. Verifique as credenciais.');
                    }
                }
            }

            return $this->redirectToRoute('app_admin_configuracao_index');
        }

        return $this->render('admin/configuracao/index.html.twig', [
            'config' => $config,
            'logs' => $logs,
        ]);
    }
}
