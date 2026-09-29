<?php

namespace App\Service;

use App\Entity\Paciente;
use App\Entity\PacienteHistorico;
use App\Repository\PacienteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ponto único para criar/atualizar pacientes a partir da API, sempre pela chave codPaciente,
 * registrando em paciente_historico cada campo que mudar.
 *
 * Não faz flush: quem chama controla a transação.
 */
class PacienteRegistroService
{
    /** @var array<int, Paciente> cache de pacientes gerenciados, por codPaciente */
    private array $cache = [];

    public function __construct(
        private EntityManagerInterface $em,
        private PacienteRepository $pacienteRepo,
    ) {
    }

    /**
     * @param array{nome?: ?string, cpf?: ?string, celular?: ?string, sexo?: ?string, dataNascimento?: ?\DateTimeInterface} $dados
     */
    public function sincronizarPorCodigo(int $codPaciente, array $dados, string $origem): Paciente
    {
        $paciente = $this->buscarPorCodigo($codPaciente);
        $agora = new \DateTime();

        if (!$paciente) {
            $paciente = new Paciente();
            $paciente->setCodPaciente($codPaciente);
            $paciente->setCodigoExterno('PAC-' . $codPaciente);
            $paciente->setNomeCompleto($this->limpar($dados['nome'] ?? null) ?? ('Paciente ' . $codPaciente));
            $paciente->setPrimeiroVistoEm($agora);
            $paciente->setAtualizadoEm($agora);
            $this->aplicarSemHistorico($paciente, $dados);
            $this->em->persist($paciente);
            $this->cache[$codPaciente] = $paciente;

            return $paciente;
        }

        if (!$paciente->getPrimeiroVistoEm()) {
            $paciente->setPrimeiroVistoEm($agora);
        }

        $mudou = false;
        $nome = $this->limpar($dados['nome'] ?? null);
        if ($nome !== null && $nome !== $paciente->getNomeCompleto()) {
            $this->historico($paciente, 'nome', $paciente->getNomeCompleto(), $nome, $origem);
            $paciente->setNomeCompleto($nome);
            $paciente->setNomeExibicao(null);
            $paciente->setNomeExibicao($paciente->getNomeExibicao());
            $mudou = true;
        }

        $cpf = $this->limpar($dados['cpf'] ?? null);
        if ($cpf !== null && $cpf !== $paciente->getCpf()) {
            $this->historico($paciente, 'cpf', $paciente->getCpf(), $cpf, $origem);
            $paciente->setCpf(mb_substr($cpf, 0, 50));
            $mudou = true;
        }

        $celular = $this->limpar($dados['celular'] ?? null);
        if ($celular !== null && $celular !== $paciente->getCelular()) {
            $this->historico($paciente, 'celular', $paciente->getCelular(), $celular, $origem);
            $paciente->setCelular(mb_substr($celular, 0, 250));
            $mudou = true;
        }

        $sexo = $this->limpar($dados['sexo'] ?? null);
        if ($sexo !== null && $sexo !== $paciente->getSexo()) {
            $this->historico($paciente, 'sexo', $paciente->getSexo(), $sexo, $origem);
            $paciente->setSexo(mb_substr($sexo, 0, 20));
            $mudou = true;
        }

        $nasc = $dados['dataNascimento'] ?? null;
        if ($nasc instanceof \DateTimeInterface) {
            $atual = $paciente->getDataNascimento()?->format('Y-m-d');
            if ($atual !== $nasc->format('Y-m-d')) {
                $this->historico($paciente, 'dataNascimento', $atual, $nasc->format('Y-m-d'), $origem);
                $paciente->setDataNascimento($nasc);
                $mudou = true;
            }
        }

        if ($mudou) {
            $paciente->setAtualizadoEm($agora);
        }

        return $paciente;
    }

    /**
     * Garante a existência de vários pacientes de uma vez (somente nome).
     *
     * @param array<int, ?string> $nomesPorCodigo
     *
     * @return array<int, Paciente>
     */
    public function garantirVarios(array $nomesPorCodigo, string $origem): array
    {
        $faltando = array_diff(array_keys($nomesPorCodigo), array_keys($this->cacheValido()));
        if ($faltando) {
            foreach (array_chunk($faltando, 500) as $lote) {
                $encontrados = $this->pacienteRepo->createQueryBuilder('p')
                    ->where('p.codPaciente IN (:cods)')
                    ->setParameter('cods', $lote)
                    ->getQuery()
                    ->getResult();
                foreach ($encontrados as $p) {
                    $this->cache[$p->getCodPaciente()] = $p;
                }
            }
        }

        $res = [];
        foreach ($nomesPorCodigo as $cod => $nome) {
            $res[$cod] = $this->sincronizarPorCodigo((int) $cod, ['nome' => $nome], $origem);
        }

        return $res;
    }

    public function limparCache(): void
    {
        $this->cache = [];
    }

    private function buscarPorCodigo(int $cod): ?Paciente
    {
        $cache = $this->cacheValido();
        if (isset($cache[$cod])) {
            return $cache[$cod];
        }

        $p = $this->pacienteRepo->findOneBy(['codPaciente' => $cod]);
        if (!$p) {
            // Registros antigos: só tinham "PAC-<cod>" em codigoExterno. Adota apenas se veio da API real
            // (agendamento com código numérico) — o simulador antigo gerava "PAC-<aleatório>".
            $p = $this->pacienteRepo->findOneBy(['codigoExterno' => 'PAC-' . $cod, 'codPaciente' => null]);
            if ($p && !$this->temAgendamentoReal($p)) {
                $p = null;
            }
            $p?->setCodPaciente($cod);
        }
        if ($p) {
            $this->cache[$cod] = $p;
        }

        return $p;
    }

    private function temAgendamentoReal(Paciente $p): bool
    {
        if (!$p->getId()) {
            return false;
        }
        $codigos = $this->em->getConnection()->fetchFirstColumn(
            'SELECT codigo_agendamento FROM agendamento WHERE paciente_id = ? AND codigo_agendamento IS NOT NULL',
            [$p->getId()]
        );
        foreach ($codigos as $c) {
            if (ctype_digit((string) $c)) {
                return true;
            }
        }

        return false;
    }

    /** Descarta do cache entidades que saíram do EntityManager (após clear()). */
    private function cacheValido(): array
    {
        foreach ($this->cache as $cod => $p) {
            if (!$this->em->contains($p)) {
                unset($this->cache[$cod]);
            }
        }

        return $this->cache;
    }

    private function aplicarSemHistorico(Paciente $p, array $dados): void
    {
        if ($v = $this->limpar($dados['cpf'] ?? null)) {
            $p->setCpf(mb_substr($v, 0, 50));
        }
        if ($v = $this->limpar($dados['celular'] ?? null)) {
            $p->setCelular(mb_substr($v, 0, 250));
        }
        if ($v = $this->limpar($dados['sexo'] ?? null)) {
            $p->setSexo(mb_substr($v, 0, 20));
        }
        if (($dados['dataNascimento'] ?? null) instanceof \DateTimeInterface) {
            $p->setDataNascimento($dados['dataNascimento']);
        }
    }

    private function historico(Paciente $p, string $campo, ?string $antes, ?string $depois, string $origem): void
    {
        $h = new PacienteHistorico();
        $h->setPaciente($p);
        $h->setCampo($campo);
        $h->setValorAnterior($antes);
        $h->setValorNovo($depois);
        $h->setOrigem($origem);
        $this->em->persist($h);
    }

    private function limpar(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
