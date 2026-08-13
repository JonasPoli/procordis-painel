<?php

namespace App\Entity;

use App\Repository\ConfiguracaoIntegracaoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConfiguracaoIntegracaoRepository::class)]
#[ORM\Table(name: 'configuracao_integracao')]
class ConfiguracaoIntegracao
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, options: ['default' => 'https://api.procordis.org.br'])]
    private string $apiBaseUrl = 'https://api.procordis.org.br';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiUsuario = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiSenha = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $apiToken = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $apiTokenExpiraEm = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $modoSimulacao = true;

    #[ORM\Column(options: ['default' => 1])]
    private int $frequenciaAtualizacaoSegundos = 1;

    #[ORM\Column(options: ['default' => 1])]
    private int $simuladorVelocidadeMultiplier = 1;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $ultimoSyncEm = null;

    #[ORM\Column(length: 50, options: ['default' => 'desconectado'])]
    private string $statusConexao = 'desconectado';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }

    public function setApiBaseUrl(string $apiBaseUrl): static
    {
        $this->apiBaseUrl = $apiBaseUrl;
        return $this;
    }

    public function getApiUsuario(): ?string
    {
        return $this->apiUsuario;
    }

    public function setApiUsuario(?string $apiUsuario): static
    {
        $this->apiUsuario = $apiUsuario;
        return $this;
    }

    public function getApiSenha(): ?string
    {
        return $this->apiSenha;
    }

    public function setApiSenha(?string $apiSenha): static
    {
        $this->apiSenha = $apiSenha;
        return $this;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function setApiToken(?string $apiToken): static
    {
        $this->apiToken = $apiToken;
        return $this;
    }

    public function getApiTokenExpiraEm(): ?\DateTimeInterface
    {
        return $this->apiTokenExpiraEm;
    }

    public function setApiTokenExpiraEm(?\DateTimeInterface $apiTokenExpiraEm): static
    {
        $this->apiTokenExpiraEm = $apiTokenExpiraEm;
        return $this;
    }

    public function isModoSimulacao(): bool
    {
        return $this->modoSimulacao;
    }

    public function setModoSimulacao(bool $modoSimulacao): static
    {
        $this->modoSimulacao = $modoSimulacao;
        return $this;
    }

    public function getFrequenciaAtualizacaoSegundos(): int
    {
        return $this->frequenciaAtualizacaoSegundos;
    }

    public function setFrequenciaAtualizacaoSegundos(int $frequenciaAtualizacaoSegundos): static
    {
        $this->frequenciaAtualizacaoSegundos = $frequenciaAtualizacaoSegundos;
        return $this;
    }

    public function getSimuladorVelocidadeMultiplier(): int
    {
        return $this->simuladorVelocidadeMultiplier;
    }

    public function setSimuladorVelocidadeMultiplier(int $simuladorVelocidadeMultiplier): static
    {
        $this->simuladorVelocidadeMultiplier = $simuladorVelocidadeMultiplier;
        return $this;
    }

    public function getUltimoSyncEm(): ?\DateTimeInterface
    {
        return $this->ultimoSyncEm;
    }

    public function setUltimoSyncEm(?\DateTimeInterface $ultimoSyncEm): static
    {
        $this->ultimoSyncEm = $ultimoSyncEm;
        return $this;
    }

    public function getStatusConexao(): string
    {
        return $this->statusConexao;
    }

    public function setStatusConexao(string $statusConexao): static
    {
        $this->statusConexao = $statusConexao;
        return $this;
    }
}
