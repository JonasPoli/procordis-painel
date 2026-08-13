<?php

namespace App\Entity;

use App\Repository\LogSyncApiRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LogSyncApiRepository::class)]
#[ORM\Table(name: 'log_sync_api')]
class LogSyncApi
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dataHora = null;

    #[ORM\Column(length: 255)]
    private ?string $endpoint = null;

    #[ORM\Column(length: 10)]
    private string $metodo = 'GET';

    #[ORM\Column(nullable: true)]
    private ?int $httpStatus = null;

    #[ORM\Column(nullable: true)]
    private ?int $tempoRespostaMs = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $mensagemErro = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $registrosProcessados = 0;

    public function __construct()
    {
        $this->dataHora = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDataHora(): ?\DateTimeInterface
    {
        return $this->dataHora;
    }

    public function setDataHora(\DateTimeInterface $dataHora): static
    {
        $this->dataHora = $dataHora;
        return $this;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getMetodo(): string
    {
        return $this->metodo;
    }

    public function setMetodo(string $metodo): static
    {
        $this->metodo = $metodo;
        return $this;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function setHttpStatus(?int $httpStatus): static
    {
        $this->httpStatus = $httpStatus;
        return $this;
    }

    public function getTempoRespostaMs(): ?int
    {
        return $this->tempoRespostaMs;
    }

    public function setTempoRespostaMs(?int $tempoRespostaMs): static
    {
        $this->tempoRespostaMs = $tempoRespostaMs;
        return $this;
    }

    public function getMensagemErro(): ?string
    {
        return $this->mensagemErro;
    }

    public function setMensagemErro(?string $mensagemErro): static
    {
        $this->mensagemErro = $mensagemErro;
        return $this;
    }

    public function getRegistrosProcessados(): int
    {
        return $this->registrosProcessados;
    }

    public function setRegistrosProcessados(int $registrosProcessados): static
    {
        $this->registrosProcessados = $registrosProcessados;
        return $this;
    }
}
