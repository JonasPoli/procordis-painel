<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260827124222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE agendamento (id INT AUTO_INCREMENT NOT NULL, paciente_id INT NOT NULL, medico_id INT DEFAULT NULL, especialidade_id INT DEFAULT NULL, unidade_id INT DEFAULT NULL, setor_sala_id INT DEFAULT NULL, codigo_agendamento VARCHAR(50) DEFAULT NULL, data_hora_agendada DATETIME NOT NULL, horario_chegada DATETIME DEFAULT NULL, horario_confirmacao DATETIME DEFAULT NULL, horario_inicio_triagem DATETIME DEFAULT NULL, horario_fim_triagem DATETIME DEFAULT NULL, horario_inicio_consulta DATETIME DEFAULT NULL, horario_fim_consulta DATETIME DEFAULT NULL, horario_saida DATETIME DEFAULT NULL, status VARCHAR(50) DEFAULT \'agendado\' NOT NULL, prioridade TINYINT(1) DEFAULT 0 NOT NULL, encaixe TINYINT(1) DEFAULT 0 NOT NULL, observacoes LONGTEXT DEFAULT NULL, access_number VARCHAR(50) DEFAULT NULL, qtd_exames INT DEFAULT 1 NOT NULL, guiche_atendimento VARCHAR(50) DEFAULT NULL, procedimento_nome VARCHAR(150) DEFAULT NULL, horario_primeira_imagem DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_1F6FB7AA7310DAD4 (paciente_id), INDEX IDX_1F6FB7AAA7FB1C0C (medico_id), INDEX IDX_1F6FB7AA3BA9BFA5 (especialidade_id), INDEX IDX_1F6FB7AAEDF4B99B (unidade_id), INDEX IDX_1F6FB7AADC8F3924 (setor_sala_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE atendimento_etapa_historico (id INT AUTO_INCREMENT NOT NULL, agendamento_id INT NOT NULL, setor_sala_id INT DEFAULT NULL, etapa VARCHAR(50) NOT NULL, data_hora_inicio DATETIME NOT NULL, data_hora_fim DATETIME DEFAULT NULL, duracao_segundos INT DEFAULT NULL, responsavel VARCHAR(150) DEFAULT NULL, INDEX IDX_6393BE8EC427592F (agendamento_id), INDEX IDX_6393BE8EDC8F3924 (setor_sala_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE chamada_telao (id INT AUTO_INCREMENT NOT NULL, senha_id INT DEFAULT NULL, agendamento_id INT DEFAULT NULL, setor_sala_id INT DEFAULT NULL, medico_id INT DEFAULT NULL, paciente_nome_mascarado VARCHAR(255) NOT NULL, guiche_ou_consultorio VARCHAR(50) DEFAULT NULL, data_hora_chamada DATETIME NOT NULL, rechamada_count INT DEFAULT 1 NOT NULL, status VARCHAR(50) DEFAULT \'chamada\' NOT NULL, INDEX IDX_87CBF5C1F55F4BA (senha_id), INDEX IDX_87CBF5CC427592F (agendamento_id), INDEX IDX_87CBF5CDC8F3924 (setor_sala_id), INDEX IDX_87CBF5CA7FB1C0C (medico_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE configuracao_integracao (id INT AUTO_INCREMENT NOT NULL, api_base_url VARCHAR(255) DEFAULT \'https://api.procordis.org.br\' NOT NULL, api_usuario VARCHAR(255) DEFAULT NULL, api_senha VARCHAR(255) DEFAULT NULL, api_token LONGTEXT DEFAULT NULL, api_token_expira_em DATETIME DEFAULT NULL, modo_simulacao TINYINT(1) DEFAULT 1 NOT NULL, frequencia_atualizacao_segundos INT DEFAULT 1 NOT NULL, simulador_velocidade_multiplier INT DEFAULT 1 NOT NULL, ultimo_sync_em DATETIME DEFAULT NULL, status_conexao VARCHAR(50) DEFAULT \'desconectado\' NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE especialidade (id INT AUTO_INCREMENT NOT NULL, codigo_externo VARCHAR(50) DEFAULT NULL, nome VARCHAR(255) NOT NULL, descricao VARCHAR(500) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE log_sync_api (id INT AUTO_INCREMENT NOT NULL, data_hora DATETIME NOT NULL, endpoint VARCHAR(255) NOT NULL, metodo VARCHAR(10) NOT NULL, http_status INT DEFAULT NULL, tempo_resposta_ms INT DEFAULT NULL, mensagem_erro LONGTEXT DEFAULT NULL, registros_processados INT DEFAULT 0 NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE medico (id INT AUTO_INCREMENT NOT NULL, especialidade_id INT DEFAULT NULL, codigo_externo VARCHAR(50) DEFAULT NULL, nome VARCHAR(255) NOT NULL, crm VARCHAR(50) DEFAULT NULL, status_atividade VARCHAR(50) DEFAULT \'disponivel\' NOT NULL, INDEX IDX_34E5914C3BA9BFA5 (especialidade_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE medico_unidade (medico_id INT NOT NULL, unidade_id INT NOT NULL, INDEX IDX_15157EF3A7FB1C0C (medico_id), INDEX IDX_15157EF3EDF4B99B (unidade_id), PRIMARY KEY(medico_id, unidade_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE paciente (id INT AUTO_INCREMENT NOT NULL, codigo_externo VARCHAR(50) DEFAULT NULL, nome_completo VARCHAR(255) NOT NULL, nome_exibicao VARCHAR(255) DEFAULT NULL, cpf VARCHAR(20) DEFAULT NULL, data_nascimento DATE DEFAULT NULL, sexo VARCHAR(20) DEFAULT NULL, celular VARCHAR(30) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE procedimento_sla (id INT AUTO_INCREMENT NOT NULL, codigo VARCHAR(50) NOT NULL, nome_procedimento VARCHAR(150) NOT NULL, limite_verde_minutos INT DEFAULT 59 NOT NULL, limite_amarelo_minutos INT DEFAULT 119 NOT NULL, descricao LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_DA22236120332D99 (codigo), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE senha_atendimento (id INT AUTO_INCREMENT NOT NULL, paciente_id INT DEFAULT NULL, numero_formatado VARCHAR(20) NOT NULL, tipo_senha VARCHAR(20) NOT NULL, data_hora_emissao DATETIME NOT NULL, guiche_ou_estacao VARCHAR(50) DEFAULT NULL, status VARCHAR(50) DEFAULT \'gerada\' NOT NULL, INDEX IDX_64B880347310DAD4 (paciente_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE setor_sala (id INT AUTO_INCREMENT NOT NULL, unidade_id INT DEFAULT NULL, codigo_externo VARCHAR(50) DEFAULT NULL, nome_setor VARCHAR(100) NOT NULL, nome_sala VARCHAR(100) NOT NULL, tipo VARCHAR(50) NOT NULL, INDEX IDX_822F1EB0EDF4B99B (unidade_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE unidade (id INT AUTO_INCREMENT NOT NULL, codigo_externo VARCHAR(50) DEFAULT NULL, nome VARCHAR(255) NOT NULL, endereco VARCHAR(255) DEFAULT NULL, ativo TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE agendamento ADD CONSTRAINT FK_1F6FB7AA7310DAD4 FOREIGN KEY (paciente_id) REFERENCES paciente (id)');
        $this->addSql('ALTER TABLE agendamento ADD CONSTRAINT FK_1F6FB7AAA7FB1C0C FOREIGN KEY (medico_id) REFERENCES medico (id)');
        $this->addSql('ALTER TABLE agendamento ADD CONSTRAINT FK_1F6FB7AA3BA9BFA5 FOREIGN KEY (especialidade_id) REFERENCES especialidade (id)');
        $this->addSql('ALTER TABLE agendamento ADD CONSTRAINT FK_1F6FB7AAEDF4B99B FOREIGN KEY (unidade_id) REFERENCES unidade (id)');
        $this->addSql('ALTER TABLE agendamento ADD CONSTRAINT FK_1F6FB7AADC8F3924 FOREIGN KEY (setor_sala_id) REFERENCES setor_sala (id)');
        $this->addSql('ALTER TABLE atendimento_etapa_historico ADD CONSTRAINT FK_6393BE8EC427592F FOREIGN KEY (agendamento_id) REFERENCES agendamento (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE atendimento_etapa_historico ADD CONSTRAINT FK_6393BE8EDC8F3924 FOREIGN KEY (setor_sala_id) REFERENCES setor_sala (id)');
        $this->addSql('ALTER TABLE chamada_telao ADD CONSTRAINT FK_87CBF5C1F55F4BA FOREIGN KEY (senha_id) REFERENCES senha_atendimento (id)');
        $this->addSql('ALTER TABLE chamada_telao ADD CONSTRAINT FK_87CBF5CC427592F FOREIGN KEY (agendamento_id) REFERENCES agendamento (id)');
        $this->addSql('ALTER TABLE chamada_telao ADD CONSTRAINT FK_87CBF5CDC8F3924 FOREIGN KEY (setor_sala_id) REFERENCES setor_sala (id)');
        $this->addSql('ALTER TABLE chamada_telao ADD CONSTRAINT FK_87CBF5CA7FB1C0C FOREIGN KEY (medico_id) REFERENCES medico (id)');
        $this->addSql('ALTER TABLE medico ADD CONSTRAINT FK_34E5914C3BA9BFA5 FOREIGN KEY (especialidade_id) REFERENCES especialidade (id)');
        $this->addSql('ALTER TABLE medico_unidade ADD CONSTRAINT FK_15157EF3A7FB1C0C FOREIGN KEY (medico_id) REFERENCES medico (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE medico_unidade ADD CONSTRAINT FK_15157EF3EDF4B99B FOREIGN KEY (unidade_id) REFERENCES unidade (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE senha_atendimento ADD CONSTRAINT FK_64B880347310DAD4 FOREIGN KEY (paciente_id) REFERENCES paciente (id)');
        $this->addSql('ALTER TABLE setor_sala ADD CONSTRAINT FK_822F1EB0EDF4B99B FOREIGN KEY (unidade_id) REFERENCES unidade (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agendamento DROP FOREIGN KEY FK_1F6FB7AA7310DAD4');
        $this->addSql('ALTER TABLE agendamento DROP FOREIGN KEY FK_1F6FB7AAA7FB1C0C');
        $this->addSql('ALTER TABLE agendamento DROP FOREIGN KEY FK_1F6FB7AA3BA9BFA5');
        $this->addSql('ALTER TABLE agendamento DROP FOREIGN KEY FK_1F6FB7AAEDF4B99B');
        $this->addSql('ALTER TABLE agendamento DROP FOREIGN KEY FK_1F6FB7AADC8F3924');
        $this->addSql('ALTER TABLE atendimento_etapa_historico DROP FOREIGN KEY FK_6393BE8EC427592F');
        $this->addSql('ALTER TABLE atendimento_etapa_historico DROP FOREIGN KEY FK_6393BE8EDC8F3924');
        $this->addSql('ALTER TABLE chamada_telao DROP FOREIGN KEY FK_87CBF5C1F55F4BA');
        $this->addSql('ALTER TABLE chamada_telao DROP FOREIGN KEY FK_87CBF5CC427592F');
        $this->addSql('ALTER TABLE chamada_telao DROP FOREIGN KEY FK_87CBF5CDC8F3924');
        $this->addSql('ALTER TABLE chamada_telao DROP FOREIGN KEY FK_87CBF5CA7FB1C0C');
        $this->addSql('ALTER TABLE medico DROP FOREIGN KEY FK_34E5914C3BA9BFA5');
        $this->addSql('ALTER TABLE medico_unidade DROP FOREIGN KEY FK_15157EF3A7FB1C0C');
        $this->addSql('ALTER TABLE medico_unidade DROP FOREIGN KEY FK_15157EF3EDF4B99B');
        $this->addSql('ALTER TABLE senha_atendimento DROP FOREIGN KEY FK_64B880347310DAD4');
        $this->addSql('ALTER TABLE setor_sala DROP FOREIGN KEY FK_822F1EB0EDF4B99B');
        $this->addSql('DROP TABLE agendamento');
        $this->addSql('DROP TABLE atendimento_etapa_historico');
        $this->addSql('DROP TABLE chamada_telao');
        $this->addSql('DROP TABLE configuracao_integracao');
        $this->addSql('DROP TABLE especialidade');
        $this->addSql('DROP TABLE log_sync_api');
        $this->addSql('DROP TABLE medico');
        $this->addSql('DROP TABLE medico_unidade');
        $this->addSql('DROP TABLE paciente');
        $this->addSql('DROP TABLE procedimento_sla');
        $this->addSql('DROP TABLE senha_atendimento');
        $this->addSql('DROP TABLE setor_sala');
        $this->addSql('DROP TABLE unidade');
    }
}
