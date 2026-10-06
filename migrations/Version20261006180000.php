<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Painel de atendimentos: camada consolidada (atendimento, procedimentos, categorias) e controle de processamento
 * da camada bruta. As categorias padrão espelham AtendimentoConsolidacaoService::CATEGORIAS_PADRAO.
 */
final class Version20261006180000 extends AbstractMigration
{
    private const OPCOES_TABELA = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB';

    public function getDescription(): string
    {
        return 'Painel de atendimentos: atendimento, atendimento_procedimento, atendimento_categoria e atendimento_captura_dia.processado_em';
    }

    public function up(Schema $schema): void
    {
        $o = self::OPCOES_TABELA;

        $this->addSql('ALTER TABLE atendimento_captura_dia ADD processado_em DATETIME DEFAULT NULL');

        $this->addSql("CREATE TABLE atendimento_categoria (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(60) NOT NULL, nome VARCHAR(100) NOT NULL, cor VARCHAR(7) DEFAULT '#64748b' NOT NULL, ordem INT DEFAULT 0 NOT NULL, exibir_no_site TINYINT(1) DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_atendimento_categoria_slug (slug), PRIMARY KEY(id)) $o");

        $this->addSql("CREATE TABLE atendimento_procedimento (id INT AUTO_INCREMENT NOT NULL, categoria_id INT DEFAULT NULL, cod_procedimento INT NOT NULL, descricao VARCHAR(255) NOT NULL, consulta TINYINT(1) DEFAULT 0 NOT NULL, categoria_manual TINYINT(1) DEFAULT 0 NOT NULL, primeiro_visto_em DATETIME NOT NULL, ultimo_visto_em DATETIME NOT NULL, INDEX IDX_D54E843B3397707A (categoria_id), UNIQUE INDEX uniq_atendimento_procedimento_cod (cod_procedimento), PRIMARY KEY(id)) $o");

        $this->addSql("CREATE TABLE atendimento (id INT AUTO_INCREMENT NOT NULL, procedimento_id INT DEFAULT NULL, cod_agendamento INT NOT NULL, data DATE NOT NULL, data_hora_agendada DATETIME NOT NULL, cod_status_agendamento SMALLINT NOT NULL, cancelado TINYINT(1) DEFAULT 0 NOT NULL, realizado TINYINT(1) DEFAULT 0 NOT NULL, cod_paciente INT DEFAULT NULL, sexo VARCHAR(1) DEFAULT NULL, idade SMALLINT DEFAULT NULL, cod_medico INT DEFAULT NULL, medico_nome VARCHAR(150) DEFAULT NULL, cod_plano INT DEFAULT NULL, plano_descricao VARCHAR(150) DEFAULT NULL, encaixe TINYINT(1) DEFAULT 0 NOT NULL, retorno TINYINT(1) DEFAULT 0 NOT NULL, data_hora_chegada DATETIME DEFAULT NULL, data_hora_liberacao DATETIME DEFAULT NULL, atualizado_em DATETIME NOT NULL, INDEX IDX_3FA50F2CF307B3C5 (procedimento_id), INDEX idx_atendimento_data_realizado (data, realizado), INDEX idx_atendimento_cod_paciente (cod_paciente), UNIQUE INDEX uniq_atendimento_cod_agendamento (cod_agendamento), PRIMARY KEY(id)) $o");

        $this->addSql('ALTER TABLE atendimento_procedimento ADD CONSTRAINT FK_D54E843B3397707A FOREIGN KEY (categoria_id) REFERENCES atendimento_categoria (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE atendimento ADD CONSTRAINT FK_3FA50F2CF307B3C5 FOREIGN KEY (procedimento_id) REFERENCES atendimento_procedimento (id) ON DELETE SET NULL');

        $this->addSql("INSERT INTO atendimento_categoria (slug, nome, cor, ordem, exibir_no_site) VALUES
            ('consulta', 'Consultas', '#2563eb', 1, 1),
            ('ecocardiograma', 'Ecocardiogramas', '#e11d48', 2, 1),
            ('eletrocardiograma', 'Eletrocardiogramas (ECG)', '#f59e0b', 3, 1),
            ('teste-ergometrico', 'Testes ergométricos (esteira)', '#10b981', 4, 1),
            ('holter', 'Holter 24h', '#8b5cf6', 5, 1),
            ('mapa', 'MAPA', '#06b6d4', 6, 1),
            ('outros', 'Outros procedimentos', '#64748b', 7, 1),
            ('retirada-equipamento', 'Retorno de Holter/MAPA (retirada)', '#94a3b8', 8, 0)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE atendimento DROP FOREIGN KEY FK_3FA50F2CF307B3C5');
        $this->addSql('ALTER TABLE atendimento_procedimento DROP FOREIGN KEY FK_D54E843B3397707A');
        $this->addSql('DROP TABLE atendimento');
        $this->addSql('DROP TABLE atendimento_procedimento');
        $this->addSql('DROP TABLE atendimento_categoria');
        $this->addSql('ALTER TABLE atendimento_captura_dia DROP processado_em');
    }
}
