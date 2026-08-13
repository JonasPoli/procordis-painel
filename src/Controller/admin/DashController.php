<?php

namespace App\Controller\admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class DashController extends AbstractController
{
    #[Route('/', name: 'admin_dash')]
    public function dashboard(): Response
    {
        return $this->redirectToRoute('app_admin_simulador_index');
    }
}
