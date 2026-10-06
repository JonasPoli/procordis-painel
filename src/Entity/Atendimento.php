<?php

namespace App\Entity;

use App\Repository\AtendimentoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Agendamento consolidado para os indicadores do painel de atendimentos (um registro por codAgendamento).
 * Gerado a partir de atendimento_captura_dia por AtendimentoConsolidacaoService.
 *
 * Guarda só códigos e atributos agregáveis (sexo, idade): nome, CPF e contatos do paciente
 * ficam apenas na camada bruta.
 */
#[ORM\Entity(repositoryClass: AtendimentoRepository::class)]
#[ORM\Table(name: 'atendimento')]
#[ORM\UniqueConstraint(name: 'uniq_atendimento_cod_agendamento', columns: ['cod_agendamento'])]
#[ORM\Index(name: 'idx_atendimento_data_realizado', columns: ['data', 'realizado'])]
#[ORM\Index(name: 'idx_atendimento_cod_paciente', columns: ['cod_paciente'])]
class Atendimento
{
    /** Estágios (codStatusAgendamento) que contam como atendimento realizado: 4 Atendido, 5 Liberado. */
    public const ESTAGIOS_REALIZADOS = [4, 5];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $codAgendamento = 0;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private \DateTimeInterface $data;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $dataHoraAgendada;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $codStatusAgendamento = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $cancelado = false;

    /** Ativo e em ESTAGIOS_REALIZADOS — é o que os gráficos contam. */
    #[ORM\Column(options: ['default' => false])]
    private bool $realizado = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AtendimentoProcedimento $procedimento = null;

    #[ORM\Column(nullable: true)]
    private ?int $codPaciente = null;

    #[ORM\Column(length: 1, nullable: true)]
    private ?string $sexo = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $idade = null;

    #[ORM\Column(nullable: true)]
    private ?int $codMedico = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $medicoNome = null;

    #[ORM\Column(nullable: true)]
    private ?int $codPlano = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $planoDescricao = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $encaixe = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $retorno = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dataHoraChegada = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dataHoraLiberacao = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $atualizadoEm;

    public function __construct()
    {
        $this->data = new \DateTime('today');
        $this->dataHoraAgendada = new \DateTime();
        $this->atualizadoEm = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodAgendamento(): int
    {
        return $this->codAgendamento;
    }

    public function getData(): \DateTimeInterface
    {
        return $this->data;
    }

    public function getDataHoraAgendada(): \DateTimeInterface
    {
        return $this->dataHoraAgendada;
    }

    public function getCodStatusAgendamento(): int
    {
        return $this->codStatusAgendamento;
    }

    public function isCancelado(): bool
    {
        return $this->cancelado;
    }

    public function isRealizado(): bool
    {
        return $this->realizado;
    }

    public function getProcedimento(): ?AtendimentoProcedimento
    {
        return $this->procedimento;
    }

    public function getCodPaciente(): ?int
    {
        return $this->codPaciente;
    }

    public function getSexo(): ?string
    {
        return $this->sexo;
    }

    public function getIdade(): ?int
    {
        return $this->idade;
    }

    public function getCodMedico(): ?int
    {
        return $this->codMedico;
    }

    public function getMedicoNome(): ?string
    {
        return $this->medicoNome;
    }

    public function getCodPlano(): ?int
    {
        return $this->codPlano;
    }

    public function getPlanoDescricao(): ?string
    {
        return $this->planoDescricao;
    }

    public function isEncaixe(): bool
    {
        return $this->encaixe;
    }

    public function isRetorno(): bool
    {
        return $this->retorno;
    }

    public function getDataHoraChegada(): ?\DateTimeInterface
    {
        return $this->dataHoraChegada;
    }

    public function getDataHoraLiberacao(): ?\DateTimeInterface
    {
        return $this->dataHoraLiberacao;
    }

    public function getAtualizadoEm(): \DateTimeInterface
    {
        return $this->atualizadoEm;
    }
}
