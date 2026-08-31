<?php

namespace App\Controller\admin;

use App\Service\RelatorioService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/relatorios', name: 'app_admin_relatorio_')]
class RelatorioAdminController extends AbstractController
{
    public function __construct(
        private RelatorioService $relatorioService
    ) {
    }

    #[Route('/procedimentos', name: 'procedimentos', methods: ['GET'])]
    public function relatorioProcedimentos(Request $request): Response
    {
        $tipoPeriodo = $request->query->get('tipoPeriodo', 'ultimos_6_meses');
        $ano = $request->query->get('ano') ? (int) $request->query->get('ano') : (int) date('Y');
        $mes = $request->query->get('mes') ? (int) $request->query->get('mes') : (int) date('m');

        $dados = $this->relatorioService->obterRelatorioProcedimentos($tipoPeriodo, $ano, $mes);

        return $this->render('admin/relatorio/procedimentos.html.twig', [
            'dados' => $dados,
        ]);
    }

    #[Route('/procedimentos/exportar-csv', name: 'procedimentos_csv', methods: ['GET'])]
    public function exportarProcedimentosCsv(Request $request): StreamedResponse
    {
        $tipoPeriodo = $request->query->get('tipoPeriodo', 'ultimos_6_meses');
        $ano = $request->query->get('ano') ? (int) $request->query->get('ano') : (int) date('Y');
        $mes = $request->query->get('mes') ? (int) $request->query->get('mes') : (int) date('m');

        $dados = $this->relatorioService->obterRelatorioProcedimentos($tipoPeriodo, $ano, $mes);

        $response = new StreamedResponse(function () use ($dados) {
            $handle = fopen('php://output', 'w+');
            // UTF-8 BOM para Excel abrir acentos corretamente
            fputs($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['ID', 'Data/Hora', 'Paciente', 'Procedimento / Exame', 'Tipo Atendimento', 'Convênio', 'Médico', 'Especialidade', 'Status'], ';');

            foreach ($dados['agendamentos'] as $ag) {
                fputcsv($handle, [
                    $ag->getId(),
                    $ag->getDataHoraAgendada() ? $ag->getDataHoraAgendada()->format('d/m/Y H:i') : '',
                    $ag->getPaciente() ? $ag->getPaciente()->getNomeCompleto() : 'Paciente',
                    $ag->getProcedimentoNome() ?? 'Procedimento Geral',
                    strtoupper($ag->getTipoAtendimento() ?? 'SUS'),
                    $ag->getConvenioNome() ?? 'SUS',
                    $ag->getMedico() ? $ag->getMedico()->getNome() : 'Não informado',
                    $ag->getEspecialidade() ? $ag->getEspecialidade()->getNome() : 'Geral',
                    strtoupper($ag->getStatus())
                ], ';');
            }

            fclose($handle);
        });

        $nomeArquivo = 'relatorio_procedimentos_' . date('Ymd_His') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $nomeArquivo . '"');

        return $response;
    }

    #[Route('/anamneses', name: 'anamneses', methods: ['GET'])]
    public function relatorioAnamneses(Request $request): Response
    {
        $tipoPeriodo = $request->query->get('tipoPeriodo', 'ultimos_6_meses');
        $ano = $request->query->get('ano') ? (int) $request->query->get('ano') : (int) date('Y');
        $mes = $request->query->get('mes') ? (int) $request->query->get('mes') : (int) date('m');

        $dados = $this->relatorioService->obterRelatorioAnamneses($tipoPeriodo, $ano, $mes);

        return $this->render('admin/relatorio/anamneses.html.twig', [
            'dados' => $dados,
        ]);
    }

    #[Route('/anamneses/exportar-csv', name: 'anamneses_csv', methods: ['GET'])]
    public function exportarAnamnesesCsv(Request $request): StreamedResponse
    {
        $tipoPeriodo = $request->query->get('tipoPeriodo', 'ultimos_6_meses');
        $ano = $request->query->get('ano') ? (int) $request->query->get('ano') : (int) date('Y');
        $mes = $request->query->get('mes') ? (int) $request->query->get('mes') : (int) date('m');

        $dados = $this->relatorioService->obterRelatorioAnamneses($tipoPeriodo, $ano, $mes);

        $response = new StreamedResponse(function () use ($dados) {
            $handle = fopen('php://output', 'w+');
            fputs($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['ID Agendamento', 'Data/Hora Triagem', 'Paciente', 'Pressão Arterial', 'Freq. Cardíaca (BPM)', 'Peso (kg)', 'Classificação Risco', 'Queixa Principal', 'Responsável Triagem'], ';');

            foreach ($dados['triagens'] as $t) {
                $ag = $t->getAgendamento();
                fputcsv($handle, [
                    $ag ? $ag->getId() : '',
                    $t->getDataHoraInicio() ? $t->getDataHoraInicio()->format('d/m/Y H:i') : '',
                    ($ag && $ag->getPaciente()) ? $ag->getPaciente()->getNomeCompleto() : 'Paciente',
                    $t->getPressaoArterial() ?? 'N/I',
                    $t->getFrequenciaCardiaca() ?? 'N/I',
                    $t->getPeso() ?? 'N/I',
                    strtoupper($t->getClassificacaoRisco() ?? 'VERDE'),
                    $t->getQueixaPrincipal() ?? 'N/A',
                    $t->getResponsavel() ?? 'Enfermagem'
                ], ';');
            }

            fclose($handle);
        });

        $nomeArquivo = 'relatorio_anamneses_' . date('Ymd_His') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $nomeArquivo . '"');

        return $response;
    }
}
