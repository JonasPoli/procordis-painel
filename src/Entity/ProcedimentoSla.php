<?php

namespace App\Entity;

use App\Repository\ProcedimentoSlaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProcedimentoSlaRepository::class)]
#[ORM\Table(name: 'procedimento_sla')]
class ProcedimentoSla
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $codigo = null;

    #[ORM\Column(length: 150)]
    private ?string $nomeProcedimento = null;

    #[ORM\Column(options: ['default' => 59])]
    private int $limiteVerdeMinutos = 59;

    #[ORM\Column(options: ['default' => 119])]
    private int $limiteAmareloMinutos = 119;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descricao = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function setCodigo(string $codigo): static
    {
        $this->codigo = $codigo;
        return $this;
    }

    public function getNomeProcedimento(): ?string
    {
        return $this->nomeProcedimento;
    }

    public function setNomeProcedimento(string $nomeProcedimento): static
    {
        $this->nomeProcedimento = $nomeProcedimento;
        return $this;
    }

    public function getLimiteVerdeMinutos(): int
    {
        return $this->limiteVerdeMinutos;
    }

    public function setLimiteVerdeMinutos(int $limiteVerdeMinutos): static
    {
        $this->limiteVerdeMinutos = $limiteVerdeMinutos;
        return $this;
    }

    public function getLimiteAmareloMinutos(): int
    {
        return $this->limiteAmareloMinutos;
    }

    public function setLimiteAmareloMinutos(int $limiteAmareloMinutos): static
    {
        $this->limiteAmareloMinutos = $limiteAmareloMinutos;
        return $this;
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function setDescricao(?string $descricao): static
    {
        $this->descricao = $descricao;
        return $this;
    }

    /**
     * Retorna a cor de SLA dada a quantidade de minutos decorridos:
     * - Até limiteVerdeMinutos => 'verde'
     * - Entre (limiteVerdeMinutos + 1) e limiteAmareloMinutos => 'amarelo'
     * - Acima de limiteAmareloMinutos => 'vermelho'
     */
    public function calcularCorSla(int $minutosDecorridos): string
    {
        if ($minutosDecorridos <= $this->limiteVerdeMinutos) {
            return 'verde';
        }
        if ($minutosDecorridos <= $this->limiteAmareloMinutos) {
            return 'amarelo';
        }
        return 'vermelho';
    }
}
