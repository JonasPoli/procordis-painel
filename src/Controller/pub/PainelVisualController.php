<?php

namespace App\Controller\pub;

use App\Repository\AgendamentoRepository;
use App\Repository\EspecialidadeRepository;
use App\Repository\MedicoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/painel', name: 'painel_visual_')]
class PainelVisualController extends AbstractController
{
    public function __construct(
        private AgendamentoRepository $agendamentoRepo,
        private MedicoRepository $medicoRepo,
        private EspecialidadeRepository $especialidadeRepo
    ) {
    }

    #[Route('/espera', name: 'espera')]
    public function espera(): Response
    {
        $allMedicos = $this->medicoRepo->findAll();
        $medicosUnicos = [];
        foreach ($allMedicos as $m) {
            $chave = mb_strtolower(trim($m->getNome()));
            if (!isset($medicosUnicos[$chave])) {
                $medicosUnicos[$chave] = $m;
            }
        }

        return $this->render('pub/painel/espera.html.twig', [
            'medicos' => array_values($medicosUnicos),
            'especialidades' => $this->especialidadeRepo->findAll(),
        ]);
    }

    #[Route('/chamada', name: 'chamada')]
    public function chamada(): Response
    {
        return $this->render('pub/painel/chamada.html.twig');
    }

    #[Route('/medicos', name: 'medicos')]
    public function medicos(): Response
    {
        return $this->render('pub/painel/medicos.html.twig');
    }

    #[Route('/triagem', name: 'triagem')]
    public function triagem(): Response
    {
        return $this->render('pub/painel/triagem.html.twig');
    }

    #[Route('/dashboard', name: 'dashboard')]
    public function dashboard(): Response
    {
        return $this->render('pub/painel/dashboard.html.twig');
    }

    #[Route('/paciente/{id}', name: 'paciente_historico')]
    public function pacienteHistorico(int $id): Response
    {
        $agendamento = $this->agendamentoRepo->find($id);
        if (!$agendamento) {
            throw $this->createNotFoundException('Agendamento não encontrado.');
        }

        return $this->render('pub/painel/paciente_historico.html.twig', [
            'agendamento' => $agendamento,
        ]);
    }

    #[Route('/aguardando', name: 'aguardando')]
    public function aguardando(): Response
    {
        return $this->render('pub/painel/aguardando.html.twig');
    }

    #[Route('/finalizados', name: 'finalizados')]
    public function finalizados(): Response
    {
        return $this->render('pub/painel/finalizados.html.twig');
    }

    #[Route('/status', name: 'status')]
    public function status(): Response
    {
        return $this->redirectToRoute('app_admin_testes_index');
    }
}


