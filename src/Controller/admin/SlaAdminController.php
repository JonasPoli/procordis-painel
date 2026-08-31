<?php

namespace App\Controller\admin;

use App\Entity\ProcedimentoSla;
use App\Form\ProcedimentoSlaType;
use App\Repository\ProcedimentoSlaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Repository\AgendamentoRepository;

#[Route('/admin/sla', name: 'app_admin_sla_')]
class SlaAdminController extends AbstractController
{
    public function __construct(
        private ProcedimentoSlaRepository $slaRepository,
        private AgendamentoRepository $agendamentoRepo,
        private EntityManagerInterface $em
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $procedimentosMedware = $this->agendamentoRepo->listarProcedimentosUnicosBanco();

        return $this->render('admin/procedimento_sla/index.html.twig', [
            'regrasSla' => $this->slaRepository->findAll(),
            'procedimentosMedware' => $procedimentosMedware,
        ]);
    }

    #[Route('/novo', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $sla = new ProcedimentoSla();
        $form = $this->createForm(ProcedimentoSlaType::class, $sla);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($sla);
            $this->em->flush();

            $this->addFlash('success', 'Regra de SLA criada com sucesso.');
            return $this->redirectToRoute('app_admin_sla_index');
        }

        return $this->render('admin/procedimento_sla/new.html.twig', [
            'sla' => $sla,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ProcedimentoSla $sla): Response
    {
        $form = $this->createForm(ProcedimentoSlaType::class, $sla);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            $this->addFlash('success', 'Regra de SLA atualizada com sucesso.');
            return $this->redirectToRoute('app_admin_sla_index');
        }

        return $this->render('admin/procedimento_sla/edit.html.twig', [
            'sla' => $sla,
            'form' => $form,
        ]);
    }

    #[Route('/sincronizar-com-medware', name: 'sync_medware', methods: ['POST'])]
    public function sincronizarComMedware(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('sync_medware_sla', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_sla_index');
        }

        $procedimentosMedware = $this->agendamentoRepo->listarProcedimentosUnicosBanco();
        $regrasAtuais = $this->slaRepository->findAll();

        $nomesReaisMap = [];
        foreach ($procedimentosMedware as $p) {
            $nome = trim($p['procedimentoNome']);
            if ($nome !== '') {
                $nomesReaisMap[mb_strtolower($nome)] = $nome;
            }
        }

        $criados = 0;
        $removidos = 0;

        // 1. Criar regras SLA para procedimentos reais do Medware que ainda não existem
        foreach ($nomesReaisMap as $nomeMin => $nomeOriginal) {
            $existe = false;
            foreach ($regrasAtuais as $r) {
                if (mb_strtolower(trim($r->getNomeProcedimento())) === $nomeMin) {
                    $existe = true;
                    break;
                }
            }

            if (!$existe) {
                $novaSla = new ProcedimentoSla();
                $prefixo = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $nomeOriginal), 0, 4));
                $novaSla->setCodigo($prefixo . '-' . rand(100, 999));
                $novaSla->setNomeProcedimento($nomeOriginal);
                $novaSla->setLimiteVerdeMinutos(15);
                $novaSla->setLimiteAmareloMinutos(30);
                $novaSla->setDescricao('Importado automaticamente dos atendimentos reais da API Medware');
                $this->em->persist($novaSla);
                $criados++;
            }
        }

        // 2. Remover regras de SLA obsoletas que não correspondem aos procedimentos reais do Medware
        if (!empty($nomesReaisMap)) {
            foreach ($regrasAtuais as $r) {
                $nomeRegraMin = mb_strtolower(trim($r->getNomeProcedimento()));
                if (!isset($nomesReaisMap[$nomeRegraMin])) {
                    $this->em->remove($r);
                    $removidos++;
                }
            }
        }

        $this->em->flush();

        $this->addFlash('success', "Sincronização de SLA concluída! ({$criados} novos procedimentos importados, {$removidos} regras obsoletas removidas).");
        return $this->redirectToRoute('app_admin_sla_index');
    }

    #[Route('/{id}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, ProcedimentoSla $sla): Response
    {
        if ($this->isCsrfTokenValid('delete' . $sla->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($sla);
            $this->em->flush();
            $this->addFlash('success', 'Regra de SLA removida com sucesso.');
        }

        return $this->redirectToRoute('app_admin_sla_index');
    }
}
