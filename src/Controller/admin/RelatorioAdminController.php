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
        $dataInicioCustom = $request->query->get('dataInicio');
        $dataFimCustom = $request->query->get('dataFim');

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 100;

        $dados = $this->relatorioService->obterRelatorioProcedimentos($tipoPeriodo, $ano, $mes, $dataInicioCustom, $dataFimCustom);
        $dados['dataInicioCustom'] = $dataInicioCustom;
        $dados['dataFimCustom'] = $dataFimCustom;

        $todosAgendamentos = $dados['agendamentos'];
        $totalItems = count($todosAgendamentos);
        $totalPages = max(1, (int) ceil($totalItems / $limit));
        $offset = ($page - 1) * $limit;

        $agendamentosPaginados = array_slice($todosAgendamentos, $offset, $limit);
        $dados['agendamentosPaginados'] = $agendamentosPaginados;
        $dados['page'] = $page;
        $dados['totalPages'] = $totalPages;
        $dados['totalItems'] = $totalItems;
        $dados['limit'] = $limit;

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
        $dataInicioCustom = $request->query->get('dataInicio');
        $dataFimCustom = $request->query->get('dataFim');

        $dados = $this->relatorioService->obterRelatorioProcedimentos($tipoPeriodo, $ano, $mes, $dataInicioCustom, $dataFimCustom);

        $response = new StreamedResponse(function () use ($dados) {
            $handle = fopen('php://output', 'w+');
            // UTF-8 BOM para Excel abrir acentos e caracteres especiais corretamente
            fputs($handle, "\xEF\xBB\xBF");

            // 1. Resumo Geral de Atendimentos
            fputcsv($handle, ['=== RESUMO DE ATENDIMENTOS E FINANCIAMENTO ==='], ';');
            fputcsv($handle, ['Métrica', 'Quantidade', 'Percentual'], ';');
            fputcsv($handle, ['Total de Procedimentos / Exames', $dados['totalProcedimentos'], '100%'], ';');
            fputcsv($handle, ['Atendimentos SUS', $dados['financiamento']['sus'], $dados['financiamento']['pctSus'] . '%'], ';');
            fputcsv($handle, ['Atendimentos Filantrópicos', $dados['financiamento']['filantropico'], $dados['financiamento']['pctFilantropico'] . '%'], ';');
            fputcsv($handle, ['Particular & Convênios', $dados['financiamento']['convenio'], $dados['financiamento']['pctConvenio'] . '%'], ';');
            fputcsv($handle, ['Média Diária de Atendimentos', $dados['mediaDia'], '-'], ';');
            fputcsv($handle, [], ';');

            // 2. Evolução por Dia
            fputcsv($handle, ['=== EVOLUÇÃO TEMPORAL POR DIA ==='], ';');
            fputcsv($handle, ['Data', 'Total de Atendimentos'], ';');
            foreach ($dados['porDia'] as $dia => $qtd) {
                fputcsv($handle, [$dia, $qtd], ';');
            }
            fputcsv($handle, [], ';');

            // 3. Evolução por Mês
            fputcsv($handle, ['=== EVOLUÇÃO TEMPORAL POR MÊS ==='], ';');
            fputcsv($handle, ['Mês/Ano', 'Total de Atendimentos'], ';');
            foreach ($dados['porMes'] as $mesAno => $qtd) {
                fputcsv($handle, [$mesAno, $qtd], ';');
            }
            fputcsv($handle, [], ';');

            // 4. Evolução por Trimestre
            fputcsv($handle, ['=== EVOLUÇÃO TEMPORAL POR TRIMESTRE ==='], ';');
            fputcsv($handle, ['Trimestre/Ano', 'Total de Atendimentos'], ';');
            foreach ($dados['porTrimestre'] as $tri => $qtd) {
                fputcsv($handle, [$tri, $qtd], ';');
            }
            fputcsv($handle, [], ';');

            // 5. Evolução por Quadrimestre
            fputcsv($handle, ['=== EVOLUÇÃO TEMPORAL POR QUADRIMESTRE ==='], ';');
            fputcsv($handle, ['Quadrimestre/Ano', 'Total de Atendimentos'], ';');
            foreach ($dados['porQuadrimestre'] as $quadri => $qtd) {
                fputcsv($handle, [$quadri, $qtd], ';');
            }
            fputcsv($handle, [], ';');

            // 6. Evolução por Semestre
            fputcsv($handle, ['=== EVOLUÇÃO TEMPORAL POR SEMESTRE ==='], ';');
            fputcsv($handle, ['Semestre/Ano', 'Total de Atendimentos'], ';');
            foreach ($dados['porSemestre'] as $semestre => $qtd) {
                fputcsv($handle, [$semestre, $qtd], ';');
            }
            fputcsv($handle, [], ';');

            // 7. Evolução por Ano
            fputcsv($handle, ['=== EVOLUÇÃO TEMPORAL POR ANO ==='], ';');
            fputcsv($handle, ['Ano', 'Total de Atendimentos'], ';');
            foreach ($dados['porAno'] as $anoVal => $qtd) {
                fputcsv($handle, [$anoVal, $qtd], ';');
            }
            fputcsv($handle, [], ';');

            // 8. Ranking por Procedimento / Exame
            fputcsv($handle, ['=== VOLUME TOTAL POR PROCEDIMENTO E EXAME ==='], ';');
            fputcsv($handle, ['Procedimento / Exame', 'Total de Atendimentos no Período'], ';');
            foreach ($dados['porProcedimento'] as $proc => $qtd) {
                fputcsv($handle, [$proc, $qtd], ';');
            }
            fputcsv($handle, [], ';');

            // 9. Atendimentos Individuais Diários por Tipo de Procedimento
            fputcsv($handle, ['=== ATENDIMENTOS DIÁRIOS POR TIPO DE PROCEDIMENTO ==='], ';');
            fputcsv($handle, ['Data', 'Tipo de Procedimento / Exame', 'Quantidade Realizada no Dia'], ';');
            foreach ($dados['porProcedimentoDia'] as $dataStr => $procsNoDia) {
                $dtFmt = \DateTime::createFromFormat('Y-m-d', $dataStr);
                $dtExib = $dtFmt ? $dtFmt->format('d/m/Y') : $dataStr;
                foreach ($procsNoDia as $procNome => $qtdNoDia) {
                    fputcsv($handle, [$dtExib, $procNome, $qtdNoDia], ';');
                }
            }
            fputcsv($handle, [], ';');

            // 7. Registros Analíticos Completos
            fputcsv($handle, ['=== REGISTROS ANALÍTICOS DETALHADOS ==='], ';');
            fputcsv($handle, ['ID Agendamento', 'Data/Hora', 'Paciente', 'Procedimento / Exame', 'Tipo Atendimento', 'Convênio/Plano', 'Médico Responsável', 'Especialidade', 'Status Atendimento'], ';');

            foreach ($dados['agendamentos'] as $ag) {
                $dtAg = $ag['dataHoraAgendada'] ?? $ag['createdAt'];
                if (!$dtAg instanceof \DateTimeInterface) {
                    $dtAg = new \DateTime($dtAg ?? 'now');
                }

                fputcsv($handle, [
                    $ag['id'],
                    $dtAg->format('d/m/Y H:i'),
                    $ag['pacienteNome'] ?? 'Paciente',
                    $ag['procedimentoNome'] ?? 'Procedimento Geral',
                    strtoupper($ag['tipoAtendimento'] ?? 'SUS'),
                    $ag['convenioNome'] ?? 'SUS',
                    $ag['medicoNome'] ?? 'Não informado',
                    $ag['especialidadeNome'] ?? 'Geral',
                    strtoupper($ag['status'] ?? 'AGENDADO')
                ], ';');
            }

            fclose($handle);
        });

        $nomeArquivo = 'relatorio_procedimentos_' . date('Ymd_His') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $nomeArquivo . '"');

        return $response;
    }

    #[Route('/procedimentos/exportar-excel', name: 'procedimentos_excel', methods: ['GET'])]
    public function exportarProcedimentosExcel(Request $request): StreamedResponse
    {
        // Elevar temporariamente o limite de memória e tempo para permitir a montagem do XLSX com 20 anos de histórico
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '300');

        // Buscar TODO o histórico do Procordis em ordem crescente de data
        $dados = $this->relatorioService->obterRelatorioProcedimentos('sem_filtro');
        $agendamentos = $dados['agendamentos']; // já em ordem crescente a.dataHoraAgendada ASC

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // -------------------------------------------------------------
        // Planilha 1: "procedimentos"
        // -------------------------------------------------------------
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('procedimentos');

        // Títulos das colunas
        $headers1 = [
            'ID Agendamento',
            'Data',
            'DataHora',
            'Ano',
            'Mês',
            'Paciente',
            'Procedimento / Exame',
            'Tipo Atendimento',
            'Convênio / Plano',
            'Médico Responsável',
            'Especialidade',
            'Status'
        ];
        $sheet1->fromArray($headers1, null, 'A1');

        $rowsData = [];
        foreach ($agendamentos as $ag) {
            $dtAg = $ag['dataHoraAgendada'] ?? $ag['createdAt'];
            if (!$dtAg instanceof \DateTimeInterface) {
                $dtAg = new \DateTime($dtAg ?? 'now');
            }

            $rowsData[] = [
                $ag['id'],
                $dtAg->format('Y-m-d'),
                $dtAg->format('d/m/Y H:i'),
                (int) $dtAg->format('Y'),
                (int) $dtAg->format('m'),
                $ag['pacienteNome'] ?? 'Paciente',
                $ag['procedimentoNome'] ?? 'Consulta / Procedimento Geral',
                strtoupper($ag['tipoAtendimento'] ?? 'SUS'),
                $ag['convenioNome'] ?? 'SUS',
                $ag['medicoNome'] ?? 'Não informado',
                $ag['especialidadeNome'] ?? 'Geral',
                strtoupper($ag['status'] ?? 'AGENDADO')
            ];
        }

        // Escrever todas as linhas em lote (fromArray é 10x mais rápido e gasta menos memória que setCellValue linha a linha)
        if (!empty($rowsData)) {
            $sheet1->fromArray($rowsData, null, 'A2');
        }
        $lastRowSheet1 = count($rowsData) + 1;

        // Estilizar cabeçalho da planilha 1
        $sheet1->getStyle('A1:L1')->getFont()->setBold(true);
        $sheet1->getStyle('A1:L1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF0891B2');
        $sheet1->getStyle('A1:L1')->getFont()->getColor()->setARGB('FFFFFFFF');

        // -------------------------------------------------------------
        // Planilha 2: "Evolução por tipo - ano"
        // -------------------------------------------------------------
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Evolução por tipo - ano');

        // Anos do período (2005 até o ano atual)
        $anoAtual = (int) date('Y');
        $anos = range(2005, $anoAtual);

        // Montar cabeçalho da Planilha 2
        $headers2 = ['Tipo de Procedimento / Exame'];
        foreach ($anos as $a) {
            $headers2[] = (string) $a;
        }
        $headers2[] = 'Total Geral (Fórmula)';
        $sheet2->fromArray($headers2, null, 'A1');

        // Descobrir procedimentos únicos para aplicar fórmulas dinâmicas por linha
        $procedimentosUnicos = array_keys($dados['porProcedimento']);
        sort($procedimentosUnicos);

        $sheet2RowsData = [];
        $rowIdx2 = 2;
        foreach ($procedimentosUnicos as $procNome) {
            $rowCells = [$procNome];

            $colCharIdx = 2; // B = 2
            foreach ($anos as $a) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCharIdx);
                // Fórmula do Excel apontando para a Planilha 1 ('procedimentos')
                $rowCells[] = "=COUNTIFS(procedimentos!\$G\$2:\$G\${$lastRowSheet1}, \$A{$rowIdx2}, procedimentos!\$D\$2:\$D\${$lastRowSheet1}, {$colLetter}\$1)";
                $colCharIdx++;
            }

            // Coluna Total Geral (Soma das colunas de anos)
            $lastYearColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCharIdx - 1);
            $rowCells[] = "=SUM(B{$rowIdx2}:{$lastYearColLetter}{$rowIdx2})";

            $sheet2RowsData[] = $rowCells;
            $rowIdx2++;
        }

        if (!empty($sheet2RowsData)) {
            $sheet2->fromArray($sheet2RowsData, null, 'A2');
        }

        $lastRowSheet2 = max(2, $rowIdx2 - 1);
        $lastColLetterSheet2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers2));

        // Linha de Total Geral no Rodapé da Planilha 2 com Fórmula SUM (SOMA)
        $footerCells = ['TOTAL GERAL POR ANO'];
        $colCharIdx = 2;
        foreach ($anos as $a) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCharIdx);
            $footerCells[] = "=SUM({$colLetter}2:{$colLetter}{$lastRowSheet2})";
            $colCharIdx++;
        }
        $footerCells[] = "=SUM(" . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCharIdx) . "2:" . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCharIdx) . "{$lastRowSheet2})";

        $sheet2->fromArray([$footerCells], null, "A{$rowIdx2}");

        // Estilização da Planilha 2
        $sheet2->getStyle("A1:{$lastColLetterSheet2}1")->getFont()->setBold(true);
        $sheet2->getStyle("A1:{$lastColLetterSheet2}1")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF059669');
        $sheet2->getStyle("A1:{$lastColLetterSheet2}1")->getFont()->getColor()->setARGB('FFFFFFFF');

        $sheet2->getStyle("A{$rowIdx2}:{$lastColLetterSheet2}{$rowIdx2}")->getFont()->setBold(true);
        $sheet2->getStyle("A{$rowIdx2}:{$lastColLetterSheet2}{$rowIdx2}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE2E8F0');

        // Streaming Response do arquivo XLSX
        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $nomeArquivo = 'relatorio_procedimentos_completo_' . date('Ymd_His') . '.xlsx';
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
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
