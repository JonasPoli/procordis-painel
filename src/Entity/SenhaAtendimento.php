<?php

namespace App\Entity;

use App\Repository\SenhaAtendimentoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SenhaAtendimentoRepository::class)]
#[ORM\Table(name: 'senha_atendimento')]
class SenhaAtendimento
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $numeroFormatado = null; // Ex: N001, P002

    #[ORM\Column(length: 20)]
    private string $tipoSenha = 'normal'; // normal, preferencial, urgencia

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dataHoraEmissao = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $guicheOuEstacao = null;

    #[ORM\Column(length: 50, options: ['default' => 'gerada'])]
    private string $status = 'gerada'; // gerada, chamada, em_atendimento, concluida, cancelada

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Paciente $paciente = null;

    public function __construct()
    {
        $this->dataHoraEmissao = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumeroFormatado(): ?string
    {
        return $this->numeroFormatado;
    }

    public function setNumeroFormatado(string $numeroFormatado): static
    {
        $this->numeroFormatado = $numeroFormatado;
        return $this;
    }

    public function getTipoSenha(): string
    {
        return $this->tipoSenha;
    }

    public function setTipoSenha(string $tipoSenha): static
    {
        $this->tipoSenha = $tipoSenha;
        return $this;
    }

    public function getDataHoraEmissao(): ?\DateTimeInterface
    {
        return $this->dataHoraEmissao;
    }

    public function setDataHoraEmissao(\DateTimeInterface $dataHoraEmissao): static
    {
        $this->dataHoraEmissao = $dataHoraEmissao;
        return $this;
    }

    public function getGuicheOuEstacao(): ?string
    {
        return $this->guicheOuEstacao;
    }

    public function setGuicheOuEstacao(?string $guicheOuEstacao): static
    {
        $this->guicheOuEstacao = $guicheOuEstacao;
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

    public function getPaciente(): ?Paciente
    {
        return $this->paciente;
    }

    public function setPaciente(?Paciente $paciente): static
    {
        $this->paciente = $paciente;
        return $this;
    }
}
