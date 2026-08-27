<?php

namespace App\Controller\admin;

use App\Repository\AgendamentoRepository;
use App\Repository\ConfiguracaoIntegracaoRepository;
use App\Repository\LogSyncApiRepository;
use App\Repository\MedicoRepository;
use App\Service\MedwareApiClientService;
use App\Service\SystemHealthCheckService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class DashController extends AbstractController
{
    public function __construct(
        private SystemHealthCheckService $healthService,
        private AgendamentoRepository $agendamentoRepo,
        private MedicoRepository $medicoRepo,
        private LogSyncApiRepository $logRepo,
        private ConfiguracaoIntegracaoRepository $configRepo,
        private MedwareApiClientService $medwareClient
    ) {
    }

    #[Route('/', name: 'admin_dash', methods: ['GET'])]
    public function dashboard(): Response
    {
        $infoSync = $this->healthService->obterInfoUltimoSync();
        $stats = $this->healthService->obterEstatisticasBanco();
        $config = $this->configRepo->getObterOuCriarConfiguracao();
        $ultimosLogs = $this->logRepo->findBy([], ['id' => 'DESC'], 10);
        $metricasHoje = $this->agendamentoRepo->getResumoMetricasHoje();

        // Variáveis e Intervalos de Sincronismo do Sistema
        $variaveisSincronismo = [
            [
                'nome' => 'Throttle de Auto-Sync no Backend',
                'chave' => 'AUTO_SYNC_THROTTLE',
                'intervalo' => '10 segundos',
                'intervaloMs' => 10000,
                'camada' => 'Backend (PHP / Symfony)',
                'badge' => 'Essencial',
                'corBadge' => 'emerald',
                'descricao' => 'Evita sobrecarga na API Medware. Se uma requisição pública chegar antes de 10s do último sync, lê o cache do MySQL local; se ultrapassar 10s, busca um lote novo na API automaticamente.',
                'impacto' => 'Protege a infraestrutura do Medware contra excesso de conexões mantendo os painéis sempre atualizados.'
            ],
            [
                'nome' => 'Telão TV de Chamadas (TV)',
                'chave' => 'PAINEL_CHAMADA_POLLING',
                'intervalo' => '1,5 segundos',
                'intervaloMs' => 1500,
                'camada' => 'Frontend (Browser Telão)',
                'badge' => 'Tempo Real Crítico',
                'corBadge' => 'cyan',
                'descricao' => 'Atualiza a última senha chamada no telão e dispara o efeito sonoro de campainha (chime) imediatamente após a chamada.',
                'impacto' => 'Tempo de resposta imediato para o paciente na sala de espera.'
            ],
            [
                'nome' => 'Fila de Espera & Triagem',
                'chave' => 'PAINEL_ESPERA_POLLING',
                'intervalo' => '2,0 segundos',
                'intervaloMs' => 2000,
                'camada' => 'Frontend (Browser Fila/Triagem)',
                'badge' => 'Fila Ativa',
                'corBadge' => 'indigo',
                'descricao' => 'Atualiza a lista de pacientes presentes, médicos em atendimento e transferências de etapa de triagem.',
                'impacto' => 'Visão fluida da recepção e consultórios.'
            ],
            [
                'nome' => 'Pré-Atendimento (SLA & Aguardando)',
                'chave' => 'PAINEL_AGUARDANDO_POLLING',
                'intervalo' => '2,5 segundos',
                'intervaloMs' => 2500,
                'camada' => 'Frontend (Browser Painel SLA)',
                'badge' => 'Monitor de SLA',
                'corBadge' => 'amber',
                'descricao' => 'Recalcula o tempo de espera acumulado de cada paciente, atualiza as cores dos alertas de SLA (verde, amarelo, vermelho) e os KPIs médios.',
                'impacto' => 'Monitoramento contínuo de estouro de SLA de atendimento.'
            ],
            [
                'nome' => 'Dashboard Executivo & Pós-Atendimento',
                'chave' => 'DASHBOARD_POLLING',
                'intervalo' => '3,0 segundos',
                'intervaloMs' => 3000,
                'camada' => 'Frontend (Browser Dashboard)',
                'badge' => 'Analítico',
                'corBadge' => 'sky',
                'descricao' => 'Atualiza gráficos de throughput por hora, volume por procedimento e estatísticas de pós-atendimento.',
                'impacto' => 'Visão analítica gerencial consolidada.'
            ],
            [
                'nome' => 'Worker Daemon em Background (CLI)',
                'chave' => 'CLI_SYNC_COMMAND',
                'intervalo' => '5,0 segundos (opcional)',
                'intervaloMs' => 5000,
                'camada' => 'Console CLI (app:sync-medware --loop)',
                'badge' => 'Serviço Daemon',
                'corBadge' => 'purple',
                'descricao' => 'Processo contínuo que pode rodar em background no servidor para sincronizar periodicamente mesmo sem nenhum usuário navegando.',
                'impacto' => 'Garante banco sempre sincronizado 24/7.'
            ],
        ];

        // Calcular métricas de qualidade das comunicações recentes
        $logsRecentes = $this->logRepo->findBy([], ['id' => 'DESC'], 50);
        $totalLogs = count($logsRecentes);
        $totalSucessoLogs = 0;
        $somaLatencia = 0;
        $maiorLatencia = 0;

        foreach ($logsRecentes as $l) {
            if ($l->getHttpStatus() === 200) {
                $totalSucessoLogs++;
            }
            $tempo = $l->getTempoRespostaMs() ?? 0;
            $somaLatencia += $tempo;
            if ($tempo > $maiorLatencia) {
                $maiorLatencia = $tempo;
            }
        }

        $taxaSucesso = $totalLogs > 0 ? (int) round(($totalSucessoLogs / $totalLogs) * 100) : 100;
        $mediaLatencia = $totalLogs > 0 ? (int) round($somaLatencia / $totalLogs) : ($infoSync['tempoRespostaMs'] ?? 0);

        // Qualidade da conexão / velocidade
        $qualidadeConexao = 'Excelente';
        $corQualidade = 'emerald';
        if ($mediaLatencia > 500) {
            $qualidadeConexao = 'Lenta / Instável';
            $corQualidade = 'rose';
        } elseif ($mediaLatencia > 250) {
            $qualidadeConexao = 'Boa';
            $corQualidade = 'amber';
        }

        return $this->render('admin/dash/dashboard.html.twig', [
            'infoSync' => $infoSync,
            'stats' => $stats,
            'config' => $config,
            'ultimosLogs' => $ultimosLogs,
            'metricasHoje' => $metricasHoje,
            'variaveisSincronismo' => $variaveisSincronismo,
            'qualidade' => [
                'taxaSucesso' => $taxaSucesso,
                'mediaLatencia' => $mediaLatencia,
                'maiorLatencia' => $maiorLatencia,
                'qualidadeConexao' => $qualidadeConexao,
                'corQualidade' => $corQualidade,
                'totalAmostras' => $totalLogs,
            ],
        ]);
    }
}
