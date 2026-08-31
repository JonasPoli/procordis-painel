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
        // Padrão 'sem_filtro' para extrair TODO o histórico de atendimentos realizados desde a fundação
        $tipoPeriodo = $request->query->get('tipoPeriodo', 'sem_filtro');
        $ano = $request->query->get('ano') ? (int) $request->query->get('ano') : (int) date('Y');
        $mes = $request->query->get('mes') ? (int) $request->query->get('mes') : (int) date('m');
        $dataInicioCustom = $request->query->get('dataInicio');
        $dataFimCustom = $request->query->get('dataFim');

        $iterator = $this->relatorioService->obterIterableAgendamentosCompletos($tipoPeriodo, $ano, $mes, $dataInicioCustom, $dataFimCustom);

        $diasSemanaMap = [
            1 => 'Segunda-feira',
            2 => 'Terça-feira',
            3 => 'Quarta-feira',
            4 => 'Quinta-feira',
            5 => 'Sexta-feira',
            6 => 'Sábado',
            7 => 'Domingo'
        ];

        $response = new StreamedResponse(function () use ($iterator, $diasSemanaMap) {
            $handle = fopen('php://output', 'w+');
            // UTF-8 BOM para Excel
            fputs($handle, "\xEF\xBB\xBF");

            // Cabeçalho alinhado com a planilha 'procedimentos' do XLSX
            fputcsv($handle, [
                'ID Agendamento',
                'Data',
                'DataHora',
                'Ano',
                'Mês',
                'Dia da Semana',
                'Hora',
                'Paciente',
                'Sexo',
                'Faixa Etária',
                'Procedimento / Exame',
                'Tipo Atendimento',
                'Convênio / Plano',
                'Médico Responsável',
                'Especialidade',
                'Status',
                'Encaixe',
                'Tempo Espera (min)'
            ], ';');

            foreach ($iterator as $item) {
                $dt = $item['dataHoraAgendada'] ?? $item['createdAt'];
                if (!$dt instanceof \DateTimeInterface) {
                    $dt = new \DateTime($dt ?? 'now');
                }

                $nasc = $item['pacienteNascimento'] ?? null;
                $faixaEtaria = 'Não informada';
                if ($nasc instanceof \DateTimeInterface) {
                    $idade = $dt->diff($nasc)->y;
                    if ($idade <= 18) {
                        $faixaEtaria = '0 a 18 anos';
                    } elseif ($idade <= 39) {
                        $faixaEtaria = '19 a 39 anos';
                    } elseif ($idade <= 59) {
                        $faixaEtaria = '40 a 59 anos';
                    } elseif ($idade <= 79) {
                        $faixaEtaria = '60 a 79 anos';
                    } else {
                        $faixaEtaria = '80+ anos';
                    }
                }

                $diaSemanaNum = (int) $dt->format('N');
                $diaSemanaStr = $diasSemanaMap[$diaSemanaNum] ?? 'Outro';
                $horaStr = $dt->format('H') . 'h';

                $tempoEsperaMin = 0;
                $chegada = $item['horarioChegada'] ?? null;
                $fim = $item['horarioInicioConsulta'] ?? $item['horarioSaida'] ?? $item['horarioFimConsulta'] ?? null;
                if ($chegada instanceof \DateTimeInterface && $fim instanceof \DateTimeInterface) {
                    $diffSeg = $fim->getTimestamp() - $chegada->getTimestamp();
                    if ($diffSeg > 0) {
                        $tempoEsperaMin = round($diffSeg / 60);
                    }
                }

                fputcsv($handle, [
                    $item['id'],
                    $dt->format('Y-m-d'),
                    $dt->format('d/m/Y H:i'),
                    (int) $dt->format('Y'),
                    (int) $dt->format('m'),
                    $diaSemanaStr,
                    $horaStr,
                    $item['pacienteNome'] ?? 'Paciente',
                    strtoupper($item['pacienteSexo'] ?? 'NÃO INFORMADO'),
                    $faixaEtaria,
                    $item['procedimentoNome'] ?? 'Consulta / Procedimento Geral',
                    strtoupper($item['tipoAtendimento'] ?? 'SUS'),
                    $item['convenioNome'] ?? 'SUS',
                    $item['medicoNome'] ?? 'Não informado',
                    $item['especialidadeNome'] ?? 'Geral',
                    strtoupper($item['status'] ?? 'AGENDADO'),
                    !empty($item['encaixe']) ? 'SIM' : 'NÃO',
                    $tempoEsperaMin
                ], ';');
            }

            fclose($handle);
        });

        $nomeArquivo = 'dados_atendimentos_procordis_' . date('Ymd_His') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $nomeArquivo . '"');

        return $response;
    }

    #[Route('/procedimentos/exportar-excel', name: 'procedimentos_excel', methods: ['GET'])]
    public function exportarProcedimentosExcel(Request $request): StreamedResponse
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        $anoAtual = (int) date('Y');
        $anos = range(2005, $anoAtual);

        $procedimentosUnicos = $this->relatorioService->obterProcedimentosUnicos();
        if (empty($procedimentosUnicos)) {
            $procedimentosUnicos = ['Consulta Cardiológica', 'Ecocardiograma Transtorácico', 'Eletrocardiograma (ECG)', 'Holter 24 Horas', 'MAPA 24 Horas', 'Teste Ergométrico'];
        }

        $medicosList = $this->relatorioService->obterMedicosComEspecialidade();
        $conveniosList = $this->relatorioService->obterConveniosUnicos();
        if (empty($conveniosList)) {
            $conveniosList = ['SUS - Sistema Único de Saúde', 'UNIMED', 'CASSI', 'BRADESCO SAÚDE', 'SULAMÉRICA', 'PARTICULAR'];
        }

        // -------------------------------------------------------------
        // Planilha 1: "procedimentos" (Aba de Dados - Deixada Limpa para Carga Instantânea)
        // -------------------------------------------------------------
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('procedimentos');

        $headers1 = [
            'ID Agendamento',
            'Data',
            'DataHora',
            'Ano',
            'Mês',
            'Dia da Semana',
            'Hora',
            'Paciente',
            'Sexo',
            'Faixa Etária',
            'Procedimento / Exame',
            'Tipo Atendimento',
            'Convênio / Plano',
            'Médico Responsável',
            'Especialidade',
            'Status',
            'Encaixe',
            'Tempo Espera (min)'
        ];
        $sheet1->fromArray($headers1, null, 'A1');

        $lastColLetter1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers1));
        $sheet1->getStyle("A1:{$lastColLetter1}1")->getFont()->setBold(true);
        $sheet1->getStyle("A1:{$lastColLetter1}1")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF0891B2'); // Cyan Procordis
        $sheet1->getStyle("A1:{$lastColLetter1}1")->getFont()->getColor()->setARGB('FFFFFFFF');

        // -------------------------------------------------------------
        // Planilha 2: "Evolução por tipo - ano"
        // -------------------------------------------------------------
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Evolução por tipo - ano');

        $headers2 = ['Tipo de Procedimento / Exame'];
        foreach ($anos as $a) {
            $headers2[] = (string) $a;
        }
        $headers2[] = 'Total Geral';
        $sheet2->fromArray($headers2, null, 'A1');

        $sheet2Rows = [];
        $r2 = 2;
        foreach ($procedimentosUnicos as $procNome) {
            $rowCells = [$procNome];
            $colIdx = 2;
            foreach ($anos as $a) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                $rowCells[] = "=COUNTIFS(procedimentos!\$K:\$K, \$A{$r2}, procedimentos!\$D:\$D, {$colLetter}\$1)";
                $colIdx++;
            }
            $lastYearCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx - 1);
            $rowCells[] = "=SUM(B{$r2}:{$lastYearCol}{$r2})";
            $sheet2Rows[] = $rowCells;
            $r2++;
        }
        $sheet2->fromArray($sheet2Rows, null, 'A2');

        $lastRowS2 = max(2, $r2 - 1);
        $lastColS2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers2));

        $footerS2 = ['TOTAL GERAL POR ANO'];
        $colIdx = 2;
        foreach ($anos as $a) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $footerS2[] = "=SUM({$colLetter}2:{$colLetter}{$lastRowS2})";
            $colIdx++;
        }
        $totalColS2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
        $footerS2[] = "=SUM({$totalColS2}2:{$totalColS2}{$lastRowS2})";
        $sheet2->fromArray([$footerS2], null, "A{$r2}");

        $sheet2->getStyle("A1:{$lastColS2}1")->getFont()->setBold(true);
        $sheet2->getStyle("A1:{$lastColS2}1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF059669');
        $sheet2->getStyle("A1:{$lastColS2}1")->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet2->getStyle("A{$r2}:{$lastColS2}{$r2}")->getFont()->setBold(true);
        $sheet2->getStyle("A{$r2}:{$lastColS2}{$r2}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');

        // -------------------------------------------------------------
        // Planilha 3: "Produtividade Médica"
        // -------------------------------------------------------------
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Produtividade Médica');

        $headers3 = ['Médico Responsável', 'Especialidade'];
        foreach ($anos as $a) {
            $headers3[] = (string) $a;
        }
        $headers3[] = 'Total Atendimentos';
        $headers3[] = 'Concluídos';
        $headers3[] = 'Cancelados';
        $sheet3->fromArray($headers3, null, 'A1');

        $sheet3Rows = [];
        $r3 = 2;
        foreach ($medicosList as $med) {
            $nomeM = $med['medicoNome'] ?? 'Não atribuído';
            $espM = $med['especialidadeNome'] ?? 'Geral';
            $rowCells = [$nomeM, $espM];
            $colIdx = 3;
            foreach ($anos as $a) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                $rowCells[] = "=COUNTIFS(procedimentos!\$N:\$N, \$A{$r3}, procedimentos!\$D:\$D, {$colLetter}\$1)";
                $colIdx++;
            }
            $lastYearCol3 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx - 1);
            $rowCells[] = "=SUM(C{$r3}:{$lastYearCol3}{$r3})";
            $rowCells[] = "=COUNTIFS(procedimentos!\$N:\$N, \$A{$r3}, procedimentos!\$P:\$P, \"FINALIZADO\")";
            $rowCells[] = "=COUNTIFS(procedimentos!\$N:\$N, \$A{$r3}, procedimentos!\$P:\$P, \"CANCELADO\")";

            $sheet3Rows[] = $rowCells;
            $r3++;
        }
        if (!empty($sheet3Rows)) {
            $sheet3->fromArray($sheet3Rows, null, 'A2');
        }

        $lastRowS3 = max(2, $r3 - 1);
        $lastColS3 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers3));
        $sheet3->getStyle("A1:{$lastColS3}1")->getFont()->setBold(true);
        $sheet3->getStyle("A1:{$lastColS3}1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF4F46E5');
        $sheet3->getStyle("A1:{$lastColS3}1")->getFont()->getColor()->setARGB('FFFFFFFF');

        // -------------------------------------------------------------
        // Planilha 4: "Composição Convênios"
        // -------------------------------------------------------------
        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle('Composição Convênios');

        $headers4 = ['Tipo de Financiamento'];
        foreach ($anos as $a) {
            $headers4[] = (string) $a;
        }
        $headers4[] = 'Total Geral';
        $sheet4->fromArray($headers4, null, 'A1');

        $tiposFin = ['SUS', 'CONVÊNIO', 'FILANTRÓPICO', 'PARTICULAR'];
        $sheet4Rows = [];
        $r4 = 2;
        foreach ($tiposFin as $tf) {
            $rowCells = [$tf];
            $colIdx = 2;
            foreach ($anos as $a) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                $rowCells[] = "=COUNTIFS(procedimentos!\$L:\$L, \$A{$r4}, procedimentos!\$D:\$D, {$colLetter}\$1)";
                $colIdx++;
            }
            $lastYearCol4 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx - 1);
            $rowCells[] = "=SUM(B{$r4}:{$lastYearCol4}{$r4})";
            $sheet4Rows[] = $rowCells;
            $r4++;
        }
        $sheet4->fromArray($sheet4Rows, null, 'A2');

        $footerS4 = ['TOTAL GERAL'];
        $colIdx = 2;
        foreach ($anos as $a) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $footerS4[] = "=SUM({$colLetter}2:{$colLetter}5)";
            $colIdx++;
        }
        $totalColS4 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
        $footerS4[] = "=SUM({$totalColS4}2:{$totalColS4}5)";
        $sheet4->fromArray([$footerS4], null, 'A6');

        $rowPctSus = ['% Participação SUS'];
        $colIdx = 2;
        foreach ($anos as $a) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $rowPctSus[] = "=IF({$colLetter}6>0, {$colLetter}2/{$colLetter}6, 0)";
            $colIdx++;
        }
        $rowPctSus[] = "=IF({$totalColS4}6>0, {$totalColS4}2/{$totalColS4}6, 0)";
        $sheet4->fromArray([$rowPctSus], null, 'A7');

        // Seção 2: Top Operadoras de Convênio
        $sheet4->setCellValue('A9', 'TOP OPERADORAS / CONVÊNIOS DE SAÚDE');
        $sheet4->getStyle('A9')->getFont()->setBold(true);

        $headersConv = ['Nome da Operadora / Convênio'];
        foreach ($anos as $a) {
            $headersConv[] = (string) $a;
        }
        $headersConv[] = 'Total';
        $sheet4->fromArray($headersConv, null, 'A10');

        $sheet4ConvRows = [];
        $r4c = 11;
        foreach ($conveniosList as $cNome) {
            $rowCells = [$cNome];
            $colIdx = 2;
            foreach ($anos as $a) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                $rowCells[] = "=COUNTIFS(procedimentos!\$M:\$M, \$A{$r4c}, procedimentos!\$D:\$D, {$colLetter}\$10)";
                $colIdx++;
            }
            $lastYearCol4c = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx - 1);
            $rowCells[] = "=SUM(B{$r4c}:{$lastYearCol4c}{$r4c})";
            $sheet4ConvRows[] = $rowCells;
            $r4c++;
        }
        if (!empty($sheet4ConvRows)) {
            $sheet4->fromArray($sheet4ConvRows, null, 'A11');
        }

        $lastColS4 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers4));
        $sheet4->getStyle("A1:{$lastColS4}1")->getFont()->setBold(true);
        $sheet4->getStyle("A1:{$lastColS4}1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF0284C7');
        $sheet4->getStyle("A1:{$lastColS4}1")->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet4->getStyle("A6:{$lastColS4}7")->getFont()->setBold(true);
        $sheet4->getStyle("A10:{$lastColS4}10")->getFont()->setBold(true);

        // -------------------------------------------------------------
        // Planilha 5: "Status e Eficiência"
        // -------------------------------------------------------------
        $sheet5 = $spreadsheet->createSheet();
        $sheet5->setTitle('Status e Eficiência');

        $headers5 = ['Status do Atendimento'];
        foreach ($anos as $a) {
            $headers5[] = (string) $a;
        }
        $headers5[] = 'Total';
        $sheet5->fromArray($headers5, null, 'A1');

        $statusList = ['FINALIZADO', 'CANCELADO', 'AGENDADO', 'EM_CONSULTA', 'AGUARDANDO_MEDICO', 'AGUARDANDO_TRIAGEM'];
        $sheet5Rows = [];
        $r5 = 2;
        foreach ($statusList as $st) {
            $rowCells = [$st];
            $colIdx = 2;
            foreach ($anos as $a) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                $rowCells[] = "=COUNTIFS(procedimentos!\$P:\$P, \$A{$r5}, procedimentos!\$D:\$D, {$colLetter}\$1)";
                $colIdx++;
            }
            $lastYearCol5 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx - 1);
            $rowCells[] = "=SUM(B{$r5}:{$lastYearCol5}{$r5})";
            $sheet5Rows[] = $rowCells;
            $r5++;
        }
        $sheet5->fromArray($sheet5Rows, null, 'A2');

        $footerS5 = ['TOTAL GERAL'];
        $colIdx = 2;
        foreach ($anos as $a) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $footerS5[] = "=SUM({$colLetter}2:{$colLetter}7)";
            $colIdx++;
        }
        $totalColS5 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
        $footerS5[] = "=SUM({$totalColS5}2:{$totalColS5}7)";
        $sheet5->fromArray([$footerS5], null, 'A8');

        // Indicadores de Eficiência
        $sheet5->setCellValue('A10', 'INDICADORES DE EFICIÊNCIA DE AGENDA');
        $sheet5->getStyle('A10')->getFont()->setBold(true);

        $rowEncaixes = ['Total de Encaixes'];
        $colIdx = 2;
        foreach ($anos as $a) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $rowEncaixes[] = "=COUNTIFS(procedimentos!\$Q:\$Q, \"SIM\", procedimentos!\$D:\$D, {$colLetter}\$1)";
            $colIdx++;
        }
        $rowEncaixes[] = "=COUNTIF(procedimentos!\$Q:\$Q, \"SIM\")";
        $sheet5->fromArray([$rowEncaixes], null, 'A11');

        $rowTaxaEncaixe = ['Taxa de Encaixes (%)'];
        $colIdx = 2;
        foreach ($anos as $a) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $rowTaxaEncaixe[] = "=IF({$colLetter}8>0, {$colLetter}11/{$colLetter}8, 0)";
            $colIdx++;
        }
        $rowTaxaEncaixe[] = "=IF({$totalColS5}8>0, {$totalColS5}11/{$totalColS5}8, 0)";
        $sheet5->fromArray([$rowTaxaEncaixe], null, 'A12');

        $rowTaxaCancel = ['Taxa de Cancelamento (%)'];
        $colIdx = 2;
        foreach ($anos as $a) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $rowTaxaCancel[] = "=IF({$colLetter}8>0, {$colLetter}3/{$colLetter}8, 0)";
            $colIdx++;
        }
        $rowTaxaCancel[] = "=IF({$totalColS5}8>0, {$totalColS5}3/{$totalColS5}8, 0)";
        $sheet5->fromArray([$rowTaxaCancel], null, 'A13');

        $lastColS5 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers5));
        $sheet5->getStyle("A1:{$lastColS5}1")->getFont()->setBold(true);
        $sheet5->getStyle("A1:{$lastColS5}1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD97706');
        $sheet5->getStyle("A1:{$lastColS5}1")->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet5->getStyle("A8:{$lastColS5}8")->getFont()->setBold(true);

        // -------------------------------------------------------------
        // Planilha 6: "Horários e Dias de Pico"
        // -------------------------------------------------------------
        $sheet6 = $spreadsheet->createSheet();
        $sheet6->setTitle('Horários e Dias de Pico');

        $sheet6->fromArray(['Dia da Semana', 'Volume de Atendimentos', '% do Total'], null, 'A1');
        $diasSemana = ['Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado', 'Domingo'];
        $sheet6Dias = [];
        $r6d = 2;
        foreach ($diasSemana as $dia) {
            $sheet6Dias[] = [
                $dia,
                "=COUNTIF(procedimentos!\$F:\$F, \$A{$r6d})",
                "=IF(\$B\$9>0, B{$r6d}/\$B\$9, 0)"
            ];
            $r6d++;
        }
        $sheet6->fromArray($sheet6Dias, null, 'A2');
        $sheet6->fromArray([['TOTAL', '=SUM(B2:B8)', '=SUM(C2:C8)']], null, 'A9');

        $sheet6->setCellValue('A11', 'DISTRIBUIÇÃO POR FAIXA DE HORÁRIO');
        $sheet6->getStyle('A11')->getFont()->setBold(true);
        $sheet6->fromArray(['Faixa de Horário', 'Volume de Atendimentos', '% do Total'], null, 'A12');
        $horas = ['06h', '07h', '08h', '09h', '10h', '11h', '12h', '13h', '14h', '15h', '16h', '17h', '18h', '19h', '20h'];
        $sheet6Horas = [];
        $r6h = 13;
        foreach ($horas as $h) {
            $sheet6Horas[] = [
                $h,
                "=COUNTIF(procedimentos!\$G:\$G, \$A{$r6h})",
                "=IF(\$B\$28>0, B{$r6h}/\$B\$28, 0)"
            ];
            $r6h++;
        }
        $sheet6->fromArray($sheet6Horas, null, 'A13');
        $sheet6->fromArray([['TOTAL', '=SUM(B13:B27)', '=SUM(C13:C27)']], null, 'A28');

        $sheet6->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet6->getStyle('A1:C1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF7C3AED');
        $sheet6->getStyle('A1:C1')->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet6->getStyle('A9:C9')->getFont()->setBold(true);
        $sheet6->getStyle('A12:C12')->getFont()->setBold(true);
        $sheet6->getStyle('A12:C12')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF7C3AED');
        $sheet6->getStyle('A12:C12')->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet6->getStyle('A28:C28')->getFont()->setBold(true);

        // -------------------------------------------------------------
        // Planilha 7: "Demografia e Perfil"
        // -------------------------------------------------------------
        $sheet7 = $spreadsheet->createSheet();
        $sheet7->setTitle('Demografia e Perfil');

        $sheet7->fromArray(['Gênero do Paciente', 'Total Atendimentos', '% do Total'], null, 'A1');
        $generos = ['M', 'F', 'NÃO INFORMADO'];
        $sheet7Gen = [];
        $r7g = 2;
        foreach ($generos as $g) {
            $rotuloG = $g === 'M' ? 'MASCULINO (M)' : ($g === 'F' ? 'FEMININO (F)' : 'NÃO INFORMADO');
            $sheet7Gen[] = [
                $rotuloG,
                "=COUNTIF(procedimentos!\$I:\$I, \"{$g}\")",
                "=IF(\$B\$5>0, B{$r7g}/\$B\$5, 0)"
            ];
            $r7g++;
        }
        $sheet7->fromArray($sheet7Gen, null, 'A2');
        $sheet7->fromArray([['TOTAL', '=SUM(B2:B4)', '=SUM(C2:C4)']], null, 'A5');

        $sheet7->setCellValue('A7', 'DISTRIBUIÇÃO POR FAIXA ETÁRIA');
        $sheet7->getStyle('A7')->getFont()->setBold(true);
        $sheet7->fromArray(['Faixa Etária', 'Total Atendimentos', '% do Total'], null, 'A8');
        $faixas = ['0 a 18 anos', '19 a 39 anos', '40 a 59 anos', '60 a 79 anos', '80+ anos', 'Não informada'];
        $sheet7Faixas = [];
        $r7f = 9;
        foreach ($faixas as $fx) {
            $sheet7Faixas[] = [
                $fx,
                "=COUNTIF(procedimentos!\$J:\$J, \$A{$r7f})",
                "=IF(\$B\$15>0, B{$r7f}/\$B\$15, 0)"
            ];
            $r7f++;
        }
        $sheet7->fromArray($sheet7Faixas, null, 'A9');
        $sheet7->fromArray([['TOTAL', '=SUM(B9:B14)', '=SUM(C9:C14)']], null, 'A15');

        $sheet7->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet7->getStyle('A1:C1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEC4899');
        $sheet7->getStyle('A1:C1')->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet7->getStyle('A5:C5')->getFont()->setBold(true);
        $sheet7->getStyle('A8:C8')->getFont()->setBold(true);
        $sheet7->getStyle('A8:C8')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEC4899');
        $sheet7->getStyle('A8:C8')->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet7->getStyle('A15:C15')->getFont()->setBold(true);

        // -------------------------------------------------------------
        // Planilha 8: "Resumo Geral KPIs"
        // -------------------------------------------------------------
        $sheet8 = $spreadsheet->createSheet();
        $sheet8->setTitle('Resumo Geral KPIs');

        $sheet8->fromArray(['Indicador Estratégico Procordis', 'Valor Calculado (Fórmula)', 'Descrição / Meta'], null, 'A1');
        $kpis = [
            ['Total Histórico de Procedimentos & Atendimentos', '=COUNTA(procedimentos!$A:$A)-1', 'Volume total de atendimentos na base de dados'],
            ['Atendimentos Concluídos / Realizados', '=COUNTIF(procedimentos!$P:$P, "FINALIZADO")', 'Consultas e exames com saída confirmada'],
            ['Volume Total SUS', '=COUNTIF(procedimentos!$L:$L, "SUS")', 'Atendimentos realizados pelo SUS'],
            ['Participação do SUS no Mix Total', '=IF(B2>0, B4/B2, 0)', 'Percentual de atendimento público'],
            ['Volume Total de Convênios Privados', '=COUNTIF(procedimentos!$L:$L, "CONVÊNIO")', 'Saúde suplementar e planos parceiros'],
            ['Volume Total Filantrópico', '=COUNTIF(procedimentos!$L:$L, "FILANTRÓPICO")', 'Atendimentos de filantropia institucional'],
            ['Total de Cancelamentos e Absenteísmo', '=COUNTIF(procedimentos!$P:$P, "CANCELADO")', 'Consultas desmarcadas ou faltas'],
            ['Taxa Geral de Cancelamento (No-Show)', '=IF(B2>0, B8/B2, 0)', 'Índice de perda de capacidade de agenda'],
            ['Total de Atendimentos por Encaixe', '=COUNTIF(procedimentos!$Q:$Q, "SIM")', 'Atendimentos não programados previamente'],
            ['Taxa Geral de Encaixes', '=IF(B2>0, B10/B2, 0)', 'Percentual de encaixes sobre a agenda']
        ];
        $sheet8->fromArray($kpis, null, 'A2');

        $sheet8->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet8->getStyle('A1:C1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');
        $sheet8->getStyle('A1:C1')->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet8->getStyle('A2:A11')->getFont()->setBold(true);

        // Streaming Response do arquivo XLSX
        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $nomeArquivo = 'modelo_dashboard_procordis_' . date('Ymd_His') . '.xlsx';
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
