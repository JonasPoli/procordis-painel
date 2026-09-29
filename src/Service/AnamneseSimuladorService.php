<?php

namespace App\Service;

use App\Entity\ClassificacaoEstudo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gera dados simulados e plausíveis de anamnese (clínica cardiológica) para o painel funcionar
 * enquanto a integração está em modo simulação. Pacientes simulados têm codigo_externo "SIM-…"
 * e cod_paciente a partir de 90.000.000; agendamentos simulados têm código "SIM-…".
 */
class AnamneseSimuladorService
{
    public const COD_PACIENTE_BASE = 90000000;

    /** cod => [nome, categoria] — códigos alinhados com o exemplo real da API (2 = Diabetes, 3 = Hipertensão, 19/20 = Covid dose 4/5). */
    private const CATALOGO = [
        1 => 'DISLIPIDEMIA',
        2 => 'DIABETES',
        3 => 'HIPERTENSÃO',
        4 => 'TABAGISMO',
        5 => 'EX-TABAGISTA',
        6 => 'OBESIDADE',
        7 => 'SEDENTARISMO',
        8 => 'HISTÓRICO FAMILIAR DE DOENÇA CARDÍACA',
        9 => 'INFARTO PRÉVIO',
        10 => 'ARRITMIA',
        11 => 'INSUFICIÊNCIA CARDÍACA',
        12 => 'DOENÇA RENAL CRÔNICA',
        13 => 'AVC PRÉVIO',
        14 => 'HIPOTIREOIDISMO',
        15 => 'USO DE ANTICOAGULANTE',
        16 => 'VACINA DA COVID DOSE 1',
        17 => 'VACINA DA COVID DOSE 2',
        18 => 'VACINA DA COVID DOSE 3',
        19 => 'VACINA DA COVID DOSE 4',
        20 => 'VACINA DA COVID DOSE 5',
        21 => 'MARCAPASSO',
    ];

    private const PROCEDIMENTOS = [
        ['ELETROCARDIOGRAMA', 35], ['ECOCARDIOGRAMA TRANSTORÁCICO', 20], ['TESTE ERGOMÉTRICO', 13],
        ['HOLTER 24 HORAS', 12], ['CONSULTA CARDIOLÓGICA', 12], ['MAPA 24 HORAS', 8],
    ];

    private const CONVENIOS = [
        ['sus', 'SUS - Sistema Único de Saúde', 55], ['convenio', 'UNIMED', 18], ['convenio', 'CABESP', 7],
        ['convenio', 'BRADESCO SAÚDE', 6], ['convenio', 'SULAMÉRICA', 4], ['particular', 'PARTICULAR', 10],
    ];

    private const NOMES_F = ['Maria', 'Ana', 'Francisca', 'Antônia', 'Adriana', 'Juliana', 'Márcia', 'Fernanda', 'Patrícia', 'Aline', 'Sandra', 'Cláudia', 'Lúcia', 'Helena', 'Beatriz'];
    private const NOMES_M = ['José', 'João', 'Antônio', 'Francisco', 'Carlos', 'Paulo', 'Pedro', 'Lucas', 'Luiz', 'Marcos', 'Rafael', 'Sérgio', 'Roberto', 'Eduardo', 'Jorge'];
    private const SOBRENOMES = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira', 'Lima', 'Gomes', 'Costa', 'Ribeiro', 'Martins', 'Carvalho', 'Almeida', 'Lopes', 'Barbosa', 'Rocha'];

    private array $mapaClass = [];
    private array $medicos = [];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function existeBaseSimulada(): bool
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM paciente WHERE codigo_externo LIKE 'SIM-%'") > 0;
    }

    public function garantirBaseSimulada(int $pacientes = 2200, int $meses = 24): array
    {
        if ($this->existeBaseSimulada()) {
            return ['gerado' => false, 'motivo' => 'Base simulada já existe'] + $this->gerarExamesDoDia(new \DateTime());
        }

        return $this->gerarBase($pacientes, $meses);
    }

    /**
     * Gera o histórico completo de pacientes e exames simulados.
     */
    public function gerarBase(int $qtdPacientes = 2200, int $meses = 24): array
    {
        mt_srand(20260929);
        $conn = $this->em->getConnection();
        $this->prepararApoios();

        $hoje = new \DateTime();
        $inicio = (clone $hoje)->modify("-{$meses} months");
        $spanDias = (int) $inicio->diff($hoje)->days;
        $proxCod = $this->proximoCodPaciente();

        $totExames = 0;
        $totClass = 0;
        $conn->beginTransaction();
        try {
            for ($i = 0; $i < $qtdPacientes; $i++) {
                $pac = $this->criarPaciente($proxCod++);
                $n = $this->sortearQtdExames();
                // Volume cresce ~40% no período: sorteio enviesado para datas recentes
                $datas = [];
                for ($k = 0; $k < $n; $k++) {
                    // densidade cresce ao longo do período (mais exames nos meses recentes)
                    $dia = (int) round($spanDias * sqrt(mt_rand() / mt_getrandmax()));
                    $datas[] = (clone $inicio)->modify('+' . max(0, min($spanDias, $dia)) . ' days');
                }
                usort($datas, fn ($a, $b) => $a <=> $b);

                $estado = $this->estadoInicial($pac['idade'], $pac['sexo']);
                foreach ($datas as $k => $data) {
                    if ($k > 0) {
                        $estado = $this->evoluir($estado, $pac['idade'], $pac['sexo']);
                    }
                    $totClass += $this->gravarExame($pac, $data, $estado, $k);
                    $totExames++;
                }
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return ['gerado' => true, 'pacientes' => $qtdPacientes, 'exames' => $totExames, 'classificacoes' => $totClass];
    }

    /**
     * Acrescenta alguns exames no dia informado (usado pelo sync incremental em modo simulação).
     */
    public function gerarExamesDoDia(\DateTimeInterface $dia, int $qtd = 8): array
    {
        $conn = $this->em->getConnection();
        $this->prepararApoios();
        $proxCod = $this->proximoCodPaciente();
        $aleatorio = $conn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform ? 'RAND()' : 'RANDOM()';
        $retornos = $conn->fetchAllAssociative(
            "SELECT id, cod_paciente, sexo, data_nascimento FROM paciente WHERE codigo_externo LIKE 'SIM-%' ORDER BY {$aleatorio} LIMIT 200"
        );

        $exames = 0;
        $class = 0;
        $conn->beginTransaction();
        try {
            for ($i = 0; $i < $qtd; $i++) {
                if ($retornos && mt_rand(1, 100) <= 40) {
                    $r = $retornos[array_rand($retornos)];
                    $idade = $r['data_nascimento'] ? (new \DateTime($r['data_nascimento']))->diff(new \DateTime())->y : 55;
                    $pac = ['id' => (int) $r['id'], 'cod' => (int) $r['cod_paciente'], 'idade' => $idade, 'sexo' => $r['sexo'] ?: 'F'];
                    $estado = $this->estadoAtualDoBanco((int) $r['id']) ?: $this->estadoInicial($idade, $pac['sexo']);
                    $estado = $this->evoluir($estado, $idade, $pac['sexo']);
                    $ordem = 1;
                } else {
                    $pac = $this->criarPaciente($proxCod++);
                    $estado = $this->estadoInicial($pac['idade'], $pac['sexo']);
                    $ordem = 0;
                }
                $data = \DateTime::createFromInterface($dia);
                $class += $this->gravarExame($pac, $data, $estado, $ordem);
                $exames++;
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return ['exames' => $exames, 'classificacoes' => $class];
    }

    public function removerBaseSimulada(): int
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM exame_classificacao WHERE paciente_id IN (SELECT id FROM paciente WHERE codigo_externo LIKE 'SIM-%')");
        $conn->executeStatement("DELETE FROM agendamento WHERE codigo_agendamento LIKE 'SIM-%'");

        return (int) $conn->executeStatement("DELETE FROM paciente WHERE codigo_externo LIKE 'SIM-%'");
    }

    // ---------------------------------------------------------------------

    private function prepararApoios(): void
    {
        $conn = $this->em->getConnection();
        $agora = (new \DateTime())->format('Y-m-d H:i:s');
        $existentes = [];
        foreach ($conn->fetchAllAssociative('SELECT id, cod_classificacao FROM classificacao_estudo') as $r) {
            $existentes[(int) $r['cod_classificacao']] = (int) $r['id'];
        }
        foreach (self::CATALOGO as $cod => $nome) {
            if (!isset($existentes[$cod])) {
                $conn->insert('classificacao_estudo', [
                    'cod_classificacao' => $cod, 'nome' => $nome, 'codigo' => $cod >= 16 && $cod <= 20 ? (string) $cod : null,
                    'tipo' => 0, 'categoria' => ClassificacaoEstudo::sugerirCategoria($nome), 'categoria_manual' => 0,
                    'ativo' => 1, 'presente_na_api' => 1, 'primeiro_visto_em' => $agora, 'ultimo_visto_em' => $agora,
                ]);
                $existentes[$cod] = (int) $conn->lastInsertId();
            }
        }
        $this->mapaClass = $existentes;
        $this->medicos = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM medico'));
    }

    private function proximoCodPaciente(): int
    {
        $max = $this->em->getConnection()->fetchOne("SELECT MAX(cod_paciente) FROM paciente WHERE codigo_externo LIKE 'SIM-%'");

        return max(self::COD_PACIENTE_BASE, (int) $max) + 1;
    }

    private function criarPaciente(int $cod): array
    {
        $sexo = mt_rand(1, 100) <= 56 ? 'F' : 'M';
        // Idade de clínica cardiológica: concentrada entre 45 e 75
        $idade = (int) max(14, min(96, round($this->normal(58, 15))));
        $nasc = (new \DateTime())->modify("-{$idade} years")->modify('-' . mt_rand(0, 364) . ' days');
        $nome = ($sexo === 'F' ? self::NOMES_F[array_rand(self::NOMES_F)] : self::NOMES_M[array_rand(self::NOMES_M)])
            . ' ' . self::SOBRENOMES[array_rand(self::SOBRENOMES)] . ' ' . self::SOBRENOMES[array_rand(self::SOBRENOMES)];
        $partes = explode(' ', $nome);

        $conn = $this->em->getConnection();
        $agora = (new \DateTime())->format('Y-m-d H:i:s');
        $conn->insert('paciente', [
            'codigo_externo' => 'SIM-' . $cod, 'cod_paciente' => $cod, 'nome_completo' => $nome,
            'nome_exibicao' => $partes[0] . ' ' . mb_substr(end($partes), 0, 1) . '.',
            'data_nascimento' => $nasc->format('Y-m-d'), 'sexo' => $sexo,
            'primeiro_visto_em' => $agora, 'atualizado_em' => $agora,
        ]);

        return ['id' => (int) $conn->lastInsertId(), 'cod' => $cod, 'idade' => $idade, 'sexo' => $sexo];
    }

    /** Condições crônicas + vacina (dose máxima) de um paciente, com correlações clínicas plausíveis. */
    private function estadoInicial(int $idade, string $sexo): array
    {
        $m = $sexo === 'M';
        $a = fn (float $jovem, float $idoso) => $jovem + ($idoso - $jovem) * $this->sigmoide(($idade - 52) / 9);
        $c = [];
        $c[6] = $this->p(0.24);                                             // obesidade
        $c[7] = $this->p(0.38);                                             // sedentarismo
        $c[8] = $this->p(0.27);                                             // histórico familiar
        $fumante = $this->p($m ? 0.17 : 0.11);
        $c[4] = $fumante && $idade < 75;
        $c[5] = !$c[4] && $this->p($a(0.04, 0.24) * ($m ? 1.3 : 0.8));
        $c[3] = $this->p(min(0.9, $a(0.07, 0.62) * ($c[6] ? 1.35 : 1)));    // hipertensão
        $c[2] = $this->p(min(0.8, $a(0.02, 0.27) * ($c[6] ? 1.8 : 1) * ($c[3] ? 1.3 : 1))); // diabetes
        $c[1] = $this->p(min(0.8, $a(0.08, 0.42) * ($c[2] ? 1.4 : 1)));    // dislipidemia
        $c[9] = $this->p($a(0.004, 0.09) * ($m ? 1.8 : 0.7) * (($c[4] || $c[5]) ? 1.6 : 1) * ($c[2] ? 1.5 : 1));
        $c[10] = $this->p($a(0.02, 0.16));                                   // arritmia
        $c[11] = $this->p($a(0.003, 0.08) * ($c[9] ? 3 : 1));               // IC
        $c[12] = $this->p($a(0.005, 0.07) * ($c[2] ? 2.2 : 1) * ($c[3] ? 1.4 : 1));
        $c[13] = $this->p($a(0.003, 0.05) * ($c[3] ? 1.6 : 1));             // AVC
        $c[14] = $this->p($m ? 0.02 : 0.09);                                 // hipotireoidismo
        $c[15] = ($c[10] && $this->p(0.45)) || ($c[13] && $this->p(0.3));   // anticoagulante
        $c[21] = $this->p($a(0.0, 0.025) * ($c[10] ? 3 : 1));               // marcapasso

        // Vacina: dose máxima declarada (0 = não declarou). Idosos com mais doses.
        $dose = 0;
        if ($this->p(0.78)) {
            $pesos = $idade >= 60 ? [4, 10, 22, 34, 30] : ($idade >= 40 ? [6, 18, 36, 28, 12] : [12, 30, 38, 15, 5]);
            $dose = $this->sortearPeso([1, 2, 3, 4, 5], $pesos);
        }

        return ['c' => array_filter($c), 'dose' => $dose];
    }

    /** Evolução entre um exame e o próximo: incidência de novas condições, parar de fumar, novas doses. */
    private function evoluir(array $estado, int $idade, string $sexo): array
    {
        $base = $this->estadoInicial($idade, $sexo);
        foreach ($base['c'] as $cod => $v) {
            if (!isset($estado['c'][$cod]) && $this->p(0.14)) {
                $estado['c'][$cod] = true;
            }
        }
        if (isset($estado['c'][4]) && $this->p(0.15)) {
            unset($estado['c'][4]);
            $estado['c'][5] = true;
        }
        if ($estado['dose'] > 0 && $estado['dose'] < 5 && $this->p(0.2)) {
            $estado['dose']++;
        }

        return $estado;
    }

    private function estadoAtualDoBanco(int $pacienteId): ?array
    {
        $cods = $this->em->getConnection()->fetchFirstColumn(
            'SELECT c.cod_classificacao FROM exame_classificacao ec JOIN classificacao_estudo c ON c.id = ec.classificacao_id
             WHERE ec.paciente_id = ? AND ec.data_exame = (SELECT MAX(data_exame) FROM exame_classificacao WHERE paciente_id = ?)',
            [$pacienteId, $pacienteId]
        );
        if (!$cods) {
            return null;
        }
        $estado = ['c' => [], 'dose' => 0];
        foreach ($cods as $cod) {
            $cod = (int) $cod;
            if ($cod >= 16 && $cod <= 20) {
                $estado['dose'] = $cod - 15;
            } else {
                $estado['c'][$cod] = true;
            }
        }

        return $estado;
    }

    private function gravarExame(array $pac, \DateTime $data, array $estado, int $ordem): int
    {
        $conn = $this->em->getConnection();
        $data = (clone $data)->setTime(mt_rand(7, 17), [0, 10, 20, 30, 40, 50][mt_rand(0, 5)]);
        $codAg = 'SIM-' . $pac['cod'] . '-' . $data->format('ymdHi') . mt_rand(10, 99);
        [$tipo, $convenio] = $this->sortearConvenio();

        $conn->insert('agendamento', [
            'paciente_id' => $pac['id'],
            'medico_id' => $this->medicos ? $this->medicos[array_rand($this->medicos)] : null,
            'codigo_agendamento' => $codAg,
            'data_hora_agendada' => $data->format('Y-m-d H:i:s'),
            'horario_chegada' => (clone $data)->modify('-' . mt_rand(5, 40) . ' minutes')->format('Y-m-d H:i:s'),
            'horario_saida' => (clone $data)->modify('+' . mt_rand(15, 70) . ' minutes')->format('Y-m-d H:i:s'),
            'status' => 'finalizado', 'prioridade' => $pac['idade'] >= 60 ? 1 : 0, 'encaixe' => 0, 'qtd_exames' => 1,
            'procedimento_nome' => $this->sortearPeso(array_column(self::PROCEDIMENTOS, 0), array_column(self::PROCEDIMENTOS, 1)),
            'tipo_atendimento' => $tipo, 'convenio_nome' => $convenio,
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
        $agId = (int) $conn->lastInsertId();

        // ~16% dos exames ficam sem anamnese preenchida (mede a qualidade do preenchimento)
        if ($this->p(0.16)) {
            return 0;
        }

        $cods = array_keys($estado['c']);
        if ($estado['dose'] > 0) {
            $cods[] = 15 + $estado['dose'];
        }
        // Retornos às vezes omitem itens (anamnese resumida)
        if ($ordem > 0) {
            $cods = array_values(array_filter($cods, fn () => $this->p(0.9)));
        }
        // Anamnese com zero itens não aparece na API
        $agora = (new \DateTime())->format('Y-m-d H:i:s');
        foreach (array_unique($cods) as $cod) {
            $conn->insert('exame_classificacao', [
                'paciente_id' => $pac['id'], 'classificacao_id' => $this->mapaClass[$cod], 'agendamento_id' => $agId,
                'cod_agendamento' => $codAg, 'cod_paciente' => $pac['cod'], 'data_exame' => $data->format('Y-m-d H:i:s'),
                'primeiro_visto_em' => $agora, 'ultimo_visto_em' => $agora,
            ]);
        }

        return count($cods);
    }

    private function sortearQtdExames(): int
    {
        return $this->sortearPeso([1, 2, 3, 4, 5], [54, 25, 12, 6, 3]);
    }

    private function sortearConvenio(): array
    {
        $i = $this->sortearPeso(array_keys(self::CONVENIOS), array_column(self::CONVENIOS, 2));

        return [self::CONVENIOS[$i][0], self::CONVENIOS[$i][1]];
    }

    private function sortearPeso(array $valores, array $pesos): mixed
    {
        $r = mt_rand(1, (int) array_sum($pesos));
        foreach ($valores as $i => $v) {
            $r -= $pesos[$i];
            if ($r <= 0) {
                return $v;
            }
        }

        return end($valores);
    }

    private function p(float $prob): bool
    {
        return mt_rand() / mt_getrandmax() < $prob;
    }

    private function sigmoide(float $x): float
    {
        return 1 / (1 + exp(-$x));
    }

    private function normal(float $media, float $dp): float
    {
        $u = max(1e-9, mt_rand() / mt_getrandmax());
        $v = mt_rand() / mt_getrandmax();

        return $media + $dp * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }
}
