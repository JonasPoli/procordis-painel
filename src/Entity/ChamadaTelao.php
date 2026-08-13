<?php

namespace App\Entity;

use App\Repository\ChamadaTelaoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChamadaTelaoRepository::class)]
#[ORM\Table(name: 'chamada_telao')]
class ChamadaTelao
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?SenhaAtendimento $senha = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Agendamento $agendamento = null;

    #[ORM\Column(length: 255)]
    private ?string $pacienteNomeMascarado = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?SetorSala $setorSala = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Medico $medico = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $guicheOuConsultorio = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dataHoraChamada = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $rechamadaCount = 1;

    #[ORM\Column(length: 50, options: ['default' => 'chamada'])]
    private string $status = 'chamada'; // chamada, atendida, ausente

    public function __construct()
    {
        $this->dataHoraChamada = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSenha(): ?SenhaAtendimento
    {
        return $this->senha;
    }

    public function setSenha(?SenhaAtendimento $senha): static
    {
        $this->senha = $senha;
        return $this;
    }

    public function getAgendamento(): ?Agendamento
    {
        return $this->agendamento;
    }

    public function setAgendamento(?Agendamento $agendamento): static
    {
        $this->agendamento = $agendamento;
        return $this;
    }

    public function getPacienteNomeMascarado(): ?string
    {
        return $this->pacienteNomeMascarado;
    }

    public function setPacienteNomeMascarado(string $pacienteNomeMascarado): static
    {
        $this->pacienteNomeMascarado = $pacienteNomeMascarado;
        return $this;
    }

    public function getSetorSala(): ?SetorSala
    {
        return $this->setorSala;
    }

    public function setSetorSala(?SetorSala $setorSala): static
    {
        $this->setorSala = $setorSala;
        return $this;
    }

    public function getMedico(): ?Medico
    {
        return $this->medico;
    }

    public function setMedico(?Medico $medico): static
    {
        $this->medico = $medico;
        return $this;
    }

    public function getGuicheOuConsultorio(): ?string
    {
        return $this->guicheOuConsultorio;
    }

    public function setGuicheOuConsultorio(?string $guicheOuConsultorio): static
    {
        $this->guicheOuConsultorio = $guicheOuConsultorio;
        return $this;
    }

    public function getDataHoraChamada(): ?\DateTimeInterface
    {
        return $this->dataHoraChamada;
    }

    public function setDataHoraChamada(\DateTimeInterface $dataHoraChamada): static
    {
        $this->dataHoraChamada = $dataHoraChamada;
        return $this;
    }

    public function getRechamadaCount(): int
    {
        return $this->rechamadaCount;
    }

    public function setRechamadaCount(int $rechamadaCount): static
    {
        $this->rechamadaCount = $rechamadaCount;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }
}
