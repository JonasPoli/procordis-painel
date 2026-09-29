<?php

namespace App\Entity;

use App\Repository\PacienteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PacienteRepository::class)]
#[ORM\Table(name: 'paciente')]
#[ORM\UniqueConstraint(name: 'uniq_paciente_cod_paciente', columns: ['cod_paciente'])]
class Paciente
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $codigoExterno = null;

    /** Código do paciente na Medware/Procordis (chave real de identificação). */
    #[ORM\Column(nullable: true)]
    private ?int $codPaciente = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $primeiroVistoEm = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $atualizadoEm = null;

    #[ORM\Column(length: 255)]
    private ?string $nomeCompleto = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeExibicao = null; // Formatado LGPD Ex: João S.

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $cpf = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dataNascimento = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $sexo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $celular = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodigoExterno(): ?string
    {
        return $this->codigoExterno;
    }

    public function setCodigoExterno(?string $codigoExterno): static
    {
        $this->codigoExterno = $codigoExterno;
        return $this;
    }

    public function getCodPaciente(): ?int
    {
        return $this->codPaciente;
    }

    public function setCodPaciente(?int $codPaciente): static
    {
        $this->codPaciente = $codPaciente;
        return $this;
    }

    public function getPrimeiroVistoEm(): ?\DateTimeInterface
    {
        return $this->primeiroVistoEm;
    }

    public function setPrimeiroVistoEm(?\DateTimeInterface $primeiroVistoEm): static
    {
        $this->primeiroVistoEm = $primeiroVistoEm;
        return $this;
    }

    public function getAtualizadoEm(): ?\DateTimeInterface
    {
        return $this->atualizadoEm;
    }

    public function setAtualizadoEm(?\DateTimeInterface $atualizadoEm): static
    {
        $this->atualizadoEm = $atualizadoEm;
        return $this;
    }

    /**
     * Idade em anos completos numa data de referência.
     */
    public function getIdadeEm(?\DateTimeInterface $referencia = null): ?int
    {
        if (!$this->dataNascimento) {
            return null;
        }
        return $this->dataNascimento->diff($referencia ?? new \DateTime())->y;
    }

    public function getNomeCompleto(): ?string
    {
        return $this->nomeCompleto;
    }

    public function setNomeCompleto(string $nomeCompleto): static
    {
        $this->nomeCompleto = $nomeCompleto;
        if (!$this->nomeExibicao) {
            $this->nomeExibicao = $this->generarNomeExibicaoLgpd($nomeCompleto);
        }
        return $this;
    }

    public function getNomeExibicao(): ?string
    {
        if (!$this->nomeExibicao && $this->nomeCompleto) {
            return $this->generarNomeExibicaoLgpd($this->nomeCompleto);
        }
        return $this->nomeExibicao;
    }

    public function setNomeExibicao(?string $nomeExibicao): static
    {
        $this->nomeExibicao = $nomeExibicao;
        return $this;
    }

    public function getCpf(): ?string
    {
        return $this->cpf;
    }

    public function setCpf(?string $cpf): static
    {
        $this->cpf = $cpf;
        return $this;
    }

    public function getDataNascimento(): ?\DateTimeInterface
    {
        return $this->dataNascimento;
    }

    public function setDataNascimento(?\DateTimeInterface $dataNascimento): static
    {
        $this->dataNascimento = $dataNascimento;
        return $this;
    }

    public function getSexo(): ?string
    {
        return $this->sexo;
    }

    public function setSexo(?string $sexo): static
    {
        $this->sexo = $sexo;
        return $this;
    }

    public function getCelular(): ?string
    {
        return $this->celular;
    }

    public function setCelular(?string $celular): static
    {
        $this->celular = $celular;
        return $this;
    }

    private function generarNomeExibicaoLgpd(string $nome): string
    {
        $parts = array_filter(explode(' ', trim($nome)));
        if (count($parts) === 0) {
            return 'Paciente';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        $primeiro = array_shift($parts);
        $ultimo = array_pop($parts);
        return sprintf('%s %s.', $primeiro, mb_substr($ultimo, 0, 1));
    }
}
