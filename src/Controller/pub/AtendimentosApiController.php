<?php

namespace App\Controller\pub;

use App\Service\AtendimentoSerieService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API pública (somente leitura) do painel de atendimentos, consumida pelo site procordis.org.br.
 * Só devolve totais agregados por período e categoria — nenhum dado de paciente.
 */
#[Route('/api/publico/atendimentos', name: 'api_publico_atendimentos_')]
class AtendimentosApiController extends AbstractController
{
    public function __construct(
        private AtendimentoSerieService $series,
        #[Autowire('%env(ATENDIMENTOS_CORS_ORIGENS)%')] private string $origensPermitidas,
    ) {
    }

    /**
     * Série para gráfico de linhas.
     * Parâmetros: agrupamento=dia|mes|ano (padrão mes), de=Y-m-d, ate=Y-m-d.
     */
    #[Route('/serie', name: 'serie', methods: ['GET', 'OPTIONS'])]
    public function serie(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->cors($request, new JsonResponse(null, 204));
        }

        $agrupamento = (string) $request->query->get('agrupamento', 'mes');
        if (!isset(AtendimentoSerieService::AGRUPAMENTOS[$agrupamento])) {
            return $this->cors($request, new JsonResponse(['erro' => 'agrupamento deve ser dia, mes ou ano'], 400));
        }
        $de = $this->data($request->query->get('de'));
        $ate = $this->data($request->query->get('ate'));
        if (($request->query->get('de') && !$de) || ($request->query->get('ate') && !$ate)) {
            return $this->cors($request, new JsonResponse(['erro' => 'datas no formato AAAA-MM-DD'], 400));
        }

        return $this->publico($request, $this->series->serie($agrupamento, $de, $ate));
    }

    /** Totais por categoria em todo o histórico. */
    #[Route('/resumo', name: 'resumo', methods: ['GET', 'OPTIONS'])]
    public function resumo(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->cors($request, new JsonResponse(null, 204));
        }
        $anual = $this->series->serie('ano');

        return $this->publico($request, [
            'categorias' => array_map(fn ($c) => ['slug' => $c['slug'], 'nome' => $c['nome'], 'cor' => $c['cor'], 'total' => (int) $c['total']], $this->series->totaisPorCategoria()),
            'total' => $anual['total']['total'],
            'historico' => $anual['historico'],
            'atualizadoEm' => $anual['atualizadoEm'],
        ]);
    }

    private function publico(Request $request, array $dados): JsonResponse
    {
        $resposta = new JsonResponse($dados);
        $resposta->setPublic();
        $resposta->setMaxAge(900);
        $resposta->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->cors($request, $resposta);
    }

    private function cors(Request $request, JsonResponse $resposta): JsonResponse
    {
        $origem = (string) $request->headers->get('Origin');
        $permitidas = array_filter(array_map('trim', explode(',', $this->origensPermitidas)));
        if ($origem !== '' && (in_array('*', $permitidas, true) || in_array($origem, $permitidas, true))) {
            $resposta->headers->set('Access-Control-Allow-Origin', $origem);
            $resposta->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
            $resposta->headers->set('Vary', 'Origin');
        }

        return $resposta;
    }

    private function data(mixed $valor): ?\DateTimeImmutable
    {
        if (!is_string($valor) || $valor === '') {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $valor) ?: null;
    }
}
