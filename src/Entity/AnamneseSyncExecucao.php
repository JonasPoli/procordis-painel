<?php

namespace App\Entity;

use App\Repository\AnamneseSyncExecucaoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registro de cada execução da sincronização da anamnese (incremental ou completa).
 */
#[ORM\Entity(repositoryClass: AnamneseSyncExecucaoRepository::class)]
#[ORM\Table(name: 'anamnese_sync_execucao')]
#[ORM\Index(name: 'idx_anamnese_sync_inicio', columns: ['iniciado_em'])]
class AnamneseSyncExecucao
{
    public const STATUS_EM_ANDAMENTO = 'em_andamento';
    public const STATUS_SUCESSO = 'sucesso';
    public const STATUS_PARCIAL = 'parcial';
    public const STATUS_ERRO = 'erro';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** incremental | completo | simulacao */
    #[ORM\Column(length: 20)]
    private string $modo = 'incremental';

    /** cron | manual | cli */
    #[ORM\Column(length: 20, options: ['default' => 'cli'])]
    private string $origem = 'cli';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_EM_ANDAMENTO;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $iniciadoEm;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $finalizadoEm = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $resumo = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $erros = null;

    public function __construct()
    {
        $this->iniciadoEm = new \DateTime();
    }

    public function finalizar(string $status, array $resumo, array $erros = []): void
    {
        $this->status = $status;
        $this->resumo = $resumo;
        $this->erros = $erros ? implode("\n", array_slice($erros, 0, 200)) : null;
        $this->finalizadoEm = new \DateTime();
    }

    public function getDuracaoSegundos(): ?int
    {
        if (!$this->finalizadoEm) {
            return null;
        }

        return $this->finalizadoEm->getTimestamp() - $this->iniciadoEm->getTimestamp();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModo(): string
    {
        return $this->modo;
    }

    public function setModo(string $modo): static
    {
        $this->modo = $modo;

        return $this;
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }

    public function setOrigem(string $origem): static
    {
        $this->origem = $origem;

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

    public function getIniciadoEm(): \DateTimeInterface
    {
        return $this->iniciadoEm;
    }

    public function getFinalizadoEm(): ?\DateTimeInterface
    {
        return $this->finalizadoEm;
    }

    public function getResumo(): ?array
    {
        return $this->resumo;
    }

    public function setResumo(?array $resumo): static
    {
        $this->resumo = $resumo;

        return $this;
    }

    public function getErros(): ?string
    {
        return $this->erros;
    }
}
