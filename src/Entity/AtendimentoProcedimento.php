<?php

namespace App\Entity;

use App\Repository\AtendimentoProcedimentoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Procedimento Medware visto nos agendamentos (procedimentoPlanoOperadora.codProcedimento).
 * A categoria é atribuída automaticamente pela descrição; se alterada no admin vira manual e a consolidação não mexe mais.
 */
#[ORM\Entity(repositoryClass: AtendimentoProcedimentoRepository::class)]
#[ORM\Table(name: 'atendimento_procedimento')]
#[ORM\UniqueConstraint(name: 'uniq_atendimento_procedimento_cod', columns: ['cod_procedimento'])]
class AtendimentoProcedimento
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $codProcedimento = 0;

    #[ORM\Column(length: 255)]
    private string $descricao = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $consulta = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AtendimentoCategoria $categoria = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $categoriaManual = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $primeiroVistoEm;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $ultimoVistoEm;

    public function __construct()
    {
        $this->primeiroVistoEm = new \DateTime();
        $this->ultimoVistoEm = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodProcedimento(): int
    {
        return $this->codProcedimento;
    }

    public function setCodProcedimento(int $codProcedimento): static
    {
        $this->codProcedimento = $codProcedimento;

        return $this;
    }

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): static
    {
        $this->descricao = $descricao;

        return $this;
    }

    public function isConsulta(): bool
    {
        return $this->consulta;
    }

    public function getCategoria(): ?AtendimentoCategoria
    {
        return $this->categoria;
    }

    public function setCategoria(?AtendimentoCategoria $categoria): static
    {
        $this->categoria = $categoria;

        return $this;
    }

    public function isCategoriaManual(): bool
    {
        return $this->categoriaManual;
    }

    public function setCategoriaManual(bool $categoriaManual): static
    {
        $this->categoriaManual = $categoriaManual;

        return $this;
    }

    public function getPrimeiroVistoEm(): \DateTimeInterface
    {
        return $this->primeiroVistoEm;
    }

    public function getUltimoVistoEm(): \DateTimeInterface
    {
        return $this->ultimoVistoEm;
    }
}
