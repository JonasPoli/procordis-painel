<?php

namespace App\Entity;

use App\Repository\AtendimentoEtapaHistoricoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AtendimentoEtapaHistoricoRepository::class)]
#[ORM\Table(name: 'atendimento_etapa_historico')]
class AtendimentoEtapaHistorico
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'historicoEtapas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Agendamento $agendamento = null;

    #[ORM\Column(length: 50)]
    private ?string $etapa = null; // chegada, triagem, medico, exame, finalizacao

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dataHoraInicio = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dataHoraFim = null;

    #[ORM\Column(nullable: true)]
    private ?int $duracaoSegundos = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $responsavel = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?SetorSala $setorSala = null;

    public function __construct()
    {
        $this->dataHoraInicio = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getEtapa(): ?string
    {
        return $this->etapa;
    }

    public function setEtapa(string $etapa): static
    {
        $this->etapa = $etapa;
        return $this;
    }

    public function getDataHoraInicio(): ?\DateTimeInterface
    {
        return $this->dataHoraInicio;
    }

    public function setDataHoraInicio(\DateTimeInterface $dataHoraInicio): static
    {
        $this->dataHoraInicio = $dataHoraInicio;
        return $this;
    }

    public function getDataHoraFim(): ?\DateTimeInterface
    {
        return $this->dataHoraFim;
    }

    public function setDataHoraFim(?\DateTimeInterface $dataHoraFim): static
    {
        $this->dataHoraFim = $dataHoraFim;
        if ($dataHoraFim && $this->dataHoraInicio) {
            $this->duracaoSegundos = $dataHoraFim->getTimestamp() - $this->dataHoraInicio->getTimestamp();
        }
        return $this;
    }

    public function getDuracaoSegundos(): ?int
    {
        return $this->duracaoSegundos;
    }

    public function setDuracaoSegundos(?int $duracaoSegundos): static
    {
        $this->duracaoSegundos = $duracaoSegundos;
        return $this;
    }

    public function getResponsavel(): ?string
    {
        return $this->responsavel;
    }

    public function setResponsavel(?string $responsavel): static
    {
        $this->responsavel = $responsavel;
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
}
