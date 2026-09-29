<?php

namespace App\Entity;

use App\Repository\ClassificacaoEstudoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Catálogo de classificações de estudo (itens da anamnese) vindo de /api/ClassificacaoEstudo.
 * A categoria é nossa (editável no admin) e serve para agrupar os gráficos.
 */
#[ORM\Entity(repositoryClass: ClassificacaoEstudoRepository::class)]
#[ORM\Table(name: 'classificacao_estudo')]
#[ORM\UniqueConstraint(name: 'uniq_classificacao_cod', columns: ['cod_classificacao'])]
class ClassificacaoEstudo
{
    public const CATEGORIAS = [
        'comorbidade' => 'Comorbidade',
        'fator_risco' => 'Fator de risco / Hábito',
        'vacina' => 'Vacina',
        'medicacao' => 'Medicação',
        'sintoma' => 'Sintoma / Queixa',
        'outros' => 'Outros',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $codClassificacao = 0;

    #[ORM\Column(length: 255)]
    private string $nome = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $codigo = null;

    #[ORM\Column(nullable: true)]
    private ?int $tipo = null;

    #[ORM\Column(length: 30, options: ['default' => 'comorbidade'])]
    private string $categoria = 'comorbidade';

    /** Quando true, a categoria foi escolhida manualmente e o sync não a sobrescreve. */
    #[ORM\Column(options: ['default' => false])]
    private bool $categoriaManual = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $ativo = true;

    /** Presente no último sync do catálogo? (false = sumiu da API) */
    #[ORM\Column(options: ['default' => true])]
    private bool $presenteNaApi = true;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dadosBrutos = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $primeiroVistoEm;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $ultimoVistoEm;

    public function __construct()
    {
        $this->primeiroVistoEm = new \DateTime();
        $this->ultimoVistoEm = new \DateTime();
    }

    /**
     * Sugere a categoria a partir do nome (usado só enquanto a categoria não for manual).
     */
    public static function sugerirCategoria(string $nome): string
    {
        $n = mb_strtoupper($nome);
        if (str_contains($n, 'VACINA') || str_contains($n, 'DOSE')) {
            return 'vacina';
        }
        foreach (['TABAG', 'FUMA', 'ETIL', 'ALCOO', 'SEDENT', 'OBES', 'SOBREPESO', 'HEREDIT', 'FAMILIAR', 'ESTRESSE', 'DROGA'] as $k) {
            if (str_contains($n, $k)) {
                return 'fator_risco';
            }
        }
        foreach (['USO DE', 'MEDICA', 'ANTICOAG', 'MARCAPASSO', 'MARCA-PASSO', 'STENT'] as $k) {
            if (str_contains($n, $k)) {
                return 'medicacao';
            }
        }
        foreach (['DOR ', 'DOR TOR', 'PALPITA', 'DISPNEIA', 'CANSA', 'TONTURA', 'SINCOPE', 'SÍNCOPE', 'FADIGA'] as $k) {
            if (str_contains($n, $k) || str_starts_with($n, 'DOR')) {
                return 'sintoma';
            }
        }

        return 'comorbidade';
    }

    /**
     * Para vacinas com "DOSE N" no nome, devolve N.
     */
    public function getNumeroDose(): ?int
    {
        if (preg_match('/DOSE\s*(\d+)/i', $this->nome, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)\s*[ªaº]?\s*DOSE/iu', $this->nome, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public function getCategoriaLabel(): string
    {
        return self::CATEGORIAS[$this->categoria] ?? $this->categoria;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodClassificacao(): int
    {
        return $this->codClassificacao;
    }

    public function setCodClassificacao(int $codClassificacao): static
    {
        $this->codClassificacao = $codClassificacao;

        return $this;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): static
    {
        $this->nome = mb_substr(trim($nome), 0, 255);

        return $this;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function setCodigo(?string $codigo): static
    {
        $this->codigo = $codigo !== null ? mb_substr($codigo, 0, 50) : null;

        return $this;
    }

    public function getTipo(): ?int
    {
        return $this->tipo;
    }

    public function setTipo(?int $tipo): static
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getCategoria(): string
    {
        return $this->categoria;
    }

    public function setCategoria(string $categoria): static
    {
        $this->categoria = array_key_exists($categoria, self::CATEGORIAS) ? $categoria : 'outros';

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

    public function isAtivo(): bool
    {
        return $this->ativo;
    }

    public function setAtivo(bool $ativo): static
    {
        $this->ativo = $ativo;

        return $this;
    }

    public function isPresenteNaApi(): bool
    {
        return $this->presenteNaApi;
    }

    public function setPresenteNaApi(bool $presenteNaApi): static
    {
        $this->presenteNaApi = $presenteNaApi;

        return $this;
    }

    public function getDadosBrutos(): ?array
    {
        return $this->dadosBrutos;
    }

    public function setDadosBrutos(?array $dadosBrutos): static
    {
        $this->dadosBrutos = $dadosBrutos;

        return $this;
    }

    public function getPrimeiroVistoEm(): \DateTimeInterface
    {
        return $this->primeiroVistoEm;
    }

    public function setPrimeiroVistoEm(\DateTimeInterface $primeiroVistoEm): static
    {
        $this->primeiroVistoEm = $primeiroVistoEm;

        return $this;
    }

    public function getUltimoVistoEm(): \DateTimeInterface
    {
        return $this->ultimoVistoEm;
    }

    public function setUltimoVistoEm(\DateTimeInterface $ultimoVistoEm): static
    {
        $this->ultimoVistoEm = $ultimoVistoEm;

        return $this;
    }
}
