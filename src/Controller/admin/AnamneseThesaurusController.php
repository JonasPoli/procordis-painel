<?php

namespace App\Controller\admin;

use App\Entity\AnamneseTermo;
use App\Entity\ClassificacaoEstudo;
use App\Repository\AnamneseTermoRepository;
use App\Service\AnamneseEstatisticaService;
use App\Service\AnamneseThesaurusService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Thesaurus da anamnese: termos que reúnem itens do catálogo com nomes diferentes (ex.: COVID 2019…2026 = Covid).
 * Nas estatísticas, os itens de um termo contam como um só (ver AnamneseThesaurusService).
 */
#[Route('/admin/anamnese/thesaurus', name: 'app_admin_anamnese_thesaurus')]
class AnamneseThesaurusController extends AbstractController
{
    public function __construct(
        private AnamneseThesaurusService $thesaurus,
        private AnamneseTermoRepository $termoRepo,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $itens = $this->itensComContagem();
        $termos = $this->thesaurus->termos();

        $lista = [];
        foreach ($termos as $tid => $t) {
            $doTermo = array_values(array_filter($itens, fn (array $i) => $i['termo'] === $tid));
            $doses = array_unique(array_filter(array_map(fn (array $i) => $i['categoria'] === 'vacina' ? (new ClassificacaoEstudo())->setNome($i['nome'])->getNumeroDose() : null, $doTermo)));
            $lista[] = $t + [
                'categoriaRotulo' => ClassificacaoEstudo::CATEGORIAS[$t['categoria']] ?? $t['categoria'],
                'itens' => $doTermo,
                'pacientes' => $this->pacientesDoTermo($doTermo),
                'regrasInvalidas' => array_values(array_filter($t['padroes'], fn (string $p) => AnamneseThesaurusService::regex($p) === null)),
                'misturaDoses' => count($doses) > 1,
            ];
        }

        $sugestoes = [];
        foreach ($this->thesaurus->sugestoes() as $s) {
            $sugestoes[] = ['a' => $itens[$s['a']], 'b' => $itens[$s['b']], 'motivo' => $s['motivo']];
        }

        return $this->render('admin/anamnese/thesaurus/index.html.twig', [
            'termos' => $lista,
            'nomesTermos' => array_map(fn (array $t) => $t['nome'], $termos),
            'conflitos' => array_values(array_filter($itens, fn (array $i) => count($i['casam']) > 1)),
            'manuais' => array_values(array_filter($itens, fn (array $i) => $i['manual'])),
            'semTermo' => count(array_filter($itens, fn (array $i) => $i['termo'] === null)),
            'totalItens' => count($itens),
            'sugestoes' => $sugestoes,
        ]);
    }

    #[Route('/novo', name: '_novo', methods: ['GET', 'POST'])]
    public function novo(Request $request): Response
    {
        return $this->formulario($request, new AnamneseTermo());
    }

    #[Route('/{id}', name: '_editar', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editar(Request $request, int $id): Response
    {
        $termo = $this->termoRepo->find($id) ?? throw $this->createNotFoundException('Termo não encontrado.');

        return $this->formulario($request, $termo);
    }

    #[Route('/{id}/excluir', name: '_excluir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function excluir(Request $request, int $id): Response
    {
        $this->validarToken($request);
        $termo = $this->termoRepo->find($id) ?? throw $this->createNotFoundException('Termo não encontrado.');
        $nome = $termo->getNome();
        $this->thesaurus->excluir($termo);
        $this->addFlash('success', "Termo “{$nome}” excluído. Os seus itens voltaram a contar separados.");

        return $this->redirectToRoute('app_admin_anamnese_thesaurus');
    }

    #[Route('/item/{id}/automatico', name: '_item_automatico', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function itemAutomatico(Request $request, int $id): Response
    {
        $this->validarToken($request);
        $this->thesaurus->voltarAoAutomatico($id);
        $this->addFlash('success', 'O item voltou a seguir as regras de nome.');

        return $this->redirectToRoute('app_admin_anamnese_thesaurus');
    }

    private function formulario(Request $request, AnamneseTermo $termo): Response
    {
        $erros = [];
        $marcados = null;
        if ($request->isMethod('POST')) {
            $this->validarToken($request);
            $r = $request->request;
            $termo->setNome((string) $r->get('nome', ''))
                ->setCategoria((string) $r->get('categoria', 'comorbidade'))
                ->setPadroes((string) $r->get('padroes', ''))
                ->setObservacao((string) $r->get('observacao', ''));
            $marcados = array_map('intval', $r->all('itens'));

            if ($termo->getNome() === '') {
                $erros[] = 'Informe o nome do termo.';
            }
            $mesmoNome = $termo->getNome() !== '' ? $this->termoRepo->findOneBy(['nome' => $termo->getNome()]) : null;
            if ($mesmoNome && $mesmoNome->getId() !== $termo->getId()) {
                $erros[] = "Já existe um termo chamado “{$mesmoNome->getNome()}”.";
            }
            foreach ($termo->getListaPadroes() as $p) {
                if (AnamneseThesaurusService::regex($p) === null) {
                    $erros[] = "A regra “{$p}” precisa ter ao menos uma letra ou número.";
                }
            }

            if (!$erros) {
                $r = $this->thesaurus->salvar($termo, $marcados);
                $itens = count(array_filter($this->thesaurus->vinculos(), fn (array $v) => $v['termo'] === $termo->getId()));
                $this->addFlash('success', sprintf('Termo “%s” salvo com %d %s.%s', $termo->getNome(), $itens, $itens === 1 ? 'item' : 'itens', $r['manuais'] ? " {$r['manuais']} escolha(s) manual(is) registrada(s)." : ''));

                return $this->redirectToRoute('app_admin_anamnese_thesaurus');
            }
            // Com erro nada é gravado (não há flush): o formulário volta com o que foi digitado.
        }

        $itens = $this->itensComContagem();
        if ($marcados === null && $termo->getId() === null && $request->query->has('itens')) {
            // "Criar termo" a partir de uma sugestão de itens parecidos
            $marcados = array_values(array_intersect(array_map('intval', $request->query->all('itens')), array_keys($itens)));
            $termo->setNome((string) $request->query->get('nome', ''));
        }
        if ($marcados === null) {
            $marcados = $termo->getId() !== null ? array_keys(array_filter($itens, fn (array $i) => $i['termo'] === $termo->getId())) : [];
        }

        return $this->render('admin/anamnese/thesaurus/form.html.twig', [
            'termo' => $termo,
            'itens' => $itens,
            'marcados' => array_flip($marcados),
            'nomesTermos' => array_map(fn (array $t) => $t['nome'], $this->thesaurus->termos()),
            'categorias' => ClassificacaoEstudo::CATEGORIAS,
            'erros' => $erros,
        ], new Response(status: $erros ? 422 : 200));
    }

    /**
     * Itens do catálogo com o vínculo efetivo e quantos pacientes e marcações têm.
     *
     * @return array<int, array{id: int, nome: string, titulo: string, categoria: string, ativo: bool, termo: ?int, origem: ?string, manual: bool, casam: list<int>, marcacoes: int, pacientes: int}>
     */
    private function itensComContagem(): array
    {
        $contagens = $this->em->getConnection()->fetchAllAssociativeIndexed(
            'SELECT classificacao_id AS id, COUNT(*) AS n, COUNT(DISTINCT paciente_id) AS pac FROM exame_classificacao WHERE removido_em IS NULL GROUP BY classificacao_id'
        );
        $vinculos = $this->thesaurus->vinculos();
        $out = [];
        foreach ($this->thesaurus->itens() as $id => $i) {
            $out[$id] = [
                'id' => $id,
                'nome' => $i['nome'],
                'titulo' => AnamneseEstatisticaService::titulo($i['nome']),
                'categoria' => $i['categoria'],
                'ativo' => $i['ativo'],
                'termo' => $vinculos[$id]['termo'],
                'origem' => $vinculos[$id]['origem'],
                'manual' => $i['termo_manual'],
                'casam' => $vinculos[$id]['casam'],
                'marcacoes' => (int) ($contagens[$id]['n'] ?? 0),
                'pacientes' => (int) ($contagens[$id]['pac'] ?? 0),
            ];
        }
        uasort($out, fn (array $a, array $b) => strnatcasecmp($a['nome'], $b['nome']));

        return $out;
    }

    /** Pacientes distintos com algum item do termo (um paciente com Covid em 3 anos conta uma vez). */
    private function pacientesDoTermo(array $itens): int
    {
        if (!$itens) {
            return 0;
        }

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(DISTINCT paciente_id) FROM exame_classificacao WHERE removido_em IS NULL AND classificacao_id IN (?)',
            [array_column($itens, 'id')],
            [ArrayParameterType::INTEGER]
        );
    }

    private function validarToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('anamnese_thesaurus', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token inválido.');
        }
    }
}
