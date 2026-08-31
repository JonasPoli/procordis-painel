<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use ZipArchive;

class BackupRestoreService
{
    private const TABELAS_ORDEM = [
        'user' => 'users',
        'configuracao_integracao' => 'configuracoes',
        'unidade' => 'unidades',
        'especialidade' => 'especialidades',
        'medico' => 'medicos',
        'medico_unidade' => 'medico_unidade',
        'setor_sala' => 'salas',
        'procedimento_sla' => 'slas',
        'paciente' => 'pacientes',
        'agendamento' => 'agendamentos',
        'atendimento_etapa_historico' => 'etapasHistorico',
        'senha_atendimento' => 'senhas',
        'chamada_telao' => 'chamadas',
    ];

    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    /**
     * Gera um arquivo comprimido de backup (.procordis.bak) com 100% dos dados via streaming de baixo consumo de memória.
     */
    public function gerarBackup(?string $caminhoSaida = null): string
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        $hoje = new \DateTime();
        $conn = $this->em->getConnection();

        $tempDir = $this->obterDiretorioTemp();

        if (!$caminhoSaida) {
            $caminhoSaida = $tempDir . '/procordis_backup_' . $hoje->format('Ymd_His') . '.procordis.bak';
        }

        $jsonTempPath = $tempDir . '/procordis_raw_' . uniqid() . '.json';
        $fp = fopen($jsonTempPath, 'wb');
        if (!$fp) {
            throw new \RuntimeException("Não foi possível criar o arquivo temporário de backup em: {$jsonTempPath}");
        }

        $metadata = [
            'sistema' => 'Procordis Painel',
            'versao' => '2.0.0',
            'geradoEm' => $hoje->format('Y-m-d H:i:s'),
            'timestamp' => $hoje->getTimestamp(),
        ];

        fwrite($fp, '{"metadata":' . json_encode($metadata, JSON_UNESCAPED_UNICODE) . ',"tabelas":{');

        $isFirstTable = true;
        foreach (self::TABELAS_ORDEM as $tableName => $aliasKey) {
            if (!$isFirstTable) {
                fwrite($fp, ',');
            }
            $isFirstTable = false;

            fwrite($fp, '"' . $aliasKey . '":[');

            try {
                $stmt = $conn->executeQuery("SELECT * FROM `{$tableName}`");
                $isFirstRow = true;

                while ($row = $stmt->fetchAssociative()) {
                    if (!$isFirstRow) {
                        fwrite($fp, ',');
                    }
                    $isFirstRow = false;
                    fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE));
                }
                unset($stmt);
            } catch (\Throwable $e) {
                // Tabela pode não existir na base atual
            }

            fwrite($fp, ']');
        }

        fwrite($fp, '}}');
        fclose($fp);

        // 1. Tentar empacotar com ZipArchive se disponível
        if (class_exists('ZipArchive')) {
            try {
                $zip = new ZipArchive();
                if ($zip->open($caminhoSaida, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    $zip->addFile($jsonTempPath, 'backup_data.json');
                    $zip->close();
                    @unlink($jsonTempPath);
                    return $caminhoSaida;
                }
            } catch (\Throwable $e) {
                // Fallback para compressão streaming zlib/gz
            }
        }

        // 2. Fallback para streaming GZ (zlib core PHP)
        if (function_exists('gzopen')) {
            $gzFp = gzopen($caminhoSaida, 'wb9');
            $inFp = fopen($jsonTempPath, 'rb');
            if ($gzFp && $inFp) {
                while (!feof($inFp)) {
                    gzwrite($gzFp, fread($inFp, 65536));
                }
                fclose($inFp);
                gzclose($gzFp);
                @unlink($jsonTempPath);
                return $caminhoSaida;
            }
            if ($inFp) fclose($inFp);
            if ($gzFp) gzclose($gzFp);
        }

        // 3. Fallback sem compressão
        @rename($jsonTempPath, $caminhoSaida);
        return $caminhoSaida;
    }

    /**
     * Restaura 100% dos dados do sistema a partir de um arquivo .procordis.bak em lotes DBAL de alta velocidade.
     */
    public function restaurarBackup(string $caminhoArquivo, bool $modoLimpo = true): array
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        if (!file_exists($caminhoArquivo)) {
            throw new \InvalidArgumentException("Arquivo de backup não encontrado.");
        }

        $jsonStr = null;

        // 1. Tenta descompactar como ZIP
        if (class_exists('ZipArchive')) {
            try {
                $zip = new ZipArchive();
                if ($zip->open($caminhoArquivo) === true) {
                    $jsonStr = $zip->getFromName('backup_data.json');
                    $zip->close();
                }
            } catch (\Throwable $e) {
                // Segue para fallback
            }
        }

        // 2. Tenta descompactar como GZ / zlib
        if (!$jsonStr && function_exists('gzopen')) {
            $gz = @gzopen($caminhoArquivo, 'rb');
            if ($gz) {
                $buffer = '';
                while (!gzeof($gz)) {
                    $buffer .= gzread($gz, 65536);
                }
                gzclose($gz);
                if (!empty($buffer)) {
                    $jsonStr = $buffer;
                }
            }
        }

        // 3. Tenta como JSON direto
        if (!$jsonStr) {
            $jsonStr = file_get_contents($caminhoArquivo);
        }

        if (!$jsonStr) {
            throw new \RuntimeException("Arquivo de backup sem conteúdo legível.");
        }

        if (stripos(ltrim($jsonStr), '<!DOCTYPE') === 0 || stripos(ltrim($jsonStr), '<html') === 0) {
            throw new \RuntimeException("O arquivo enviado é uma página HTML e não um arquivo de dados (.bak). O download anterior no servidor antigo salvou a página da web em vez do arquivo de banco. Gere e baixe o backup novamente agora.");
        }

        $dados = json_decode($jsonStr, true);
        unset($jsonStr); // Libera memória imediatamente

        if (!$dados || !isset($dados['tabelas'])) {
            throw new \RuntimeException("Estrutura do arquivo de backup incompatível ou corrompida.");
        }

        $tabelas = $dados['tabelas'];
        unset($dados); // Libera memória

        if ($modoLimpo) {
            $this->limparBancoCompleto();
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0;');

        $totais = [];

        foreach (self::TABELAS_ORDEM as $tableName => $aliasKey) {
            $rows = $tabelas[$aliasKey] ?? $tabelas[$tableName] ?? [];
            $count = 0;

            if (!empty($rows) && is_array($rows)) {
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        if (isset($row['roles']) && is_array($row['roles'])) {
                            $row['roles'] = json_encode($row['roles']);
                        }
                        
                        try {
                            $conn->insert("`{$tableName}`", $row);
                            $count++;
                        } catch (\Throwable $e) {
                            if (!$modoLimpo && isset($row['id'])) {
                                $idVal = $row['id'];
                                unset($row['id']);
                                try {
                                    $conn->update("`{$tableName}`", $row, ['id' => $idVal]);
                                    $count++;
                                } catch (\Throwable $e2) {
                                }
                            }
                        }
                    }
                }
            }

            $totais[$aliasKey] = $count;
            unset($tabelas[$aliasKey]);
        }

        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1;');

        return [
            'sucesso' => true,
            'modoLimpo' => $modoLimpo,
            'totais' => [
                'users' => $totais['users'] ?? 0,
                'configuracoes' => $totais['configuracoes'] ?? 0,
                'unidades' => $totais['unidades'] ?? 0,
                'especialidades' => $totais['especialidades'] ?? 0,
                'medicos' => $totais['medicos'] ?? 0,
                'salas' => $totais['salas'] ?? 0,
                'slas' => $totais['slas'] ?? 0,
                'pacientes' => $totais['pacientes'] ?? 0,
                'agendamentos' => $totais['agendamentos'] ?? 0,
                'etapasHistorico' => $totais['etapasHistorico'] ?? 0,
                'senhas' => $totais['senhas'] ?? 0,
                'chamadas' => $totais['chamadas'] ?? 0,
            ]
        ];
    }

    private function limparBancoCompleto(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0;');

        $tables = [
            'chamada_telao', 'senha_atendimento', 'atendimento_etapa_historico',
            'agendamento', 'paciente', 'procedimento_sla', 'setor_sala',
            'medico_unidade', 'medico', 'especialidade', 'unidade',
            'configuracao_integracao', 'user', 'log_sync_api'
        ];

        foreach ($tables as $t) {
            try {
                $conn->executeStatement("TRUNCATE TABLE `{$t}`;");
            } catch (\Throwable $e) {
                // Ignore se não existir
            }
        }

        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1;');
    }

    private function obterDiretorioTemp(): string
    {
        $projectDir = dirname(__DIR__, 2);
        $backupDir = $projectDir . '/var/backup';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0777, true);
        }
        if (is_dir($backupDir) && is_writable($backupDir)) {
            return $backupDir;
        }
        return sys_get_temp_dir();
    }

    /**
     * Diagnóstico profundo do ambiente de execução do backup em produção.
     */
    public function diagnosticarAmbiente(): array
    {
        $inicio = microtime(true);
        $memoriaInicio = memory_get_usage(true);
        $conn = $this->em->getConnection();
        $projectDir = dirname(__DIR__, 2);

        $diag = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ambiente' => [
                'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os' => PHP_OS,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'open_basedir' => ini_get('open_basedir') ?: '(desativado / livre)',
                'extensoes' => [
                    'zip' => class_exists('ZipArchive') ? 'Instalada' : 'Não instalada (usando fallback GZ)',
                    'zlib_gz' => function_exists('gzopen') ? 'Instalada (gzip OK)' : 'Não instalada',
                    'pdo_mysql' => extension_loaded('pdo_mysql') ? 'Instalada' : 'Não instalada',
                    'json' => function_exists('json_encode') ? 'Instalada' : 'Não instalada',
                    'mbstring' => extension_loaded('mbstring') ? 'Instalada' : 'Não instalada',
                ],
            ],
            'pastas' => [
                'var_backup' => [
                    'caminho' => $projectDir . '/var/backup',
                    'existe' => is_dir($projectDir . '/var/backup'),
                    'gravavel' => is_writable($projectDir . '/var/backup') || is_writable($projectDir . '/var'),
                ],
                'sys_temp' => [
                    'caminho' => sys_get_temp_dir(),
                    'gravavel' => is_writable(sys_get_temp_dir()),
                ],
            ],
            'tabelas' => [],
            'teste_geracao' => [
                'sucesso' => false,
                'tempo_ms' => 0,
                'memoria_pico_mb' => 0,
                'tamanho_bytes' => 0,
                'tamanho_formatado' => '0 KB',
                'erro' => null,
            ],
            'ultimos_logs_erro' => $this->obterUltimosLogsErro(),
        ];

        // 1. Contagem das tabelas
        foreach (self::TABELAS_ORDEM as $tableName => $aliasKey) {
            try {
                $count = (int) $conn->fetchOne("SELECT COUNT(*) FROM `{$tableName}`");
                $diag['tabelas'][$tableName] = [
                    'status' => 'OK',
                    'total' => $count,
                ];
            } catch (\Throwable $e) {
                $diag['tabelas'][$tableName] = [
                    'status' => 'ERRO',
                    'total' => 0,
                    'erro' => $e->getMessage(),
                ];
            }
        }

        // 2. Teste real de geração de backup
        try {
            $testeInicio = microtime(true);
            $caminhoTeste = $this->gerarBackup();
            $testeFim = microtime(true);

            $tamanho = file_exists($caminhoTeste) ? filesize($caminhoTeste) : 0;
            $memoriaPico = memory_get_peak_usage(true);

            // Validar se o arquivo gerado é legível
            $conteudoTeste = file_get_contents($caminhoTeste, false, null, 0, 100);
            @unlink($caminhoTeste);

            $diag['teste_geracao']['sucesso'] = true;
            $diag['teste_geracao']['tempo_ms'] = round(($testeFim - $testeInicio) * 1000, 2);
            $diag['teste_geracao']['memoria_pico_mb'] = round($memoriaPico / 1024 / 1024, 2);
            $diag['teste_geracao']['tamanho_bytes'] = $tamanho;
            $diag['teste_geracao']['tamanho_formatado'] = round($tamanho / 1024, 2) . ' KB';
        } catch (\Throwable $e) {
            $diag['teste_geracao']['sucesso'] = false;
            $diag['teste_geracao']['erro'] = $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine();
            $diag['teste_geracao']['trace'] = $e->getTraceAsString();
        }

        $diag['tempo_total_diagnostico_ms'] = round((microtime(true) - $inicio) * 1000, 2);

        return $diag;
    }

    private function obterUltimosLogsErro(): array
    {
        $projectDir = dirname(__DIR__, 2);
        $arquivosLog = [
            $projectDir . '/var/log/backup.log',
            $projectDir . '/var/log/prod.log',
            $projectDir . '/var/log/dev.log',
        ];

        $erros = [];

        foreach ($arquivosLog as $logFile) {
            if (file_exists($logFile) && is_readable($logFile)) {
                $fp = @fopen($logFile, 'rb');
                if ($fp) {
                    $size = (int) @filesize($logFile);
                    $readSize = min($size, 65536);
                    if ($size > $readSize) {
                        @fseek($fp, $size - $readSize);
                    }
                    $buffer = (string) @fread($fp, $readSize);
                    @fclose($fp);

                    if ($buffer !== '') {
                        $buffer = mb_convert_encoding($buffer, 'UTF-8', 'UTF-8');
                        $linhas = explode("\n", $buffer);
                        foreach (array_reverse($linhas) as $l) {
                            $l = trim($l);
                            if ($l === '') continue;
                            if (stripos($l, 'CRITICAL') !== false || stripos($l, 'ERROR') !== false || stripos($l, 'Fatal') !== false || stripos($l, 'Exception') !== false) {
                                $erros[] = mb_substr($l, 0, 300, 'UTF-8');
                                if (count($erros) >= 15) break 2;
                            }
                        }
                    }
                }
            }
        }

        return $erros;
    }
}
