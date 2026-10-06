<?php

namespace App\Entity;

use App\Repository\AtendimentoCategoriaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tipo de atendimento exibido nos gráficos (Consulta, ECG, Ecocardiograma...).
 * Cada AtendimentoProcedimento aponta para uma categoria; só categorias com exibirNoSite entram na API pública.
 */
#[ORM\Entity(repositoryClass: AtendimentoCategoriaRepository::class)]
#[ORM\Table(name: 'atendimento_categoria')]
#[ORM\UniqueConstraint(name: 'uniq_atendimento_categoria_slug', columns: ['slug'])]
class AtendimentoCategoria
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $slug = '';

    #[ORM\Column(length: 100)]
    private string $nome = '';

    #[ORM\Column(length: 7, options: ['default' => '#64748b'])]
    private string $cor = '#64748b';

    #[ORM\Column(options: ['default' => 0])]
    private int $ordem = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $exibirNoSite = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): static
    {
        $this->nome = $nome;

        return $this;
    }

    public function getCor(): string
    {
        return $this->cor;
    }

    public function setCor(string $cor): static
    {
        $this->cor = $cor;

        return $this;
    }

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function setOrdem(int $ordem): static
    {
        $this->ordem = $ordem;

        return $this;
    }

    public function isExibirNoSite(): bool
    {
        return $this->exibirNoSite;
    }

    public function setExibirNoSite(bool $exibirNoSite): static
    {
        $this->exibirNoSite = $exibirNoSite;

        return $this;
    }
}
