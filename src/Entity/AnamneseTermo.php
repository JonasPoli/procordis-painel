<?php

namespace App\Entity;

use App\Repository\AnamneseTermoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Termo do thesaurus da anamnese: um conceito (ex.: "Covid") que reúne vários itens do catálogo
 * com nomes diferentes (COVID 2019, COVID 2020…). Nas estatísticas, os itens do termo contam como um só.
 *
 * Um item entra no termo pelas regras de nome (uma por linha; `#` = um dígito, `*` = qualquer trecho)
 * ou por escolha manual no admin, que prevalece sobre as regras (ver ClassificacaoEstudo::$termoManual).
 */
#[ORM\Entity(repositoryClass: AnamneseTermoRepository::class)]
#[ORM\Table(name: 'anamnese_termo')]
#[ORM\UniqueConstraint(name: 'uniq_anamnese_termo_nome', columns: ['nome'])]
class AnamneseTermo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $nome = '';

    #[ORM\Column(length: 30, options: ['default' => 'comorbidade'])]
    private string $categoria = 'comorbidade';

    /** Regras de nome, uma por linha. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $padroes = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $observacao = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $criadoEm;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $atualizadoEm;

    public function __construct()
    {
        $this->criadoEm = new \DateTime();
        $this->atualizadoEm = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): static
    {
        $this->nome = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $nome)), 0, 120);

        return $this;
    }

    public function getCategoria(): string
    {
        return $this->categoria;
    }

    public function setCategoria(string $categoria): static
    {
        $this->categoria = array_key_exists($categoria, ClassificacaoEstudo::CATEGORIAS) ? $categoria : 'outros';

        return $this;
    }

    public function getCategoriaLabel(): string
    {
        return ClassificacaoEstudo::CATEGORIAS[$this->categoria] ?? $this->categoria;
    }

    public function getPadroes(): ?string
    {
        return $this->padroes;
    }

    /** @return list<string> regras não vazias, sem repetição */
    public function getListaPadroes(): array
    {
        $linhas = array_map(fn (string $l) => mb_substr(trim($l), 0, 120), preg_split('/\R/u', (string) $this->padroes) ?: []);

        return array_values(array_unique(array_filter($linhas, fn (string $l) => $l !== '')));
    }

    /** Uma regra por linha; linhas vazias e repetidas são descartadas. */
    public function setPadroes(?string $padroes): static
    {
        $this->padroes = $padroes;
        $this->padroes = implode("\n", $this->getListaPadroes()) ?: null;

        return $this;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function setObservacao(?string $observacao): static
    {
        $observacao = $observacao !== null ? trim($observacao) : null;
        $this->observacao = $observacao !== null && $observacao !== '' ? mb_substr($observacao, 0, 255) : null;

        return $this;
    }

    public function getCriadoEm(): \DateTimeInterface
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): \DateTimeInterface
    {
        return $this->atualizadoEm;
    }

    public function setAtualizadoEm(\DateTimeInterface $atualizadoEm): static
    {
        $this->atualizadoEm = $atualizadoEm;

        return $this;
    }
}
