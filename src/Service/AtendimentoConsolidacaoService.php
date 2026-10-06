<?php

namespace App\Service;

use App\Entity\Atendimento;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Consolida a camada bruta (atendimento_captura_dia) em atendimento / atendimento_procedimento.
 *
 * Formato real de /Medware/Agendamento/Listar (sondagem de 06/10/2026, ver docs/integracao-medware-atendimentos.md):
 * - status vem como texto 'ATIVO' | 'CANCELADO' (no ListarResumido é -1 | 0);
 * - flags (consulta, encaixe, particular) vêm como 0 / -1; retorno como 'SIM' | 'NÃO';
 * - datas em dd/MM/yyyy HH:mm; codigoTuss vem sempre nulo, por isso a categoria sai da descrição do procedimento.
 *
 * Um dia capturado por completo é a verdade para aquela data: agendamentos que sumiram dele são removidos
 * (foram reagendados para outro dia, que os traz de volta com a data nova).
 */
class AtendimentoConsolidacaoService
{
    /** slug => [nome, cor, ordem, exibirNoSite] */
    public const CATEGORIAS_PADRAO = [
        'consulta' => ['Consultas', '#2563eb', 1, true],
        'ecocardiograma' => ['Ecocardiogramas', '#e11d48', 2, true],
        'eletrocardiograma' => ['Eletrocardiogramas (ECG)', '#f59e0b', 3, true],
        'teste-ergometrico' => ['Testes ergométricos (esteira)', '#10b981', 4, true],
        'holter' => ['Holter 24h', '#8b5cf6', 5, true],
        'mapa' => ['MAPA', '#06b6d4', 6, true],
        'outros' => ['Outros procedimentos', '#64748b', 7, true],
        'retirada-equipamento' => ['Retorno de Holter/MAPA (retirada)', '#94a3b8', 8, false],
    ];

    private Connection $conn;
    /** @var array<int, int> codProcedimento => id */
    private array $procedimentos = [];
    /** @var array<int, true> procedimentos já atualizados nesta execução */
    private array $procedimentosVistos = [];
    /** @var array<string, int> slug => id */
    private array $categorias = [];

    public function __construct(EntityManagerInterface $em)
    {
        $this->conn = $em->getConnection();
    }

    /**
     * Consolida os dias capturados ainda não processados (ou todos, com $todos).
     *
     * @param callable(string): void|null $log
     */
    public function processarPendentes(bool $todos = false, ?callable $log = null): array
    {
        $this->carregarCatalogos();
        if ($todos) {
            $this->conn->executeStatement('UPDATE atendimento_captura_dia SET processado_em = NULL');
        }

        $resumo = ['dias' => 0, 'agendamentos' => 0, 'realizados' => 0, 'removidos' => 0, 'ignorados' => 0];
        while (true) {
            $lote = $this->conn->fetchAllAssociative(
                'SELECT id, data, payload, completo FROM atendimento_captura_dia WHERE processado_em IS NULL AND payload IS NOT NULL ORDER BY data LIMIT 100'
            );
            if (!$lote) {
                break;
            }
            foreach ($lote as $dia) {
                $r = $this->processarDia((string) $dia['data'], (string) $dia['payload'], (bool) $dia['completo']);
                $this->conn->executeStatement('UPDATE atendimento_captura_dia SET processado_em = ? WHERE id = ?', [$this->agora(), $dia['id']]);
                $resumo['dias']++;
                foreach (['agendamentos', 'realizados', 'removidos', 'ignorados'] as $k) {
                    $resumo[$k] += $r[$k];
                }
            }
            if ($log) {
                $log(sprintf('%d dias consolidados (até %s), %d agendamentos, %d realizados.', $resumo['dias'], substr((string) end($lote)['data'], 0, 10), $resumo['agendamentos'], $resumo['realizados']));
            }
        }

        return $resumo;
    }

    /**
     * @return array{agendamentos: int, realizados: int, removidos: int, ignorados: int}
     */
    public function processarDia(string $data, string $payload, bool $completo): array
    {
        if (!$this->categorias) {
            $this->carregarCatalogos();
        }
        $itens = json_decode($payload, true);
        $r = ['agendamentos' => 0, 'realizados' => 0, 'removidos' => 0, 'ignorados' => 0];
        if (!is_array($itens) || !array_is_list($itens)) {
            return $r;
        }

        $codigos = [];
        $this->conn->beginTransaction();
        try {
            foreach ($itens as $item) {
                $linha = is_array($item) ? $this->linha($item) : null;
                if (!$linha) {
                    $r['ignorados']++;
                    continue;
                }
                $this->upsert($linha);
                $codigos[] = $linha['cod_agendamento'];
                $r['agendamentos']++;
                $r['realizados'] += $linha['realizado'];
            }

            if ($completo) {
                $sql = 'DELETE FROM atendimento WHERE data = ?';
                $params = [substr($data, 0, 10)];
                if ($codigos) {
                    $sql .= ' AND cod_agendamento NOT IN (' . implode(',', array_map('intval', $codigos)) . ')';
                }
                $r['removidos'] = (int) $this->conn->executeStatement($sql, $params);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        return $r;
    }

    /** Categoria sugerida a partir da descrição do procedimento e da flag "consulta" da API. */
    public static function classificar(string $descricao, bool $consulta = false): string
    {
        $d = strtr(mb_strtolower($descricao), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return match (true) {
            str_contains($d, 'retorno') && (str_contains($d, 'holter') || preg_match('/\bmapa\b/', $d) === 1) => 'retirada-equipamento',
            str_contains($d, 'holter') => 'holter',
            preg_match('/\bmapa\b/', $d) === 1 => 'mapa',
            str_contains($d, 'ecocardio') || preg_match('/\beco\b/', $d) === 1 => 'ecocardiograma',
            str_contains($d, 'eletrocardio') || preg_match('/\becg\b/', $d) === 1 => 'eletrocardiograma',
            str_contains($d, 'ergometr') || str_contains($d, 'esteira') => 'teste-ergometrico',
            $consulta || str_contains($d, 'consulta') => 'consulta',
            default => 'outros',
        };
    }

    /** Cria as categorias padrão que ainda não existem (a migration já cria; serve para bancos novos e testes). */
    public function garantirCategoriasPadrao(): void
    {
        foreach (self::CATEGORIAS_PADRAO as $slug => [$nome, $cor, $ordem, $site]) {
            $this->conn->executeStatement(
                'INSERT IGNORE INTO atendimento_categoria (slug, nome, cor, ordem, exibir_no_site) VALUES (?, ?, ?, ?, ?)',
                [$slug, $nome, $cor, $ordem, (int) $site]
            );
        }
    }

    private function linha(array $item): ?array
    {
        $cod = (int) ($item['codAgendamento'] ?? 0);
        $agendada = self::dataHora($item['dataHoraAgendada'] ?? null);
        if ($cod <= 0 || !$agendada) {
            return null;
        }

        $codStatus = (int) ($item['codStatusAgendamento'] ?? 0);
        $cancelado = self::cancelado($item['status'] ?? null);
        $proc = is_array($item['procedimentoPlanoOperadora'] ?? null) ? $item['procedimentoPlanoOperadora'] : [];
        $paciente = is_array($item['paciente'] ?? null) ? $item['paciente'] : [];
        $medico = is_array($item['medico'] ?? null) ? $item['medico'] : [];
        $sexo = strtoupper(trim((string) ($paciente['sexo'] ?? '')));
        $nascimento = self::dataHora($paciente['dataNascimento'] ?? null);
        $idade = $nascimento ? $nascimento->diff($agendada)->y : null;

        return [
            'cod_agendamento' => $cod,
            'data' => $agendada->format('Y-m-d'),
            'data_hora_agendada' => $agendada->format('Y-m-d H:i:s'),
            'cod_status_agendamento' => $codStatus,
            'cancelado' => (int) $cancelado,
            'realizado' => (int) (!$cancelado && in_array($codStatus, Atendimento::ESTAGIOS_REALIZADOS, true)),
            'procedimento_id' => $this->procedimentoId($proc),
            'cod_paciente' => isset($paciente['codPaciente']) ? (int) $paciente['codPaciente'] : null,
            'sexo' => in_array($sexo, ['F', 'M'], true) ? $sexo : null,
            'idade' => $idade !== null && $idade <= 120 ? $idade : null,
            'cod_medico' => isset($medico['codMedico']) ? (int) $medico['codMedico'] : null,
            'medico_nome' => self::texto($medico['nome'] ?? null, 150),
            'cod_plano' => isset($proc['codPlano']) ? (int) $proc['codPlano'] : null,
            'plano_descricao' => self::texto($proc['descricaoPlano'] ?? null, 150),
            'encaixe' => (int) self::flag($item['encaixe'] ?? null),
            'retorno' => (int) self::flag($item['retorno'] ?? null),
            'data_hora_chegada' => self::dataHora($item['dataHoraChegada'] ?? null)?->format('Y-m-d H:i:s'),
            'data_hora_liberacao' => self::dataHora($item['dataHoraLiberacao'] ?? null)?->format('Y-m-d H:i:s'),
            'atualizado_em' => $this->agora(),
        ];
    }

    private function upsert(array $linha): void
    {
        $colunas = array_keys($linha);
        $atualizar = implode(', ', array_map(fn ($c) => "$c = VALUES($c)", array_diff($colunas, ['cod_agendamento'])));
        $this->conn->executeStatement(
            sprintf('INSERT INTO atendimento (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s', implode(', ', $colunas), implode(', ', array_fill(0, count($colunas), '?')), $atualizar),
            array_values($linha)
        );
    }

    private function procedimentoId(array $proc): ?int
    {
        $cod = (int) ($proc['codProcedimento'] ?? 0);
        if ($cod <= 0) {
            return null;
        }
        $descricao = self::texto($proc['descricaoProcedimento'] ?? null, 255) ?? 'Procedimento ' . $cod;
        $consulta = self::flag($proc['consulta'] ?? null);

        if (!isset($this->procedimentos[$cod])) {
            $slug = self::classificar($descricao, $consulta);
            $this->conn->insert('atendimento_procedimento', [
                'cod_procedimento' => $cod,
                'descricao' => $descricao,
                'consulta' => (int) $consulta,
                'categoria_id' => $this->categorias[$slug] ?? $this->categorias['outros'] ?? null,
                'categoria_manual' => 0,
                'primeiro_visto_em' => $this->agora(),
                'ultimo_visto_em' => $this->agora(),
            ]);
            $this->procedimentos[$cod] = (int) $this->conn->lastInsertId();
            $this->procedimentosVistos[$cod] = true;
        } elseif (!isset($this->procedimentosVistos[$cod])) {
            $this->conn->executeStatement(
                'UPDATE atendimento_procedimento SET descricao = ?, consulta = ?, ultimo_visto_em = ? WHERE id = ?',
                [$descricao, (int) $consulta, $this->agora(), $this->procedimentos[$cod]]
            );
            $this->procedimentosVistos[$cod] = true;
        }

        return $this->procedimentos[$cod];
    }

    private function carregarCatalogos(): void
    {
        $this->garantirCategoriasPadrao();
        $this->categorias = array_map('intval', $this->conn->fetchAllKeyValue('SELECT slug, id FROM atendimento_categoria'));
        $this->procedimentos = array_map('intval', $this->conn->fetchAllKeyValue('SELECT cod_procedimento, id FROM atendimento_procedimento'));
        $this->procedimentosVistos = [];
    }

    /** 'CANCELADO' (Listar) ou 0 (ListarResumido) = cancelado; 'ATIVO' / -1 / ausente = ativo. */
    public static function cancelado(mixed $status): bool
    {
        if (is_string($status)) {
            $s = strtoupper(trim($status));

            return str_starts_with($s, 'CANCEL') || $s === '0';
        }

        return $status === 0 || $status === false;
    }

    /** Flags da API: -1 / 1 / true / 'SIM' / 'S' = verdadeiro. */
    public static function flag(mixed $v): bool
    {
        if (is_string($v)) {
            return in_array(strtoupper(trim($v)), ['SIM', 'S', '-1', '1', 'TRUE'], true);
        }

        return $v === true || $v === -1 || $v === 1;
    }

    public static function dataHora(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        foreach (['!d/m/Y H:i:s', '!d/m/Y H:i', '!d/m/Y', '!Y-m-d\TH:i:s', '!Y-m-d H:i:s', '!Y-m-d'] as $formato) {
            $d = \DateTimeImmutable::createFromFormat($formato, trim($v));
            if ($d && \DateTimeImmutable::getLastErrors() === false) {
                return $d;
            }
        }

        return null;
    }

    private static function texto(mixed $v, int $max): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function agora(): string
    {
        return (new \DateTime())->format('Y-m-d H:i:s');
    }
}
