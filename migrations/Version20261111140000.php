<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Thesaurus da anamnese: termos que reúnem itens do catálogo com nomes diferentes (ex.: COVID 2019…2026 = "Covid").
 * Cria o termo "Covid" com a regra "COVID ####" e liga os itens COVID AAAA já existentes.
 * Não altera nem apaga nenhum item do catálogo nem nenhuma marcação de exame.
 */
final class Version20261111140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anamnese: thesaurus (anamnese_termo, classificacao_estudo.termo_id/termo_manual) e termo inicial "Covid"';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE anamnese_termo (id INT AUTO_INCREMENT NOT NULL, nome VARCHAR(120) NOT NULL, categoria VARCHAR(30) DEFAULT 'comorbidade' NOT NULL, padroes LONGTEXT DEFAULT NULL, observacao VARCHAR(255) DEFAULT NULL, criado_em DATETIME NOT NULL, atualizado_em DATETIME NOT NULL, UNIQUE INDEX uniq_anamnese_termo_nome (nome), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE classificacao_estudo ADD termo_id INT DEFAULT NULL, ADD termo_manual TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE classificacao_estudo ADD CONSTRAINT FK_EBA9BB3849BBC700 FOREIGN KEY (termo_id) REFERENCES anamnese_termo (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_EBA9BB3849BBC700 ON classificacao_estudo (termo_id)');

        // Termo inicial pedido pela Procordis em 11/11/2026: todos os anos de Covid são a mesma comorbidade.
        $this->addSql("INSERT INTO anamnese_termo (nome, categoria, padroes, observacao, criado_em, atualizado_em) VALUES ('Covid', 'comorbidade', 'COVID ####', 'Covid registrada por ano (COVID 2019, COVID 2020…). Todos os anos contam como um só item.', NOW(), NOW())");
        // Espelho da regra para os itens que já existem (a regra também vale na leitura, para os anos que vierem).
        $this->addSql("UPDATE classificacao_estudo SET termo_id = (SELECT id FROM anamnese_termo WHERE nome = 'Covid') WHERE termo_manual = 0 AND UPPER(TRIM(nome)) REGEXP '^COVID[ -]?[0-9]{4}$'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE classificacao_estudo DROP FOREIGN KEY FK_EBA9BB3849BBC700');
        $this->addSql('DROP INDEX IDX_EBA9BB3849BBC700 ON classificacao_estudo');
        $this->addSql('ALTER TABLE classificacao_estudo DROP termo_id, DROP termo_manual');
        $this->addSql('DROP TABLE anamnese_termo');
    }
}
