<?php

namespace App\Tests\Support;

use App\Service\AnamneseEstatisticaService;

/**
 * Base fictícia e determinística (sem banco e sem aleatoriedade) no formato de
 * AnamneseEstatisticaService::base(), para testar as análises e as telas com volume realista.
 */
final class AnamneseAmostra
{
    private const ITENS = [
        // nome, categoria, chance base (%), ganho por década acima dos 30 anos
        ['HIPERTENSÃO', 'comorbidade', 14, 11], ['DIABETES', 'comorbidade', 5, 6], ['DISLIPIDEMIA', 'comorbidade', 10, 7],
        ['OBESIDADE', 'comorbidade', 12, 1], ['INFARTO PRÉVIO', 'comorbidade', 0, 3], ['AVC', 'comorbidade', 0, 2],
        ['DERRAME', 'comorbidade', 0, 1], ['CATETERISMO', 'comorbidade', 1, 3], ['ANGIOPLASTIA', 'comorbidade', 0, 2],
        ['ARRITMIA', 'comorbidade', 3, 2], ['INSUFICIÊNCIA CARDÍACA', 'comorbidade', 0, 2], ['HIPOTIREOIDISMO', 'comorbidade', 5, 1],
        ['DOENÇA DE CHAGAS', 'comorbidade', 1, 1], ['DOENÇA RENAL CRÔNICA', 'comorbidade', 0, 1], ['QUIMIO', 'comorbidade', 1, 0],
        ['TRATAMENTO QUIMIO', 'comorbidade', 1, 0], ['TABAGISMO', 'fator_risco', 13, 0], ['EX-TABAGISTA', 'fator_risco', 6, 3],
        ['SEDENTARISMO', 'fator_risco', 30, 1], ['HISTÓRICO FAMILIAR DE DOENÇA CARDÍACA', 'fator_risco', 20, 0],
        ['USO DE ANTICOAGULANTE', 'medicacao', 1, 2], ['MARCAPASSO', 'medicacao', 0, 1], ['DOR TORÁCICA', 'sintoma', 12, 1],
        ['VACINA DA COVID DOSE 1', 'vacina', 0, 0], ['VACINA DA COVID DOSE 2', 'vacina', 0, 0],
        ['VACINA DA COVID DOSE 3', 'vacina', 0, 0], ['VACINA DA COVID DOSE 4', 'vacina', 0, 0],
    ];

    private const PROCEDIMENTOS = ['ELETROCARDIOGRAMA', 'ELETROCARDIOGRAMA', 'ELETROCARDIOGRAMA', 'ECOCARDIOGRAMA TRANSTORÁCICO', 'ECOCARDIOGRAMA TRANSTORÁCICO', 'TESTE ERGOMÉTRICO', 'HOLTER 24 HORAS', 'MAPA 24 HORAS', 'ECOCARDIOGRAMA COM ESTRESSE FARMACOLÓGICO', 'CINTILOGRAFIA DE PERFUSÃO MIOCÁRDICA'];

    private const FAIXA_DE_IDADE = [[18, '0–17'], [30, '18–29'], [40, '30–39'], [50, '40–49'], [60, '50–59'], [70, '60–69'], [80, '70–79'], [200, '80+']];

    private int $semente = 20260930;

    /**
     * @return array{catalogo: array, examesP: array, novos: array, meses: list<string>}
     */
    public static function base(int $pacientes = 900): array
    {
        return (new self())->gerar($pacientes);
    }

    private function gerar(int $pacientes): array
    {
        $catalogo = [];
        foreach (self::ITENS as $i => [$nome, $categoria]) {
            $catalogo[$i + 1] = ['nome' => $nome, 'categoria' => $categoria, 'dose' => $categoria === 'vacina' ? (int) substr($nome, -1) : null];
        }
        $meses = ['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'];

        $exames = [];
        $novos = [];
        $ag = 50000;
        for ($pid = 1; $pid <= $pacientes; $pid++) {
            $idade = 14 + $this->sorteio(76);
            $sexo = $this->sorteio(100) < 53 ? 'Feminino' : 'Masculino';
            if ($this->sorteio(100) < 2) {
                $sexo = 'Não informado';
            }
            $semIdade = $this->sorteio(100) < 2;
            $decadas = max(0, ($idade - 30) / 10);

            $cids = [];
            foreach (self::ITENS as $i => [, $categoria, $chance, $ganho]) {
                if ($categoria !== 'vacina' && $this->sorteio(100) < $chance + $ganho * $decadas) {
                    $cids[$i + 1] = true;
                }
            }
            $dose = $this->sorteio(6);
            if ($dose >= 1 && $dose <= 4) {
                $cids[23 + $dose] = true;
                if ($this->sorteio(100) < 8 && $dose > 1) {
                    $cids[23 + $dose - 1] = true; // duas doses marcadas ao mesmo tempo
                }
            }

            $visitas = 1 + ($this->sorteio(100) < 30 ? 1 : 0) + ($this->sorteio(100) < 8 ? 1 : 0);
            $mes = $this->sorteio(12 - ($visitas - 1) * 3);
            for ($v = 0; $v < $visitas; $v++) {
                if ($v > 0) {
                    $mes += 1 + $this->sorteio(3);
                    if ($this->sorteio(100) < 35) {
                        $novo = 1 + $this->sorteio(23);
                        if (!isset($cids[$novo])) {
                            $cids[$novo] = true;
                            $novos[] = ['ag' => $ag + 1, 'cid' => $novo];
                        }
                    }
                }
                $mes = min(11, $mes);
                $dt = sprintf('%s-%02d %02d:%02d:00', $meses[$mes], 1 + $this->sorteio(27), 7 + $this->sorteio(10), $this->sorteio(6) * 10);
                $repeticoes = $this->sorteio(100) < 6 ? 2 : 1; // dois exames no mesmo dia
                $proc = self::PROCEDIMENTOS[$this->sorteio(count(self::PROCEDIMENTOS))];
                for ($r = 0; $r < $repeticoes; $r++) {
                    $vinculado = $this->sorteio(100) >= 3;
                    $exames[++$ag] = [
                        'pid' => $pid,
                        'dt' => $dt,
                        'mes' => $meses[$mes],
                        'sexo' => $sexo,
                        'idade' => $semIdade ? null : $idade,
                        'faixa' => $semIdade ? 'Sem idade' : $this->faixa($idade),
                        'proc' => $vinculado ? ($r === 0 || $this->sorteio(2) ? $proc : 'ELETROCARDIOGRAMA') : 'SEM AGENDAMENTO VINCULADO',
                        'tipo' => $vinculado ? ['sus', 'sus', 'convenio', 'particular'][$this->sorteio(4)] : 'nd',
                        'medico' => $vinculado && $this->sorteio(100) >= 4 ? 1 + $this->sorteio(6) : null,
                        'temAgendamento' => $vinculado,
                        'temProcedimento' => $vinculado,
                        'cids' => $cids,
                    ];
                }
            }
        }
        uasort($exames, fn ($a, $b) => $a['dt'] <=> $b['dt']);

        return ['catalogo' => $catalogo, 'examesP' => $exames, 'novos' => $novos, 'meses' => $meses];
    }

    private function faixa(int $idade): string
    {
        foreach (self::FAIXA_DE_IDADE as [$limite, $faixa]) {
            if ($idade < $limite) {
                return $faixa;
            }
        }

        return AnamneseEstatisticaService::FAIXAS[7];
    }

    /** Gerador congruente linear: a mesma sequência em toda execução. */
    private function sorteio(int $limite): int
    {
        $this->semente = ($this->semente * 1103515245 + 12345) % 2147483648;

        return intdiv($this->semente, 65536) % max(1, $limite);
    }
}
