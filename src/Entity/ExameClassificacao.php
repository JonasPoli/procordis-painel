<?php

namespace App\Entity;

use App\Repository\ExameClassificacaoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fato: uma classificação (item da anamnese) marcada num exame/agendamento.
 * Guarda quando foi vista pela primeira e pela última vez na API e quando sumiu dela,
 * formando o histórico que a API não fornece.
 */
#[ORM\Entity(repositoryClass: ExameClassificacaoRepository::class)]
#[ORM\Table(name: 'exame_classificacao')]
#[ORM\UniqueConstraint(name: 'uniq_exame_classificacao', columns: ['cod_agendamento', 'classificacao_id'])]
#[ORM\Index(name: 'idx_exame_class_data', columns: ['data_exame'])]
#[ORM\Index(name: 'idx_exame_class_cod_paciente', columns: ['cod_paciente'])]
#[ORM\Index(name: 'idx_exame_class_removido', columns: ['removido_em'])]
class ExameClassificacao
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Paciente $paciente = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ClassificacaoEstudo $classificacao = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agendamento $agendamento = null;

    #[ORM\Column(length: 50)]
    private string $codAgendamento = '';

    #[ORM\Column]
    private int $codPaciente = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dataExame = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomePacienteApi = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $primeiroVistoEm = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $ultimoVistoEm = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $removidoEm = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPaciente(): ?Paciente
    {
        return $this->paciente;
    }

    public function setPaciente(?Paciente $paciente): static
    {
        $this->paciente = $paciente;

        return $this;
    }

    public function getClassificacao(): ?ClassificacaoEstudo
    {
        return $this->classificacao;
    }

    public function setClassificacao(?ClassificacaoEstudo $classificacao): static
    {
        $this->classificacao = $classificacao;

        return $this;
    }

    public function getAgendamento(): ?Agendamento
    {
        return $this->agendamento;
    }

    public function setAgendamento(?Agendamento $agendamento): static
    {
        $this->agendamento = $agendamento;

        return $this;
    }

    public function getCodAgendamento(): string
    {
        return $this->codAgendamento;
    }

    public function setCodAgendamento(string $codAgendamento): static
    {
        $this->codAgendamento = $codAgendamento;

        return $this;
    }

    public function getCodPaciente(): int
    {
        return $this->codPaciente;
    }

    public function setCodPaciente(int $codPaciente): static
    {
        $this->codPaciente = $codPaciente;

        return $this;
    }

    public function getDataExame(): ?\DateTimeInterface
    {
        return $this->dataExame;
    }

    public function setDataExame(\DateTimeInterface $dataExame): static
    {
        $this->dataExame = $dataExame;

        return $this;
    }

    public function getNomePacienteApi(): ?string
    {
        return $this->nomePacienteApi;
    }

    public function setNomePacienteApi(?string $nomePacienteApi): static
    {
        $this->nomePacienteApi = $nomePacienteApi;

        return $this;
    }

    public function getPrimeiroVistoEm(): ?\DateTimeInterface
    {
        return $this->primeiroVistoEm;
    }

    public function setPrimeiroVistoEm(\DateTimeInterface $primeiroVistoEm): static
    {
        $this->primeiroVistoEm = $primeiroVistoEm;

        return $this;
    }

    public function getUltimoVistoEm(): ?\DateTimeInterface
    {
        return $this->ultimoVistoEm;
    }

    public function setUltimoVistoEm(\DateTimeInterface $ultimoVistoEm): static
    {
        $this->ultimoVistoEm = $ultimoVistoEm;

        return $this;
    }

    public function getRemovidoEm(): ?\DateTimeInterface
    {
        return $this->removidoEm;
    }

    public function setRemovidoEm(?\DateTimeInterface $removidoEm): static
    {
        $this->removidoEm = $removidoEm;

        return $this;
    }
}
