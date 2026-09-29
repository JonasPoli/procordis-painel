<?php

namespace App\Entity;

use App\Repository\ApiCapturaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Camada bruta: guarda o JSON integral de cada resposta da API.
 * Respostas idênticas (mesmo endpoint + parâmetros + hash) não são regravadas; só a data da última verificação muda.
 * Permite reprocessar tudo no futuro, inclusive campos novos que a API venha a incluir.
 */
#[ORM\Entity(repositoryClass: ApiCapturaRepository::class)]
#[ORM\Table(name: 'api_captura')]
#[ORM\Index(name: 'idx_api_captura_chave', columns: ['chave_consulta', 'hash_conteudo'])]
#[ORM\Index(name: 'idx_api_captura_data', columns: ['capturado_em'])]
class ApiCaptura
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $endpoint = '';

    #[ORM\Column(type: Types::JSON)]
    private array $parametros = [];

    /** sha1(endpoint + parâmetros): identifica "a mesma consulta" entre execuções. */
    #[ORM\Column(length: 40)]
    private string $chaveConsulta = '';

    #[ORM\Column(length: 64)]
    private string $hashConteudo = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $payload = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $qtdRegistros = 0;

    #[ORM\Column(options: ['default' => 200])]
    private int $httpStatus = 200;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $capturadoEm;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $ultimaVerificacaoEm;

    #[ORM\Column(options: ['default' => 1])]
    private int $vezesVerificado = 1;

    public function __construct()
    {
        $this->capturadoEm = new \DateTime();
        $this->ultimaVerificacaoEm = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = mb_substr($endpoint, 0, 255);

        return $this;
    }

    public function getParametros(): array
    {
        return $this->parametros;
    }

    public function setParametros(array $parametros): static
    {
        $this->parametros = $parametros;

        return $this;
    }

    public function getChaveConsulta(): string
    {
        return $this->chaveConsulta;
    }

    public function setChaveConsulta(string $chaveConsulta): static
    {
        $this->chaveConsulta = $chaveConsulta;

        return $this;
    }

    public function getHashConteudo(): string
    {
        return $this->hashConteudo;
    }

    public function setHashConteudo(string $hashConteudo): static
    {
        $this->hashConteudo = $hashConteudo;

        return $this;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function setPayload(string $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getQtdRegistros(): int
    {
        return $this->qtdRegistros;
    }

    public function setQtdRegistros(int $qtdRegistros): static
    {
        $this->qtdRegistros = $qtdRegistros;

        return $this;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function setHttpStatus(int $httpStatus): static
    {
        $this->httpStatus = $httpStatus;

        return $this;
    }

    public function getCapturadoEm(): \DateTimeInterface
    {
        return $this->capturadoEm;
    }

    public function setCapturadoEm(\DateTimeInterface $capturadoEm): static
    {
        $this->capturadoEm = $capturadoEm;

        return $this;
    }

    public function getUltimaVerificacaoEm(): \DateTimeInterface
    {
        return $this->ultimaVerificacaoEm;
    }

    public function setUltimaVerificacaoEm(\DateTimeInterface $ultimaVerificacaoEm): static
    {
        $this->ultimaVerificacaoEm = $ultimaVerificacaoEm;

        return $this;
    }

    public function getVezesVerificado(): int
    {
        return $this->vezesVerificado;
    }

    public function setVezesVerificado(int $vezesVerificado): static
    {
        $this->vezesVerificado = $vezesVerificado;

        return $this;
    }
}
