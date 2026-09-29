<?php

namespace App\Entity;

use App\Repository\PacienteHistoricoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Histórico de alterações dos dados cadastrais do paciente (a API só entrega o estado atual).
 * Uma linha por campo alterado.
 */
#[ORM\Entity(repositoryClass: PacienteHistoricoRepository::class)]
#[ORM\Table(name: 'paciente_historico')]
#[ORM\Index(name: 'idx_paciente_hist_data', columns: ['registrado_em'])]
class PacienteHistorico
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Paciente $paciente = null;

    #[ORM\Column(length: 50)]
    private string $campo = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $valorAnterior = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $valorNovo = null;

    #[ORM\Column(length: 100)]
    private string $origem = '';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $registradoEm;

    public function __construct()
    {
        $this->registradoEm = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPaciente(): ?Paciente
    {
        return $this->paciente;
    }

    public function setPaciente(Paciente $paciente): static
    {
        $this->paciente = $paciente;

        return $this;
    }

    public function getCampo(): string
    {
        return $this->campo;
    }

    public function setCampo(string $campo): static
    {
        $this->campo = $campo;

        return $this;
    }

    public function getValorAnterior(): ?string
    {
        return $this->valorAnterior;
    }

    public function setValorAnterior(?string $valorAnterior): static
    {
        $this->valorAnterior = $valorAnterior;

        return $this;
    }

    public function getValorNovo(): ?string
    {
        return $this->valorNovo;
    }

    public function setValorNovo(?string $valorNovo): static
    {
        $this->valorNovo = $valorNovo;

        return $this;
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }

    public function setOrigem(string $origem): static
    {
        $this->origem = mb_substr($origem, 0, 100);

        return $this;
    }

    public function getRegistradoEm(): \DateTimeInterface
    {
        return $this->registradoEm;
    }

    public function setRegistradoEm(\DateTimeInterface $registradoEm): static
    {
        $this->registradoEm = $registradoEm;

        return $this;
    }
}
