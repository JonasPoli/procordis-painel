<?php

namespace App\Controller\admin;

use App\Repository\ChamadaTelaoRepository;
use App\Repository\SenhaAtendimentoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/senha', name: 'app_admin_senha_')]
class SenhaAdminController extends AbstractController
{
    public function __construct(
        private SenhaAtendimentoRepository $senhaRepo,
        private ChamadaTelaoRepository $chamadaRepo
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $senhas = $this->senhaRepo->findBy([], ['id' => 'DESC'], 50);
        $chamadas = $this->chamadaRepo->findUltimasChamadas(20);

        return $this->render('admin/senha/index.html.twig', [
            'senhas' => $senhas,
            'chamadas' => $chamadas,
        ]);
    }
}
