<?php

namespace App\Entity;

use App\Repository\AgendamentoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgendamentoRepository::class)]
#[ORM\Table(name: 'agendamento')]
class Agendamento
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $codigoAgendamento = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Paciente $paciente = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Medico $medico = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Especialidade $especialidade = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Unidade $unidade = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?SetorSala $setorSala = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dataHoraAgendada = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $horarioChegada = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $horarioConfirmacao = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $horarioInicioTriagem = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $horarioFimTriagem = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $horarioInicioConsulta = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $horarioFimConsulta = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $horarioSaida = null;

    /*
     Status possíveis:
     agendado, aguardando_triagem, em_triagem, aguardando_medico, em_consulta, em_exame, finalizado, cancelado, ausente, desistencia
    */
    #[ORM\Column(length: 50, options: ['default' => 'agendado'])]
    private string $status = 'agendado';

    #[ORM\Column(options: ['default' => false])]
    private bool $prioridade = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $encaixe = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observacoes = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $accessNumber = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $qtdExames = 1;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $guicheAtendimento = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $procedimentoNome = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $horarioPrimeiraImagem = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, AtendimentoEtapaHistorico>
     */
    #[ORM\OneToMany(targetEntity: AtendimentoEtapaHistorico::class, mappedBy: 'agendamento', cascade: ['persist', 'remove'])]
    private Collection $historicoEtapas;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->historicoEtapas = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodigoAgendamento(): ?string
    {
        return $this->codigoAgendamento;
    }

    public function setCodigoAgendamento(?string $codigoAgendamento): static
    {
        $this->codigoAgendamento = $codigoAgendamento;
        return $this;
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

    public function getMedico(): ?Medico
    {
        return $this->medico;
    }

    public function setMedico(?Medico $medico): static
    {
        $this->medico = $medico;
        return $this;
    }

    public function getEspecialidade(): ?Especialidade
    {
        return $this->especialidade;
    }

    public function setEspecialidade(?Especialidade $especialidade): static
    {
        $this->especialidade = $especialidade;
        return $this;
    }

    public function getUnidade(): ?Unidade
    {
        return $this->unidade;
    }

    public function setUnidade(?Unidade $unidade): static
    {
        $this->unidade = $unidade;
        return $this;
    }

    public function getSetorSala(): ?SetorSala
    {
        return $this->setorSala;
    }

    public function setSetorSala(?SetorSala $setorSala): static
    {
        $this->setorSala = $setorSala;
        return $this;
    }

    public function getDataHoraAgendada(): ?\DateTimeInterface
    {
        return $this->dataHoraAgendada;
    }

    public function setDataHoraAgendada(\DateTimeInterface $dataHoraAgendada): static
    {
        $this->dataHoraAgendada = $dataHoraAgendada;
        return $this;
    }

    public function getHorarioChegada(): ?\DateTimeInterface
    {
        return $this->horarioChegada;
    }

    public function setHorarioChegada(?\DateTimeInterface $horarioChegada): static
    {
        $this->horarioChegada = $horarioChegada;
        return $this;
    }

    public function getHorarioConfirmacao(): ?\DateTimeInterface
    {
        return $this->horarioConfirmacao;
    }

    public function setHorarioConfirmacao(?\DateTimeInterface $horarioConfirmacao): static
    {
        $this->horarioConfirmacao = $horarioConfirmacao;
        return $this;
    }

    public function getHorarioInicioTriagem(): ?\DateTimeInterface
    {
        return $this->horarioInicioTriagem;
    }

    public function setHorarioInicioTriagem(?\DateTimeInterface $horarioInicioTriagem): static
    {
        $this->horarioInicioTriagem = $horarioInicioTriagem;
        return $this;
    }

    public function getHorarioFimTriagem(): ?\DateTimeInterface
    {
        return $this->horarioFimTriagem;
    }

    public function setHorarioFimTriagem(?\DateTimeInterface $horarioFimTriagem): static
    {
        $this->horarioFimTriagem = $horarioFimTriagem;
        return $this;
    }

    public function getHorarioInicioConsulta(): ?\DateTimeInterface
    {
        return $this->horarioInicioConsulta;
    }

    public function setHorarioInicioConsulta(?\DateTimeInterface $horarioInicioConsulta): static
    {
        $this->horarioInicioConsulta = $horarioInicioConsulta;
        return $this;
    }

    public function getHorarioFimConsulta(): ?\DateTimeInterface
    {
        return $this->horarioFimConsulta;
    }

    public function setHorarioFimConsulta(?\DateTimeInterface $horarioFimConsulta): static
    {
        $this->horarioFimConsulta = $horarioFimConsulta;
        return $this;
    }

    public function getHorarioSaida(): ?\DateTimeInterface
    {
        return $this->horarioSaida;
    }

    public function setHorarioSaida(?\DateTimeInterface $horarioSaida): static
    {
        $this->horarioSaida = $horarioSaida;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function isPrioridade(): bool
    {
        return $this->prioridade;
    }

    public function setPrioridade(bool $prioridade): static
    {
        $this->prioridade = $prioridade;
        return $this;
    }

    public function isEncaixe(): bool
    {
        return $this->encaixe;
    }

    public function setEncaixe(bool $encaixe): static
    {
        $this->encaixe = $encaixe;
        return $this;
    }

    public function getObservacoes(): ?string
    {
        return $this->observacoes;
    }

    public function setObservacoes(?string $observacoes): static
    {
        $this->observacoes = $observacoes;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, AtendimentoEtapaHistorico>
     */
    public function getHistoricoEtapas(): Collection
    {
        return $this->historicoEtapas;
    }

    public function addHistoricoEtapa(AtendimentoEtapaHistorico $historicoEtapa): static
    {
        if (!$this->historicoEtapas->contains($historicoEtapa)) {
            $this->historicoEtapas->add($historicoEtapa);
            $historicoEtapa->setAgendamento($this);
        }

        return $this;
    }

    public function removeHistoricoEtapa(AtendimentoEtapaHistorico $historicoEtapa): static
    {
        if ($this->historicoEtapas->removeElement($historicoEtapa)) {
            if ($historicoEtapa->getAgendamento() === $this) {
                $historicoEtapa->setAgendamento(null);
            }
        }

        return $this;
    }

    public function getAccessNumber(): ?string
    {
        return $this->accessNumber;
    }

    public function setAccessNumber(?string $accessNumber): static
    {
        $this->accessNumber = $accessNumber;
        return $this;
    }

    public function getQtdExames(): int
    {
        return $this->qtdExames;
    }

    public function setQtdExames(int $qtdExames): static
    {
        $this->qtdExames = $qtdExames;
        return $this;
    }

    public function getGuicheAtendimento(): ?string
    {
        return $this->guicheAtendimento;
    }

    public function setGuicheAtendimento(?string $guicheAtendimento): static
    {
        $this->guicheAtendimento = $guicheAtendimento;
        return $this;
    }

    public function getProcedimentoNome(): ?string
    {
        return $this->procedimentoNome;
    }

    public function setProcedimentoNome(?string $procedimentoNome): static
    {
        $this->procedimentoNome = $procedimentoNome;
        return $this;
    }

    public function getHorarioPrimeiraImagem(): ?\DateTimeInterface
    {
        return $this->horarioPrimeiraImagem;
    }

    public function setHorarioPrimeiraImagem(?\DateTimeInterface $horarioPrimeiraImagem): static
    {
        $this->horarioPrimeiraImagem = $horarioPrimeiraImagem;
        return $this;
    }

    /**
     * Calcula o tempo em recepção em minutos (da chegada ao fim da recepção).
     */
    public function getTempoRecepcaoMinutos(): ?int
    {
        if (!$this->horarioChegada || !$this->horarioFimTriagem) {
            return null;
        }
        $diff = $this->horarioChegada->diff($this->horarioFimTriagem);
        return ($diff->h * 60) + $diff->i;
    }

    /**
     * Calcula o tempo para recepção em minutos (da chegada ao início da recepção).
     */
    public function getTempoParaRecepcaoMinutos(): ?int
    {
        if (!$this->horarioChegada || !$this->horarioInicioTriagem) {
            return null;
        }
        $diff = $this->horarioChegada->diff($this->horarioInicioTriagem);
        return ($diff->h * 60) + $diff->i;
    }

    /**
     * Calcula o tempo total de permanência do paciente na clínica (em minutos).
     */
    public function getTempoTotalAtendimentoMinutos(): ?int
    {
        $inicio = $this->horarioChegada ?? $this->horarioConfirmacao;
        if (!$inicio) {
            return null;
        }
        $fim = $this->horarioSaida ?? new \DateTime();
        $diff = $inicio->diff($fim);
        return ($diff->h * 60) + $diff->i;
    }

    /**
     * Retorna a classificação de cor de SLA (verde, amarelo, vermelho)
     */
    public function getSlaStatus(int $limiteVerde = 59, int $limiteAmarelo = 119): string
    {
        $tempoTotal = $this->getTempoTotalAtendimentoMinutos() ?? 0;
        if ($tempoTotal <= $limiteVerde) {
            return 'verde';
        }
        if ($tempoTotal <= $limiteAmarelo) {
            return 'amarelo';
        }
        return 'vermelho';
    }

    /**
     * Calcula o tempo total de espera do paciente em minutos (desde a chegada até início da consulta).
     */
    public function getTempoEsperaMinutos(): ?int
    {
        $inicio = $this->horarioChegada ?? $this->horarioConfirmacao;
        if (!$inicio) {
            return null;
        }
        $fim = $this->horarioInicioConsulta ?? new \DateTime();
        $diff = $inicio->diff($fim);
        return ($diff->h * 60) + $diff->i;
    }

    /**
     * Calcula o tempo de consulta em minutos (do início da consulta ao fim da consulta).
     */
    public function getTempoConsultaMinutos(): ?int
    {
        if (!$this->horarioInicioConsulta) {
            return null;
        }
        $fim = $this->horarioFimConsulta ?? new \DateTime();
        $diff = $this->horarioInicioConsulta->diff($fim);
        return ($diff->h * 60) + $diff->i;
    }

    /**
     * Rótulo de etapa legível conforme docs/paineis.md
     */
    public function getEtapaRotuloPainel(): string
    {
        return match ($this->status) {
            'agendado' => 'AG REPASSE',
            'aguardando_triagem' => 'AG RECEPÇÃO',
            'em_triagem' => 'EM RECEPÇÃO',
            'aguardando_medico' => 'AG ATENDIMENTO',
            'em_consulta' => 'EM EXAME / CONSULTA',
            'finalizado' => 'ATEND FINALIZADO',
            default => strtoupper($this->status)
        };
    }
}

