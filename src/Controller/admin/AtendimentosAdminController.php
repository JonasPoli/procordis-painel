<?php

namespace App\Controller\admin;

use App\Entity\AtendimentoCategoria;
use App\Repository\AtendimentoCapturaDiaRepository;
use App\Repository\AtendimentoCategoriaRepository;
use App\Repository\AtendimentoProcedimentoRepository;
use App\Repository\AtendimentoRepository;
use App\Service\AtendimentoConsolidacaoService;
use App\Service\AtendimentoHistoricoService;
use App\Service\AtendimentoSerieService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Painel administrativo dos atendimentos realizados (base do gráfico público do site).
 * Ver docs/integracao-medware-atendimentos.md.
 */
#[Route('/admin/atendimentos', name: 'app_admin_atendimentos_')]
class AtendimentosAdminController extends AbstractController
{
    public function __construct(
        private AtendimentoSerieService $series,
        private AtendimentoCapturaDiaRepository $capturaRepo,
        private AtendimentoRepository $atendimentoRepo,
        private AtendimentoCategoriaRepository $categoriaRepo,
        private AtendimentoProcedimentoRepository $procedimentoRepo,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'painel', methods: ['GET'])]
    public function painel(Request $request): Response
    {
        $agrupamento = (string) $request->query->get('agrupamento', 'mes');
        if (!isset(AtendimentoSerieService::AGRUPAMENTOS[$agrupamento])) {
            $agrupamento = 'mes';
        }
        $de = $this->data($request->query->get('de'));
        $ate = $this->data($request->query->get('ate'));
        $serie = $this->series->serie($agrupamento, $de, $ate, false);

        return $this->render('admin/atendimentos/painel.html.twig', [
            'serie' => $serie,
            'agrupamento' => $agrupamento,
            'indicadores' => $this->atendimentoRepo->indicadores(new \DateTimeImmutable($serie['historico']['ultimaData'] ?? 'yesterday')),
            'captura' => $this->capturaRepo->resumo(),
            'problemas' => $this->capturaRepo->diasComProblema(20),
            'categoriasOcultas' => array_map(fn (AtendimentoCategoria $c) => $c->getSlug(), $this->categoriaRepo->findBy(['exibirNoSite' => false])),
        ]);
    }

    /** Recaptura os últimos N dias e consolida (mesma rotina do cron, sob demanda). */
    #[Route('/atualizar', name: 'atualizar', methods: ['POST'])]
    public function atualizar(Request $request, AtendimentoHistoricoService $historico, AtendimentoConsolidacaoService $consolidacao): Response
    {
        if (!$this->isCsrfTokenValid('atendimentos_atualizar', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token inválido, tente novamente.');

            return $this->redirectToRoute('app_admin_atendimentos_painel');
        }

        $dias = min(60, max(1, (int) $request->request->get('dias', 7)));
        set_time_limit(300);
        $ontem = new \DateTimeImmutable('yesterday');
        $captura = $historico->capturarPeriodo($ontem->modify('-' . ($dias - 1) . ' days'), $ontem, ['refazer' => true, 'pausaMs' => 100]);
        $r = $consolidacao->processarPendentes();

        $this->addFlash(
            $captura['erros'] || $captura['incompletos'] ? 'warning' : 'success',
            sprintf('%d dias recapturados (%d registros, %d com problema) e %d dias consolidados.', $captura['capturados'], $captura['registros'], count($captura['erros']) + count($captura['incompletos']), $r['dias'])
        );

        return $this->redirectToRoute('app_admin_atendimentos_painel');
    }

    #[Route('/procedimentos', name: 'procedimentos', methods: ['GET'])]
    public function procedimentos(): Response
    {
        return $this->render('admin/atendimentos/procedimentos.html.twig', [
            'procedimentos' => $this->procedimentoRepo->findBy([], ['codProcedimento' => 'ASC']),
            'categorias' => $this->categoriaRepo->findBy([], ['ordem' => 'ASC', 'nome' => 'ASC']),
            'contagens' => $this->atendimentoRepo->realizadosPorProcedimento(),
        ]);
    }

    #[Route('/procedimentos/salvar', name: 'procedimentos_salvar', methods: ['POST'])]
    public function procedimentosSalvar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('atendimentos_procedimentos', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token inválido, tente novamente.');

            return $this->redirectToRoute('app_admin_atendimentos_procedimentos');
        }

        $categorias = [];
        foreach ($this->categoriaRepo->findAll() as $c) {
            $categorias[$c->getId()] = $c;
            $categorias[$c->getSlug()] = $c;
        }
        $escolhas = $request->request->all('categoria');
        $alterados = 0;
        foreach ($this->procedimentoRepo->findAll() as $p) {
            $valor = (string) ($escolhas[$p->getId()] ?? '');
            if ($valor === 'auto') {
                $nova = $categorias[AtendimentoConsolidacaoService::classificar($p->getDescricao(), $p->isConsulta())] ?? null;
                $manual = false;
            } elseif (isset($categorias[(int) $valor])) {
                $nova = $categorias[(int) $valor];
                $manual = $nova !== $p->getCategoria() || $p->isCategoriaManual();
            } else {
                continue;
            }
            if ($nova !== $p->getCategoria() || $manual !== $p->isCategoriaManual()) {
                $p->setCategoria($nova)->setCategoriaManual($manual);
                $alterados++;
            }
        }
        $this->em->flush();
        $this->series->invalidar();
        $this->addFlash('success', $alterados . ' procedimento(s) atualizados.');

        return $this->redirectToRoute('app_admin_atendimentos_procedimentos');
    }

    #[Route('/categorias', name: 'categorias', methods: ['GET'])]
    public function categorias(): Response
    {
        return $this->render('admin/atendimentos/categorias.html.twig', [
            'categorias' => $this->categoriaRepo->findBy([], ['ordem' => 'ASC', 'nome' => 'ASC']),
        ]);
    }

    #[Route('/categorias/salvar', name: 'categorias_salvar', methods: ['POST'])]
    public function categoriasSalvar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('atendimentos_categorias', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token inválido, tente novamente.');

            return $this->redirectToRoute('app_admin_atendimentos_categorias');
        }

        $dados = $request->request->all('cat');
        foreach ($this->categoriaRepo->findAll() as $c) {
            $d = $dados[$c->getId()] ?? null;
            if (!is_array($d)) {
                continue;
            }
            $this->preencher($c, $d);
        }

        $nova = $request->request->all('nova');
        $slug = trim(strtolower((string) ($nova['slug'] ?? '')));
        if ($slug !== '' && trim((string) ($nova['nome'] ?? '')) !== '') {
            if (!preg_match('/^[a-z0-9-]{2,60}$/', $slug) || $this->categoriaRepo->findOneBy(['slug' => $slug])) {
                $this->addFlash('warning', 'Identificador inválido ou já usado: use letras minúsculas, números e hífen.');
            } else {
                $c = (new AtendimentoCategoria())->setSlug($slug);
                $this->preencher($c, $nova);
                $this->em->persist($c);
            }
        }

        $this->em->flush();
        $this->series->invalidar();
        $this->addFlash('success', 'Categorias salvas.');

        return $this->redirectToRoute('app_admin_atendimentos_categorias');
    }

    private function preencher(AtendimentoCategoria $c, array $d): void
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome !== '') {
            $c->setNome(mb_substr($nome, 0, 100));
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($d['cor'] ?? ''))) {
            $c->setCor(strtolower($d['cor']));
        }
        $c->setOrdem((int) ($d['ordem'] ?? $c->getOrdem()));
        $c->setExibirNoSite(!empty($d['site']));
    }

    private function data(mixed $valor): ?\DateTimeImmutable
    {
        return is_string($valor) && $valor !== '' ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $valor) ?: null) : null;
    }
}
