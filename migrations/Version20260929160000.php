<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Anamnese (ClassificacaoEstudo): catálogo, classificações por exame com histórico,
 * histórico de paciente, captura bruta da API e registro das execuções de sync.
 * Paciente passa a ter cod_paciente (código Medware) como chave única.
 */
final class Version20260929160000 extends AbstractMigration
{
    private const OPCOES_TABELA = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB';

    public function getDescription(): string
    {
        return 'Anamnese: classificacao_estudo, exame_classificacao, paciente_historico, api_captura, anamnese_sync_execucao e paciente.cod_paciente';
    }

    public function up(Schema $schema): void
    {
        $o = self::OPCOES_TABELA;

        $this->addSql("CREATE TABLE classificacao_estudo (id INT AUTO_INCREMENT NOT NULL, cod_classificacao INT NOT NULL, nome VARCHAR(255) NOT NULL, codigo VARCHAR(50) DEFAULT NULL, tipo INT DEFAULT NULL, categoria VARCHAR(30) DEFAULT 'comorbidade' NOT NULL, categoria_manual TINYINT(1) DEFAULT 0 NOT NULL, ativo TINYINT(1) DEFAULT 1 NOT NULL, presente_na_api TINYINT(1) DEFAULT 1 NOT NULL, dados_brutos JSON DEFAULT NULL, primeiro_visto_em DATETIME NOT NULL, ultimo_visto_em DATETIME NOT NULL, UNIQUE INDEX uniq_classificacao_cod (cod_classificacao), PRIMARY KEY(id)) $o");

        $this->addSql("CREATE TABLE exame_classificacao (id INT AUTO_INCREMENT NOT NULL, paciente_id INT NOT NULL, classificacao_id INT NOT NULL, agendamento_id INT DEFAULT NULL, cod_agendamento VARCHAR(50) NOT NULL, cod_paciente INT NOT NULL, data_exame DATETIME NOT NULL, nome_paciente_api VARCHAR(255) DEFAULT NULL, primeiro_visto_em DATETIME NOT NULL, ultimo_visto_em DATETIME NOT NULL, removido_em DATETIME DEFAULT NULL, INDEX IDX_F0797E277310DAD4 (paciente_id), INDEX IDX_F0797E271AB36034 (classificacao_id), INDEX IDX_F0797E27C427592F (agendamento_id), INDEX idx_exame_class_data (data_exame), INDEX idx_exame_class_cod_paciente (cod_paciente), INDEX idx_exame_class_removido (removido_em), UNIQUE INDEX uniq_exame_classificacao (cod_agendamento, classificacao_id), PRIMARY KEY(id)) $o");

        $this->addSql("CREATE TABLE paciente_historico (id INT AUTO_INCREMENT NOT NULL, paciente_id INT NOT NULL, campo VARCHAR(50) NOT NULL, valor_anterior LONGTEXT DEFAULT NULL, valor_novo LONGTEXT DEFAULT NULL, origem VARCHAR(100) NOT NULL, registrado_em DATETIME NOT NULL, INDEX IDX_A7EDE7EB7310DAD4 (paciente_id), INDEX idx_paciente_hist_data (registrado_em), PRIMARY KEY(id)) $o");

        $this->addSql("CREATE TABLE api_captura (id INT AUTO_INCREMENT NOT NULL, endpoint VARCHAR(255) NOT NULL, parametros JSON NOT NULL, chave_consulta VARCHAR(40) NOT NULL, hash_conteudo VARCHAR(64) NOT NULL, payload LONGTEXT NOT NULL, qtd_registros INT DEFAULT 0 NOT NULL, http_status INT DEFAULT 200 NOT NULL, capturado_em DATETIME NOT NULL, ultima_verificacao_em DATETIME NOT NULL, vezes_verificado INT DEFAULT 1 NOT NULL, INDEX idx_api_captura_chave (chave_consulta, hash_conteudo), INDEX idx_api_captura_data (capturado_em), PRIMARY KEY(id)) $o");

        $this->addSql("CREATE TABLE anamnese_sync_execucao (id INT AUTO_INCREMENT NOT NULL, modo VARCHAR(20) NOT NULL, origem VARCHAR(20) DEFAULT 'cli' NOT NULL, status VARCHAR(20) NOT NULL, iniciado_em DATETIME NOT NULL, finalizado_em DATETIME DEFAULT NULL, resumo JSON DEFAULT NULL, erros LONGTEXT DEFAULT NULL, INDEX idx_anamnese_sync_inicio (iniciado_em), PRIMARY KEY(id)) $o");

        $this->addSql('ALTER TABLE exame_classificacao ADD CONSTRAINT FK_F0797E277310DAD4 FOREIGN KEY (paciente_id) REFERENCES paciente (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE exame_classificacao ADD CONSTRAINT FK_F0797E271AB36034 FOREIGN KEY (classificacao_id) REFERENCES classificacao_estudo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE exame_classificacao ADD CONSTRAINT FK_F0797E27C427592F FOREIGN KEY (agendamento_id) REFERENCES agendamento (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE paciente_historico ADD CONSTRAINT FK_A7EDE7EB7310DAD4 FOREIGN KEY (paciente_id) REFERENCES paciente (id) ON DELETE CASCADE');

        // Paciente: código Medware como chave real + datas de controle
        $this->addSql('ALTER TABLE paciente ADD cod_paciente INT DEFAULT NULL, ADD primeiro_visto_em DATETIME DEFAULT NULL, ADD atualizado_em DATETIME DEFAULT NULL');

        // Aproveita o codigo_externo "PAC-123" gravado pelo sync de agendamentos — só para pacientes que vieram da API real
        // (têm agendamento com código numérico; o simulador usa "AGD-…" e "PAC-<aleatório>") e sem duplicidade.
        $this->addSql("UPDATE paciente p INNER JOIN (SELECT codigo_externo FROM paciente WHERE codigo_externo REGEXP '^PAC-[0-9]+$' GROUP BY codigo_externo HAVING COUNT(*) = 1) u ON u.codigo_externo = p.codigo_externo SET p.cod_paciente = CAST(SUBSTRING(p.codigo_externo, 5) AS UNSIGNED) WHERE EXISTS (SELECT 1 FROM agendamento a WHERE a.paciente_id = p.id AND a.codigo_agendamento REGEXP '^[0-9]+$')");
        $this->addSql('CREATE UNIQUE INDEX uniq_paciente_cod_paciente ON paciente (cod_paciente)');

        // Busca de agendamentos por código (usada no vínculo exame x agendamento)
        $this->addSql('CREATE INDEX idx_agendamento_codigo ON agendamento (codigo_agendamento)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exame_classificacao DROP FOREIGN KEY FK_F0797E277310DAD4');
        $this->addSql('ALTER TABLE exame_classificacao DROP FOREIGN KEY FK_F0797E271AB36034');
        $this->addSql('ALTER TABLE exame_classificacao DROP FOREIGN KEY FK_F0797E27C427592F');
        $this->addSql('ALTER TABLE paciente_historico DROP FOREIGN KEY FK_A7EDE7EB7310DAD4');
        $this->addSql('DROP TABLE exame_classificacao');
        $this->addSql('DROP TABLE classificacao_estudo');
        $this->addSql('DROP TABLE paciente_historico');
        $this->addSql('DROP TABLE api_captura');
        $this->addSql('DROP TABLE anamnese_sync_execucao');
        $this->addSql('DROP INDEX uniq_paciente_cod_paciente ON paciente');
        $this->addSql('ALTER TABLE paciente DROP cod_paciente, DROP primeiro_visto_em, DROP atualizado_em');
        $this->addSql('DROP INDEX idx_agendamento_codigo ON agendamento');
    }
}
