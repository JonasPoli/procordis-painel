<?php

namespace App\Tests\Support;

/**
 * Itens de /Medware/Agendamento/Listar no formato real observado na sondagem de 06/10/2026
 * (status em texto, flags 0/-1, datas dd/MM/yyyy HH:mm, codigoTuss nulo).
 */
final class AtendimentoAmostra
{
    public const PROCEDIMENTOS = [
        5 => ['Consulta médica ambulatorial', -1],
        6 => ['Ecocardiograma transtorárico', 0],
        7 => ['Eletrocardiograma - EGPC', 0],
        8 => ['Holter', 0],
        9 => ['Mapa', 0],
        10 => ['Teste Ergométrico', 0],
        39 => ['Consulta de Retorno', -1],
        40 => ['Retorno Mapa', 0],
        41 => ['Retorno Holter', 0],
    ];

    public static function item(int $codAgendamento, string $dataHora, int $codProcedimento, int $codStatus = 5, string $status = 'ATIVO', int $codPaciente = 1000): array
    {
        [$descricao, $consulta] = self::PROCEDIMENTOS[$codProcedimento] ?? ['Procedimento ' . $codProcedimento, 0];

        return [
            'codAgenda' => 1,
            'codAgendamento' => $codAgendamento,
            'codStatusAgendamento' => $codStatus,
            'codUnidade' => 1,
            'dataHoraAgendada' => $dataHora,
            'dataHoraChegada' => $codStatus >= 3 ? $dataHora : '',
            'dataHoraLiberacao' => $codStatus === 5 ? $dataHora : '',
            'encaixe' => 0,
            'retorno' => 'NÃO',
            'status' => $status,
            'medico' => ['codMedico' => 7, 'nome' => 'DR TESTE', 'especialidade' => ''],
            'paciente' => ['codPaciente' => $codPaciente, 'nome' => 'PACIENTE ' . $codPaciente, 'cpf' => '00000000000', 'dataNascimento' => '01/02/1960', 'sexo' => 'F'],
            'procedimentoPlanoOperadora' => [
                'codProcedimento' => $codProcedimento, 'descricaoProcedimento' => $descricao, 'consulta' => $consulta,
                'codigoTuss' => null, 'codPlano' => 1, 'descricaoPlano' => 'SISTEMA ÚNICO DE SAÚDE (SUS) / BASICO', 'particular' => 0, 'valor' => '0,00',
            ],
        ];
    }

    /** Grava um dia na camada bruta (como a captura faria). */
    public static function gravarDia(\Doctrine\DBAL\Connection $conn, string $data, array $itens, bool $completo = true): void
    {
        $payload = json_encode($itens);
        $conn->executeStatement(
            'INSERT INTO atendimento_captura_dia (data, payload, hash_conteudo, qtd_registros, page_size, completo, http_status, tempo_ms, tentativas, capturado_em)
             VALUES (?, ?, ?, ?, 1000, ?, 200, 0, 1, NOW())
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), qtd_registros = VALUES(qtd_registros), completo = VALUES(completo), processado_em = NULL',
            [$data, $payload, hash('sha256', $payload), count($itens), (int) $completo]
        );
    }
}
