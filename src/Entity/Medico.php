<?php

namespace App\Entity;

use App\Repository\MedicoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MedicoRepository::class)]
#[ORM\Table(name: 'medico')]
class Medico
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $codigoExterno = null;

    #[ORM\Column(length: 255)]
    private ?string $nome = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $crm = null;

    #[ORM\ManyToOne(inversedBy: 'medicos')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Especialidade $especialidade = null;

    #[ORM\Column(length: 50, options: ['default' => 'disponivel'])]
    private string $statusAtividade = 'disponivel'; // disponivel, em_atendimento, ausente, em_pausa

    /**
     * @var Collection<int, Unidade>
     */
    #[ORM\ManyToMany(targetEntity: Unidade::class, inversedBy: 'medicos')]
    private Collection $unidades;

    public function __construct()
    {
        $this->unidades = new ArrayCollection();
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

    public function getCrm(): ?string
    {
        return $this->crm;
    }

    public function setCrm(?string $crm): static
    {
        $this->crm = $crm;
        return $this;
    }

    public function getEspecialidade(): ?Especialidade
    {
        return $this->especialidade;
    }

    public function setEspecialidade(?Especialidade $especialidade): static
    {
        $this->especialidade = $especialidade;
        return $this;
    }

    public function getStatusAtividade(): string
    {
        return $this->statusAtividade;
    }

    public function setStatusAtividade(string $statusAtividade): static
    {
        $this->statusAtividade = $statusAtividade;
        return $this;
    }

    /**
     * @return Collection<int, Unidade>
     */
    public function getUnidades(): Collection
    {
        return $this->unidades;
    }

    public function addUnidade(Unidade $unidade): static
    {
        if (!$this->unidades->contains($unidade)) {
            $this->unidades->add($unidade);
        }

        return $this;
    }

    public function removeUnidade(Unidade $unidade): static
    {
        $this->unidades->removeElement($unidade);
        return $this;
    }
}
