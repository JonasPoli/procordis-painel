<?php

namespace App\Entity;

use App\Repository\AtendimentoCapturaDiaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Resposta bruta de /Medware/Agendamento/Listar para um dia (camada "raw" do painel de atendimentos).
 * Um registro por data agendada; recapturar o dia substitui o conteúdo.
 * "completo" só é verdadeiro quando a resposta veio abaixo do pageSize (ver AtendimentoHistoricoService).
 */
#[ORM\Entity(repositoryClass: AtendimentoCapturaDiaRepository::class)]
#[ORM\Table(name: 'atendimento_captura_dia')]
#[ORM\UniqueConstraint(name: 'uniq_atendimento_captura_data', columns: ['data'])]
#[ORM\Index(name: 'idx_atendimento_captura_completo', columns: ['completo'])]
class AtendimentoCapturaDia
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private \DateTimeInterface $data;

    #[ORM\Column(type: Types::TEXT, length: 4294967295, nullable: true)]
    private ?string $payload = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hashConteudo = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $qtdRegistros = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $pageSize = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $completo = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $httpStatus = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $erro = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $tempoMs = 0;

    #[ORM\Column(options: ['default' => 1])]
    private int $tentativas = 1;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $capturadoEm;

    /** Quando o payload foi consolidado em atendimento; nulo ou anterior a capturadoEm = pendente. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $processadoEm = null;

    public function __construct()
    {
        $this->data = new \DateTime('today');
        $this->capturadoEm = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getData(): \DateTimeInterface
    {
        return $this->data;
    }

    public function getPayload(): ?string
    {
        return $this->payload;
    }

    /** Itens decodificados do payload (lista de agendamentos). */
    public function getItens(): array
    {
        $dados = $this->payload ? json_decode($this->payload, true) : null;

        return is_array($dados) && array_is_list($dados) ? $dados : [];
    }

    public function getHashConteudo(): ?string
    {
        return $this->hashConteudo;
    }

    public function getQtdRegistros(): int
    {
        return $this->qtdRegistros;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function isCompleto(): bool
    {
        return $this->completo;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErro(): ?string
    {
        return $this->erro;
    }

    public function getTempoMs(): int
    {
        return $this->tempoMs;
    }

    public function getTentativas(): int
    {
        return $this->tentativas;
    }

    public function getCapturadoEm(): \DateTimeInterface
    {
        return $this->capturadoEm;
    }

    public function getProcessadoEm(): ?\DateTimeInterface
    {
        return $this->processadoEm;
    }
}
