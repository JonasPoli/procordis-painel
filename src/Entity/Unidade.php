<?php

namespace App\Entity;

use App\Repository\UnidadeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UnidadeRepository::class)]
#[ORM\Table(name: 'unidade')]
class Unidade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $codigoExterno = null;

    #[ORM\Column(length: 255)]
    private ?string $nome = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $endereco = null;

    #[ORM\Column]
    private bool $ativo = true;

    /**
     * @var Collection<int, Medico>
     */
    #[ORM\ManyToMany(targetEntity: Medico::class, mappedBy: 'unidades')]
    private Collection $medicos;

    public function __construct()
    {
        $this->medicos = new ArrayCollection();
    }

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

    public function getNome(): ?string
    {
        return $this->nome;
    }

    public function setNome(string $nome): static
    {
        $this->nome = $nome;
        return $this;
    }

    public function getEndereco(): ?string
    {
        return $this->endereco;
    }

    public function setEndereco(?string $endereco): static
    {
        $this->endereco = $endereco;
        return $this;
    }

    public function isAtivo(): bool
    {
        return $this->ativo;
    }

    public function setAtivo(bool $ativo): static
    {
        $this->ativo = $ativo;
        return $this;
    }

    /**
     * @return Collection<int, Medico>
     */
    public function getMedicos(): Collection
    {
        return $this->medicos;
    }

    public function addMedico(Medico $medico): static
    {
        if (!$this->medicos->contains($medico)) {
            $this->medicos->add($medico);
            $medico->addUnidade($this);
        }

        return $this;
    }

    public function removeMedico(Medico $medico): static
    {
        if ($this->medicos->removeElement($medico)) {
            $medico->removeUnidade($this);
        }

        return $this;
    }
}
