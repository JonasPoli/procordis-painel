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
