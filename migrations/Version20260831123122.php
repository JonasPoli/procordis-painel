<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260831123122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agendamento ADD tipo_atendimento VARCHAR(50) DEFAULT \'sus\' NOT NULL, ADD convenio_nome VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE atendimento_etapa_historico ADD pressao_arterial VARCHAR(20) DEFAULT NULL, ADD frequencia_cardiaca INT DEFAULT NULL, ADD peso DOUBLE PRECISION DEFAULT NULL, ADD queixa_principal LONGTEXT DEFAULT NULL, ADD classificacao_risco VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agendamento DROP tipo_atendimento, DROP convenio_nome');
        $this->addSql('ALTER TABLE atendimento_etapa_historico DROP pressao_arterial, DROP frequencia_cardiaca, DROP peso, DROP queixa_principal, DROP classificacao_risco');
    }
}
