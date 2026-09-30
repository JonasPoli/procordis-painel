<?php

namespace App\Controller\admin;

use App\Entity\ClassificacaoEstudo;
use App\Repository\AnamneseSyncExecucaoRepository;
use App\Repository\ClassificacaoEstudoRepository;
use App\Repository\ConfiguracaoIntegracaoRepository;
use App\Service\AnamneseAnaliseService;
use App\Service\AnamneseEstatisticaService;
use App\Service\AnamneseItemService;
use App\Service\AnamneseSimuladorService;
use App\Service\AnamneseSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/anamnese', name: 'app_admin_anamnese_')]
class AnamneseAdminController extends AbstractController
{
    public function __construct(
        private AnamneseEstatisticaService $estatistica,
        private AnamneseAnaliseService $analise,
        private AnamneseItemService $item,
        private ClassificacaoEstudoRepository $classificacaoRepo,
        private AnamneseSyncExecucaoRepository $execucaoRepo,
        private AnamneseSyncService $sync,
        private ConfiguracaoIntegracaoRepository $configRepo,
        private EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        #[Autowire('%kernel.secret%')] private string $segredo,
        #[Autowire('%env(default::PHP_CLI_BINARY)%')] private ?string $phpCli = null,
    ) {
    }

    #[Route('', name: 'painel', methods: ['GET'])]
    public function painel(Request $request): Response
    {
        $filtros = $this->filtros($request);
        $dados = $this->estatistica->obterPainel($filtros);

        return $this->render('admin/anamnese/painel.html.twig', [
            'dados' => $dados,
            'analise' => $this->analise->obter($filtros),
            'filtros' => $filtros,
            'opcoes' => $this->estatistica->opcoesFiltro(),
            'ultimaExecucao' => $this->execucaoRepo->findOneBy([], ['id' => 'DESC']),
            'modoSimulacao' => $this->configRepo->getObterOuCriarConfiguracao()->isModoSimulacao(),
        ]);
    }

    /** Documento formal em folhas A4 (imprimir / salvar em PDF), com os mesmos filtros do painel. */
    #[Route('/relatorio', name: 'relatorio', methods: ['GET'])]
    public function relatorio(Request $request): Response
    {
        $filtros = $this->filtros($request);

        return $this->render('admin/anamnese/relatorio.html.twig', [
            'dados' => $this->estatistica->obterPainel($filtros),
            'analise' => $this->analise->obter($filtros),
            'filtros' => $filtros,
            'tipos' => AnamneseEstatisticaService::TIPOS_ATENDIMENTO,
            'medicos' => $this->estatistica->opcoesFiltro()['medicos'],
            'ultimaExecucao' => $this->execucaoRepo->findOneBy([], ['id' => 'DESC']),
            'modoSimulacao' => $this->configRepo->getObterOuCriarConfiguracao()->isModoSimulacao(),
            'emitidoEm' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/exportar.csv', name: 'exportar', methods: ['GET'])]
    public function exportar(Request $request): StreamedResponse
    {
        $filtros = $this->filtros($request);
        $linhas = $this->estatistica->linhasExportacao($filtros, $this->segredo);

        $resp = new StreamedResponse(function () use ($linhas) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            foreach ($linhas as $l) {
                fputcsv($h, $l, ';');
            }
            fclose($h);
        });
        $nome = sprintf('anamnese_%s_a_%s.csv', $filtros['inicio']->format('Ymd'), $filtros['fim']->format('Ymd'));
        $resp->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $resp->headers->set('Content-Disposition', 'attachment; filename="' . $nome . '"');

        return $resp;
    }

    /** Panorama de um item da anamnese (ex.: Diabetes), com os mesmos filtros do painel. */
    #[Route('/item', name: 'item', methods: ['GET'])]
    public function item(Request $request): Response
    {
        $filtros = $this->filtros($request);

        return $this->render('admin/anamnese/item.html.twig', [
            'p' => $this->item->obter($filtros, $this->itemEscolhido($request)),
            'filtros' => $filtros,
            'opcoes' => $this->estatistica->opcoesFiltro(),
            'modoSimulacao' => $this->configRepo->getObterOuCriarConfiguracao()->isModoSimulacao(),
        ]);
    }

    /** Panorama do item em folhas A4 (imprimir / salvar em PDF). */
    #[Route('/item/relatorio', name: 'item_relatorio', methods: ['GET'])]
    public function itemRelatorio(Request $request): Response
    {
        $filtros = $this->filtros($request);
        $panorama = $this->item->obter($filtros, $this->itemEscolhido($request));
        if (!$panorama['item']) {
            throw $this->createNotFoundException('Item não encontrado.');
        }

        return $this->render('admin/anamnese/item-relatorio.html.twig', [
            'p' => $panorama,
            'filtros' => $filtros,
            'tipos' => AnamneseEstatisticaService::TIPOS_ATENDIMENTO,
            'medicos' => $this->estatistica->opcoesFiltro()['medicos'],
            'ultimaExecucao' => $this->execucaoRepo->findOneBy([], ['id' => 'DESC']),
            'modoSimulacao' => $this->configRepo->getObterOuCriarConfiguracao()->isModoSimulacao(),
            'emitidoEm' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/item/exportar.csv', name: 'item_exportar', methods: ['GET'])]
    public function itemExportar(Request $request): StreamedResponse
    {
        $filtros = $this->filtros($request);
        $panorama = $this->item->obter($filtros, $this->itemEscolhido($request));
        if (!$panorama['item']) {
            throw $this->createNotFoundException('Item não encontrado.');
        }
        $linhas = $this->item->linhasExportacao($panorama);

        $resp = new StreamedResponse(function () use ($linhas) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            foreach ($linhas as $l) {
                fputcsv($h, $l, ';');
            }
            fclose($h);
        });
        $arquivo = sprintf('anamnese_item_%d_%s_a_%s.csv', $panorama['item']['id'], $filtros['inicio']->format('Ymd'), $filtros['fim']->format('Ymd'));
        $resp->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $resp->headers->set('Content-Disposition', 'attachment; filename="' . $arquivo . '"');

        return $resp;
    }

    /** Tabela de um bloco das análises complementares, com os mesmos filtros do painel. */
    #[Route('/exportar-tabela.csv', name: 'exportar_tabela', methods: ['GET'])]
    public function exportarTabela(Request $request): StreamedResponse
    {
        $filtros = $this->filtros($request);
        $tabelas = $this->analise->tabelas($this->analise->obter($filtros));
        $nome = (string) $request->query->get('tabela');
        if (!isset($tabelas[$nome])) {
            throw $this->createNotFoundException('Tabela desconhecida.');
        }
        $linhas = $tabelas[$nome]['linhas'];

        $resp = new StreamedResponse(function () use ($linhas) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            foreach ($linhas as $l) {
                fputcsv($h, $l, ';');
            }
            fclose($h);
        });
        $arquivo = sprintf('anamnese_%s_%s_a_%s.csv', $nome, $filtros['inicio']->format('Ymd'), $filtros['fim']->format('Ymd'));
        $resp->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $resp->headers->set('Content-Disposition', 'attachment; filename="' . $arquivo . '"');

        return $resp;
    }

    #[Route('/catalogo', name: 'catalogo', methods: ['GET'])]
    public function catalogo(): Response
    {
        $contagens = [];
        foreach ($this->em->getConnection()->fetchAllAssociative(
            'SELECT classificacao_id AS id, COUNT(*) AS n, COUNT(DISTINCT paciente_id) AS pac, MAX(data_exame) AS ultimo FROM exame_classificacao WHERE removido_em IS NULL GROUP BY classificacao_id'
        ) as $r) {
            $contagens[(int) $r['id']] = $r;
        }

        return $this->render('admin/anamnese/catalogo.html.twig', [
            'itens' => $this->classificacaoRepo->findBy([], ['codClassificacao' => 'ASC']),
            'contagens' => $contagens,
            'categorias' => ClassificacaoEstudo::CATEGORIAS,
        ]);
    }

    #[Route('/catalogo/salvar', name: 'catalogo_salvar', methods: ['POST'])]
    public function catalogoSalvar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('anamnese_catalogo', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token inválido.');
        }

        $categorias = (array) $request->request->all('categoria');
        $ativos = (array) $request->request->all('ativo');
        $alterados = 0;
        foreach ($this->classificacaoRepo->findAll() as $c) {
            $id = (string) $c->getId();
            if (isset($categorias[$id]) && $categorias[$id] !== $c->getCategoria()) {
                $c->setCategoria((string) $categorias[$id]);
                $c->setCategoriaManual(true);
                $alterados++;
            }
            $ativo = isset($ativos[$id]);
            if ($ativo !== $c->isAtivo()) {
                $c->setAtivo($ativo);
                $alterados++;
            }
        }
        $this->em->flush();
        $this->addFlash('success', $alterados ? "Catálogo atualizado ({$alterados} alterações)." : 'Nenhuma alteração.');

        return $this->redirectToRoute('app_admin_anamnese_catalogo');
    }

    #[Route('/sincronizacao', name: 'sincronizacao', methods: ['GET'])]
    public function sincronizacao(): Response
    {
        $conn = $this->em->getConnection();

        return $this->render('admin/anamnese/sincronizacao.html.twig', [
            'execucoes' => $this->execucaoRepo->findBy([], ['id' => 'DESC'], 40),
            'emAndamento' => $this->sync->execucaoEmAndamento(),
            'modoSimulacao' => $this->configRepo->getObterOuCriarConfiguracao()->isModoSimulacao(),
            'totais' => [
                'exames' => (int) $conn->fetchOne('SELECT COUNT(DISTINCT cod_agendamento) FROM exame_classificacao WHERE removido_em IS NULL'),
                'pacientes' => (int) $conn->fetchOne('SELECT COUNT(DISTINCT paciente_id) FROM exame_classificacao WHERE removido_em IS NULL'),
                'marcacoes' => (int) $conn->fetchOne('SELECT COUNT(*) FROM exame_classificacao WHERE removido_em IS NULL'),
                'removidas' => (int) $conn->fetchOne('SELECT COUNT(*) FROM exame_classificacao WHERE removido_em IS NOT NULL'),
                'semAgendamento' => (int) $conn->fetchOne('SELECT COUNT(DISTINCT cod_agendamento) FROM exame_classificacao WHERE removido_em IS NULL AND agendamento_id IS NULL'),
                'alteracoesPaciente' => (int) $conn->fetchOne('SELECT COUNT(*) FROM paciente_historico'),
                'capturas' => (int) $conn->fetchOne('SELECT COUNT(*) FROM api_captura'),
                'capturasMb' => round(((int) $conn->fetchOne('SELECT COALESCE(SUM(LENGTH(payload)), 0) FROM api_captura')) / 1048576, 1),
                'primeiroExame' => $conn->fetchOne('SELECT MIN(data_exame) FROM exame_classificacao WHERE removido_em IS NULL'),
                'ultimoExame' => $conn->fetchOne('SELECT MAX(data_exame) FROM exame_classificacao WHERE removido_em IS NULL'),
            ],
        ]);
    }

    #[Route('/sincronizacao/executar', name: 'sincronizacao_executar', methods: ['POST'])]
    public function sincronizacaoExecutar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('anamnese_sync', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token inválido.');
        }
        $modo = $request->request->get('modo') === 'completo' ? 'completo' : 'incremental';

        if ($this->sync->execucaoEmAndamento()) {
            $this->addFlash('warning', 'Já existe uma sincronização em andamento.');

            return $this->redirectToRoute('app_admin_anamnese_sincronizacao');
        }

        // No servidor (PHP-FPM) o PHP de linha de comando precisa ser informado: PHP_CLI_BINARY no .env.local
        $php = $this->phpCli ?: ((new PhpExecutableFinder())->find(false) ?: 'php');
        $log = $this->projectDir . '/var/log/anamnese-sync-manual.log';
        $cmd = sprintf(
            'nohup %s %s app:anamnese:sync --modo=%s --origem=manual >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($this->projectDir . '/bin/console'),
            $modo,
            escapeshellarg($log)
        );
        Process::fromShellCommandline($cmd, $this->projectDir)->run();

        $this->addFlash('success', 'Sincronização ' . $modo . ' iniciada em segundo plano. Atualize a página para acompanhar.');

        return $this->redirectToRoute('app_admin_anamnese_sincronizacao');
    }

    #[Route('/simulacao/regerar', name: 'simulacao_regerar', methods: ['POST'])]
    public function simulacaoRegerar(Request $request, AnamneseSimuladorService $simulador): Response
    {
        if (!$this->isCsrfTokenValid('anamnese_sync', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token inválido.');
        }
        if (!$this->configRepo->getObterOuCriarConfiguracao()->isModoSimulacao()) {
            $this->addFlash('danger', 'Só é possível gerar dados simulados com a integração em modo simulação.');

            return $this->redirectToRoute('app_admin_anamnese_sincronizacao');
        }
        @set_time_limit(300);
        $simulador->removerBaseSimulada();
        $r = $simulador->gerarBase();
        $this->addFlash('success', sprintf('Base simulada gerada: %d pacientes, %d exames, %d marcações.', $r['pacientes'], $r['exames'], $r['classificacoes']));

        return $this->redirectToRoute('app_admin_anamnese_sincronizacao');
    }

    private function itemEscolhido(Request $request): ?int
    {
        return filter_var($request->query->get('item'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }

    /**
     * @return array{inicio: \DateTime, fim: \DateTime, periodo: string, sexo: ?string, faixa: ?string, tipo: ?string, procedimento: ?string, medico: ?int}
     */
    private function filtros(Request $request): array
    {
        $q = $request->query;
        $periodo = (string) $q->get('periodo', '12m');
        $fim = new \DateTime();
        $inicio = match ($periodo) {
            '30d' => (new \DateTime())->modify('-29 days'),
            '6m' => (new \DateTime())->modify('-5 months')->modify('first day of this month'),
            '24m' => (new \DateTime())->modify('-23 months')->modify('first day of this month'),
            'ano' => new \DateTime(date('Y') . '-01-01'),
            default => (new \DateTime())->modify('-11 months')->modify('first day of this month'),
        };

        if ($periodo === 'tudo') {
            $min = $this->em->getConnection()->fetchOne('SELECT MIN(data_exame) FROM exame_classificacao WHERE removido_em IS NULL');
            $inicio = $min ? new \DateTime(substr($min, 0, 10)) : (new \DateTime())->modify('-1 year');
        } elseif ($periodo === 'personalizado') {
            $i = \DateTime::createFromFormat('!Y-m-d', (string) $q->get('inicio'));
            $f = \DateTime::createFromFormat('!Y-m-d', (string) $q->get('fim'));
            if ($i && $f && $i <= $f) {
                $inicio = $i;
                $fim = $f;
            } else {
                $periodo = '12m';
            }
        }

        $sexo = in_array($q->get('sexo'), ['Feminino', 'Masculino'], true) ? $q->get('sexo') : null;
        $faixa = in_array($q->get('faixa'), AnamneseEstatisticaService::FAIXAS, true) ? $q->get('faixa') : null;
        $tipo = array_key_exists((string) $q->get('tipo'), AnamneseEstatisticaService::TIPOS_ATENDIMENTO) ? $q->get('tipo') : null;
        $proc = trim((string) $q->get('procedimento', '')) ?: null;

        return [
            'inicio' => $inicio->setTime(0, 0),
            'fim' => $fim->setTime(23, 59, 59),
            'periodo' => $periodo,
            'sexo' => $sexo,
            'faixa' => $faixa,
            'tipo' => $tipo,
            'procedimento' => $proc,
            'medico' => filter_var($q->get('medico'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
        ];
    }
}
