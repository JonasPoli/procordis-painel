<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Painel de atendimentos: camada bruta da carga histórica de /Medware/Agendamento/Listar (um registro por dia).
 */
final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Painel de atendimentos: tabela atendimento_captura_dia';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE atendimento_captura_dia (id INT AUTO_INCREMENT NOT NULL, data DATE NOT NULL, payload LONGTEXT DEFAULT NULL, hash_conteudo VARCHAR(64) DEFAULT NULL, qtd_registros INT DEFAULT 0 NOT NULL, page_size INT DEFAULT 0 NOT NULL, completo TINYINT(1) DEFAULT 0 NOT NULL, http_status INT DEFAULT 0 NOT NULL, erro LONGTEXT DEFAULT NULL, tempo_ms INT DEFAULT 0 NOT NULL, tentativas INT DEFAULT 1 NOT NULL, capturado_em DATETIME NOT NULL, UNIQUE INDEX uniq_atendimento_captura_data (data), INDEX idx_atendimento_captura_completo (completo), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE atendimento_captura_dia');
    }
}
