<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Painel de atendimentos: troca as cores padrão das categorias pela paleta validada (daltonismo e contraste).
 * Só altera categorias que ainda estão com a cor padrão anterior, preservando escolhas feitas no admin.
 */
final class Version20261006210000 extends AbstractMigration
{
    private const CORES = [
        'consulta' => ['#2563eb', '#2a78d6'],
        'ecocardiograma' => ['#e11d48', '#eb6834'],
        'eletrocardiograma' => ['#f59e0b', '#1baf7a'],
        'teste-ergometrico' => ['#10b981', '#eda100'],
        'holter' => ['#8b5cf6', '#e87ba4'],
        'mapa' => ['#06b6d4', '#008300'],
        'outros' => ['#64748b', '#4a3aa7'],
        'retirada-equipamento' => ['#94a3b8', '#e34948'],
    ];

    public function getDescription(): string
    {
        return 'Painel de atendimentos: paleta validada nas cores padrão das categorias';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CORES as $slug => [$antiga, $nova]) {
            $this->addSql('UPDATE atendimento_categoria SET cor = ? WHERE slug = ? AND cor = ?', [$nova, $slug, $antiga]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::CORES as $slug => [$antiga, $nova]) {
            $this->addSql('UPDATE atendimento_categoria SET cor = ? WHERE slug = ? AND cor = ?', [$antiga, $slug, $nova]);
        }
    }
}
