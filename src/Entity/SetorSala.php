<?php

namespace App\Entity;

use App\Repository\SetorSalaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SetorSalaRepository::class)]
#[ORM\Table(name: 'setor_sala')]
class SetorSala
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $codigoExterno = null;

    #[ORM\Column(length: 100)]
    private ?string $nomeSetor = null;

    #[ORM\Column(length: 100)]
    private ?string $nomeSala = null;

    #[ORM\Column(length: 50)]
    private string $tipo = 'consultorio'; // consultorio, triagem, exame, recepcao

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Unidade $unidade = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodigoExterno(): ?string
    {
        return $this->codigoExterno;
    }

    public function setCodigoExterno(?string $codigoExterno): static
    {
        $this->codigoExterno = $codigoExterno;
        return $this;
    }

    public function getNomeSetor(): ?string
    {
        return $this->nomeSetor;
    }

    public function setNomeSetor(string $nomeSetor): static
    {
        $this->nomeSetor = $nomeSetor;
        return $this;
    }

    public function getNomeSala(): ?string
    {
        return $this->nomeSala;
    }

    public function setNomeSala(string $nomeSala): static
    {
        $this->nomeSala = $nomeSala;
        return $this;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): static
    {
        $this->tipo = $tipo;
        return $this;
    }

    public function getUnidade(): ?Unidade
    {
        return $this->unidade;
    }

    public function setUnidade(?Unidade $unidade): static
    {
        $this->unidade = $unidade;
        return $this;
    }
}
