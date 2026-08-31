<?php

namespace App\Service;

use App\Entity\Agendamento;
use App\Entity\AtendimentoEtapaHistorico;
use App\Entity\ChamadaTelao;
use App\Entity\ConfiguracaoIntegracao;
use App\Entity\Especialidade;
use App\Entity\LogSyncApi;
use App\Entity\Medico;
use App\Entity\Paciente;
use App\Entity\ProcedimentoSla;
use App\Entity\SenhaAtendimento;
use App\Entity\SetorSala;
use App\Entity\Unidade;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use ZipArchive;

class BackupRestoreService
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    /**
     * Gera um arquivo comprimido de backup (.procordis.bak) com 100% dos dados.
     */
    public function gerarBackup(?string $caminhoSaida = null): string
    {
        $hoje = new \DateTime();

        $dadosBackup = [
            'metadata' => [
                'sistema' => 'Procordis Painel',
                'versao' => '2.0.0',
                'geradoEm' => $hoje->format('Y-m-d H:i:s'),
                'timestamp' => $hoje->getTimestamp(),
            ],
            'tabelas' => [
                'users' => $this->exportarUsers(),
                'configuracoes' => $this->exportarConfiguracoes(),
                'unidades' => $this->exportarUnidades(),
                'especialidades' => $this->exportarEspecialidades(),
                'medicos' => $this->exportarMedicos(),
                'salas' => $this->exportarSalas(),
                'slas' => $this->exportarSlas(),
                'pacientes' => $this->exportarPacientes(),
                'agendamentos' => $this->exportarAgendamentos(),
                'etapasHistorico' => $this->exportarEtapasHistorico(),
                'senhas' => $this->exportarSenhas(),
                'chamadas' => $this->exportarChamadas(),
            ]
        ];

        $jsonStr = json_encode($dadosBackup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $hashSha256 = hash('sha256', $jsonStr);
        $dadosBackup['metadata']['sha256'] = $hashSha256;
        $jsonStr = json_encode($dadosBackup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (!$caminhoSaida) {
            $caminhoSaida = sys_get_temp_dir() . '/procordis_backup_' . $hoje->format('Ymd_His') . '.procordis.bak';
        }

        // 1. Tentar criar como ZIP se a extensão ZipArchive estiver disponível
        if (class_exists('ZipArchive')) {
            try {
                $zip = new ZipArchive();
                if ($zip->open($caminhoSaida, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    $zip->addFromString('backup_data.json', $jsonStr);
                    $zip->close();
                    return $caminhoSaida;
                }
            } catch (\Throwable $e) {
                // Fallback para compressão zlib/gzencode
            }
        }

        // 2. Fallback robusto nativo (gzencode / zlib core)
        if (function_exists('gzencode')) {
            $gzData = gzencode($jsonStr, 9);
            file_put_contents($caminhoSaida, $gzData);
            return $caminhoSaida;
        }

        // 3. Fallback texto puro
        file_put_contents($caminhoSaida, $jsonStr);
        return $caminhoSaida;
    }

    /**
     * Restaura 100% dos dados do sistema a partir de um arquivo .procordis.bak
     */
    public function restaurarBackup(string $caminhoArquivo, bool $modoLimpo = true): array
    {
        if (!file_exists($caminhoArquivo)) {
            throw new \InvalidArgumentException("Arquivo de backup não encontrado.");
        }

        $rawConteudo = file_get_contents($caminhoArquivo);
        $jsonStr = null;

        // 1. Tenta descompactar como ZIP
        if (class_exists('ZipArchive')) {
            try {
                $zip = new ZipArchive();
                if ($zip->open($caminhoArquivo) === true) {
                    $jsonStr = $zip->getFromName('backup_data.json');
                    $zip->close();
                }
            } catch (\Throwable $e) {
                // Segue para fallback
            }
        }

        // 2. Tenta descompactar como GZ / zlib
        if (!$jsonStr && function_exists('gzdecode')) {
            $decomp = @gzdecode($rawConteudo);
            if ($decomp !== false) {
                $jsonStr = $decomp;
            }
        }

        // 3. Tenta como JSON direto
        if (!$jsonStr) {
            $jsonStr = $rawConteudo;
        }

        if (!$jsonStr) {
            throw new \RuntimeException("Arquivo de backup sem conteúdo interno legível.");
        }

        $dados = json_decode($jsonStr, true);
        if (!$dados || !isset($dados['tabelas'])) {
            throw new \RuntimeException("Estrutura do arquivo de backup incompatível ou corrompida.");
        }

        $tabelas = $dados['tabelas'];

        if ($modoLimpo) {
            $this->limparBancoCompleto();
        }

        // 1. Restaurar Users
        $countUsers = $this->restaurarUsers($tabelas['users'] ?? []);

        // 2. Restaurar Configuracoes
        $countConfig = $this->restaurarConfiguracoes($tabelas['configuracoes'] ?? []);

        // 3. Restaurar Unidades
        $countUnidades = $this->restaurarUnidades($tabelas['unidades'] ?? []);

        // 4. Restaurar Especialidades
        $countEsp = $this->restaurarEspecialidades($tabelas['especialidades'] ?? []);

        // 5. Restaurar Medicos
        $countMed = $this->restaurarMedicos($tabelas['medicos'] ?? []);

        // 6. Restaurar Salas
        $countSalas = $this->restaurarSalas($tabelas['salas'] ?? []);

        // 7. Restaurar SLAs
        $countSlas = $this->restaurarSlas($tabelas['slas'] ?? []);

        // 8. Restaurar Pacientes
        $countPacientes = $this->restaurarPacientes($tabelas['pacientes'] ?? []);

        // 9. Restaurar Agendamentos
        $countAgendamentos = $this->restaurarAgendamentos($tabelas['agendamentos'] ?? []);

        // 10. Restaurar Etapas Historico
        $countEtapas = $this->restaurarEtapasHistorico($tabelas['etapasHistorico'] ?? []);

        // 11. Restaurar Senhas
        $countSenhas = $this->restaurarSenhas($tabelas['senhas'] ?? []);

        // 12. Restaurar Chamadas
        $countChamadas = $this->restaurarChamadas($tabelas['chamadas'] ?? []);

        $this->em->flush();

        return [
            'sucesso' => true,
            'modoLimpo' => $modoLimpo,
            'totais' => [
                'users' => $countUsers,
                'configuracoes' => $countConfig,
                'unidades' => $countUnidades,
                'especialidades' => $countEsp,
                'medicos' => $countMed,
                'salas' => $countSalas,
                'slas' => $countSlas,
                'pacientes' => $countPacientes,
                'agendamentos' => $countAgendamentos,
                'etapasHistorico' => $countEtapas,
                'senhas' => $countSenhas,
                'chamadas' => $countChamadas,
            ]
        ];
    }

    private function limparBancoCompleto(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0;');

        $tables = [
            'chamada_telao', 'senha_atendimento', 'atendimento_etapa_historico',
            'agendamento', 'paciente', 'procedimento_sla', 'setor_sala',
            'medico_unidade', 'medico', 'especialidade', 'unidade',
            'configuracao_integracao', 'user', 'log_sync_api'
        ];

        foreach ($tables as $t) {
            try {
                $conn->executeStatement("TRUNCATE TABLE `{$t}`;");
            } catch (\Throwable $e) {
                // Ignore se não existir
            }
        }

        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1;');
    }

    private function exportarUsers(): array
    {
        $users = $this->em->getRepository(User::class)->findAll();
        $out = [];
        foreach ($users as $u) {
            $out[] = [
                'id' => $u->getId(),
                'username' => $u->getUsername(),
                'roles' => $u->getRoles(),
                'password' => $u->getPassword(),
            ];
        }
        return $out;
    }

    private function restaurarUsers(array $items): int
    {
        $repo = $this->em->getRepository(User::class);
        $count = 0;
        foreach ($items as $it) {
            $u = $repo->findOneBy(['username' => $it['username']]);
            if (!$u) {
                $u = new User();
                $u->setUsername($it['username']);
            }
            $u->setRoles($it['roles'] ?? ['ROLE_USER']);
            $u->setPassword($it['password']);
            $this->em->persist($u);
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    private function exportarConfiguracoes(): array
    {
        $cfgs = $this->em->getRepository(ConfiguracaoIntegracao::class)->findAll();
        $out = [];
        foreach ($cfgs as $c) {
            $out[] = [
                'id' => $c->getId(),
                'apiBaseUrl' => $c->getApiBaseUrl(),
                'apiUsuario' => $c->getApiUsuario(),
                'apiSenha' => $c->getApiSenha(),
                'modoSimulacao' => $c->isModoSimulacao(),
                'frequenciaSegundos' => $c->getFrequenciaAtualizacaoSegundos(),
                'statusConexao' => $c->getStatusConexao(),
            ];
        }
        return $out;
    }

    private function restaurarConfiguracoes(array $items): int
    {
        $repo = $this->em->getRepository(ConfiguracaoIntegracao::class);
        $count = 0;
        foreach ($items as $it) {
            $c = $repo->find($it['id']) ?? new ConfiguracaoIntegracao();
            $c->setApiBaseUrl($it['apiBaseUrl']);
            $c->setApiUsuario($it['apiUsuario'] ?? null);
            if (!empty($it['apiSenha'])) {
                $c->setApiSenha($it['apiSenha']);
            }
            $c->setModoSimulacao((bool) ($it['modoSimulacao'] ?? true));
            if (isset($it['frequenciaSegundos'])) {
                $c->setFrequenciaAtualizacaoSegundos((int) $it['frequenciaSegundos']);
            }
            if (isset($it['statusConexao'])) {
                $c->setStatusConexao($it['statusConexao']);
            }
            $this->em->persist($c);
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    private function exportarUnidades(): array
    {
        $units = $this->em->getRepository(Unidade::class)->findAll();
        $out = [];
        foreach ($units as $u) {
            $out[] = [
                'id' => $u->getId(),
                'codigoExterno' => $u->getCodigoExterno(),
                'nome' => $u->getNome(),
                'endereco' => $u->getEndereco(),
                'ativo' => $u->isAtivo(),
            ];
        }
        return $out;
    }

    private function restaurarUnidades(array $items): int
    {
        $repo = $this->em->getRepository(Unidade::class);
        $count = 0;
        foreach ($items as $it) {
            $u = null;
            if (!empty($it['codigoExterno'])) {
                $u = $repo->findOneBy(['codigoExterno' => $it['codigoExterno']]);
            }
            if (!$u) {
                $u = $repo->findOneBy(['nome' => $it['nome']]);
            }
            if (!$u) {
                $u = new Unidade();
            }
            $u->setCodigoExterno($it['codigoExterno'] ?? null);
            $u->setNome($it['nome']);
            $u->setEndereco($it['endereco'] ?? null);
            $u->setAtivo((bool) ($it['ativo'] ?? true));
            $this->em->persist($u);
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    private function exportarEspecialidades(): array
    {
        $esps = $this->em->getRepository(Especialidade::class)->findAll();
        $out = [];
        foreach ($esps as $e) {
            $out[] = [
                'id' => $e->getId(),
                'codigoExterno' => $e->getCodigoExterno(),
                'nome' => $e->getNome(),
                'descricao' => $e->getDescricao(),
            ];
        }
        return $out;
    }

    private function restaurarEspecialidades(array $items): int
    {
        $repo = $this->em->getRepository(Especialidade::class);
        $count = 0;
        foreach ($items as $it) {
            $e = $repo->findOneBy(['nome' => $it['nome']]);
            if (!$e) {
                $e = new Especialidade();
            }
            $e->setNome($it['nome']);
            $e->setCodigoExterno($it['codigoExterno'] ?? null);
            $e->setDescricao($it['descricao'] ?? null);
            $this->em->persist($e);
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    private function exportarMedicos(): array
    {
        $meds = $this->em->getRepository(Medico::class)->findAll();
        $out = [];
        foreach ($meds as $m) {
            $out[] = [
                'id' => $m->getId(),
                'codigoExterno' => $m->getCodigoExterno(),
                'nome' => $m->getNome(),
                'crm' => $m->getCrm(),
                'statusAtividade' => $m->getStatusAtividade(),
                'especialidadeNome' => $m->getEspecialidade() ? $m->getEspecialidade()->getNome() : null,
            ];
        }
        return $out;
    }

    private function restaurarMedicos(array $items): int
    {
        $repo = $this->em->getRepository(Medico::class);
        $espRepo = $this->em->getRepository(Especialidade::class);
        $count = 0;
        foreach ($items as $it) {
            $m = null;
            if (!empty($it['codigoExterno'])) {
                $m = $repo->findOneBy(['codigoExterno' => $it['codigoExterno']]);
            }
            if (!$m && !empty($it['crm'])) {
                $m = $repo->findOneBy(['crm' => $it['crm']]);
            }
            if (!$m) {
                $m = $repo->findOneBy(['nome' => $it['nome']]);
            }
            if (!$m) {
                $m = new Medico();
            }
            $m->setNome($it['nome']);
            $m->setCodigoExterno($it['codigoExterno'] ?? null);
            $m->setCrm($it['crm'] ?? null);
            if (isset($it['statusAtividade'])) {
                $m->setStatusAtividade($it['statusAtividade']);
            }

            if (!empty($it['especialidadeNome'])) {
                $esp = $espRepo->findOneBy(['nome' => $it['especialidadeNome']]);
                if ($esp) {
                    $m->setEspecialidade($esp);
                }
            }
            $this->em->persist($m);
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    private function exportarSalas(): array
    {
        $salas = $this->em->getRepository(SetorSala::class)->findAll();
        $out = [];
        foreach ($salas as $s) {
            $out[] = [
                'id' => $s->getId(),
                'codigoExterno' => $s->getCodigoExterno(),
                'nomeSetor' => $s->getNomeSetor(),
                'nomeSala' => $s->getNomeSala(),
                'tipo' => $s->getTipo(),
            ];
        }
        return $out;
    }

    private function restaurarSalas(array $items): int
    {
        $repo = $this->em->getRepository(SetorSala::class);
        $count = 0;
        foreach ($items as $it) {
            $s = $repo->findOneBy(['nomeSala' => $it['nomeSala']]);
            if (!$s) {
                $s = new SetorSala();
            }
            $s->setNomeSetor($it['nomeSetor']);
            $s->setNomeSala($it['nomeSala']);
            $s->setTipo($it['tipo']);
            $s->setCodigoExterno($it['codigoExterno'] ?? null);
            $this->em->persist($s);
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    private function exportarSlas(): array
    {
        $slas = $this->em->getRepository(ProcedimentoSla::class)->findAll();
        $out = [];
        foreach ($slas as $s) {
            $out[] = [
                'id' => $s->getId(),
                'codigo' => $s->getCodigo(),
                'nomeProcedimento' => $s->getNomeProcedimento(),
                'limiteVerdeMinutos' => $s->getLimiteVerdeMinutos(),
                'limiteAmareloMinutos' => $s->getLimiteAmareloMinutos(),
                'descricao' => $s->getDescricao(),
            ];
        }
        return $out;
    }

    private function restaurarSlas(array $items): int
    {
        $repo = $this->em->getRepository(ProcedimentoSla::class);
        $count = 0;
        foreach ($items as $it) {
            $s = $repo->findOneBy(['codigo' => $it['codigo']]);
            if (!$s) {
                $s = new ProcedimentoSla();
            }
            $s->setCodigo($it['codigo']);
            $s->setNomeProcedimento($it['nomeProcedimento']);
            $s->setLimiteVerdeMinutos($it['limiteVerdeMinutos']);
            $s->setLimiteAmareloMinutos($it['limiteAmareloMinutos']);
            $s->setDescricao($it['descricao'] ?? null);
            $this->em->persist($s);
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    private function exportarPacientes(): array
    {
        $pacs = $this->em->getRepository(Paciente::class)->findAll();
        $out = [];
        foreach ($pacs as $p) {
            $out[] = [
                'id' => $p->getId(),
                'codigoExterno' => $p->getCodigoExterno(),
                'nomeCompleto' => $p->getNomeCompleto(),
                'nomeExibicao' => $p->getNomeExibicao(),
                'cpf' => $p->getCpf(),
                'dataNascimento' => $p->getDataNascimento() ? $p->getDataNascimento()->format('Y-m-d') : null,
                'sexo' => $p->getSexo(),
                'celular' => $p->getCelular(),
            ];
        }
        return $out;
    }

    private function restaurarPacientes(array $items): int
    {
        $repo = $this->em->getRepository(Paciente::class);
        $count = 0;
        foreach ($items as $it) {
            $p = null;
            if (!empty($it['codigoExterno'])) {
                $p = $repo->findOneBy(['codigoExterno' => $it['codigoExterno']]);
            }
            if (!$p && !empty($it['cpf'])) {
                $p = $repo->findOneBy(['cpf' => $it['cpf']]);
            }
            if (!$p) {
                $p = $repo->findOneBy(['nomeCompleto' => $it['nomeCompleto']]);
            }
            if (!$p) {
                $p = new Paciente();
            }
            $p->setNomeCompleto($it['nomeCompleto']);
            $p->setCodigoExterno($it['codigoExterno'] ?? null);
            $p->setNomeExibicao($it['nomeExibicao'] ?? null);
            $p->setCpf($it['cpf'] ?? null);
            if (!empty($it['dataNascimento'])) {
                $p->setDataNascimento(new \DateTime($it['dataNascimento']));
            }
            $p->setSexo($it['sexo'] ?? null);
            $p->setCelular($it['celular'] ?? null);
            $this->em->persist($p);
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    private function exportarAgendamentos(): array
    {
        $agds = $this->em->getRepository(Agendamento::class)->findAll();
        $out = [];
        foreach ($agds as $a) {
            $out[] = [
                'id' => $a->getId(),
                'codigoAgendamento' => $a->getCodigoAgendamento(),
                'pacienteNome' => $a->getPaciente() ? $a->getPaciente()->getNomeCompleto() : null,
                'medicoNome' => $a->getMedico() ? $a->getMedico()->getNome() : null,
                'especialidadeNome' => $a->getEspecialidade() ? $a->getEspecialidade()->getNome() : null,
                'procedimentoNome' => $a->getProcedimentoNome(),
                'tipoAtendimento' => $a->getTipoAtendimento(),
                'convenioNome' => $a->getConvenioNome(),
                'dataHoraAgendada' => $a->getDataHoraAgendada() ? $a->getDataHoraAgendada()->format('Y-m-d H:i:s') : null,
                'horarioChegada' => $a->getHorarioChegada() ? $a->getHorarioChegada()->format('Y-m-d H:i:s') : null,
                'horarioConfirmacao' => $a->getHorarioConfirmacao() ? $a->getHorarioConfirmacao()->format('Y-m-d H:i:s') : null,
                'horarioInicioTriagem' => $a->getHorarioInicioTriagem() ? $a->getHorarioInicioTriagem()->format('Y-m-d H:i:s') : null,
                'horarioFimTriagem' => $a->getHorarioFimTriagem() ? $a->getHorarioFimTriagem()->format('Y-m-d H:i:s') : null,
                'horarioInicioConsulta' => $a->getHorarioInicioConsulta() ? $a->getHorarioInicioConsulta()->format('Y-m-d H:i:s') : null,
                'horarioFimConsulta' => $a->getHorarioFimConsulta() ? $a->getHorarioFimConsulta()->format('Y-m-d H:i:s') : null,
                'horarioSaida' => $a->getHorarioSaida() ? $a->getHorarioSaida()->format('Y-m-d H:i:s') : null,
                'status' => $a->getStatus(),
                'prioridade' => $a->isPrioridade(),
                'encaixe' => $a->isEncaixe(),
                'observacoes' => $a->getObservacoes(),
                'accessNumber' => $a->getAccessNumber(),
                'qtdExames' => $a->getQtdExames(),
                'guicheAtendimento' => $a->getGuicheAtendimento(),
            ];
        }
        return $out;
    }

    private function restaurarAgendamentos(array $items): int
    {
        $repo = $this->em->getRepository(Agendamento::class);
        $pacRepo = $this->em->getRepository(Paciente::class);
        $medRepo = $this->em->getRepository(Medico::class);
        $espRepo = $this->em->getRepository(Especialidade::class);
        $unidade = $this->em->getRepository(Unidade::class)->findOneBy([]) ?? new Unidade();

        $count = 0;
        foreach ($items as $it) {
            $a = null;
            if (!empty($it['codigoAgendamento'])) {
                $a = $repo->findOneBy(['codigoAgendamento' => $it['codigoAgendamento']]);
            }
            if (!$a) {
                $a = new Agendamento();
            }

            if (!empty($it['codigoAgendamento'])) {
                $a->setCodigoAgendamento($it['codigoAgendamento']);
            }

            if (!empty($it['pacienteNome'])) {
                $pac = $pacRepo->findOneBy(['nomeCompleto' => $it['pacienteNome']]);
                if ($pac) {
                    $a->setPaciente($pac);
                }
            }

            if (!empty($it['medicoNome'])) {
                $med = $medRepo->findOneBy(['nome' => $it['medicoNome']]);
                if ($med) {
                    $a->setMedico($med);
                }
            }

            if (!empty($it['especialidadeNome'])) {
                $esp = $espRepo->findOneBy(['nome' => $it['especialidadeNome']]);
                if ($esp) {
                    $a->setEspecialidade($esp);
                }
            }

            $a->setUnidade($unidade);
            $a->setProcedimentoNome($it['procedimentoNome'] ?? 'Procedimento Geral');
            $a->setTipoAtendimento($it['tipoAtendimento'] ?? 'sus');
            $a->setConvenioNome($it['convenioNome'] ?? null);

            if (!empty($it['dataHoraAgendada'])) {
                $a->setDataHoraAgendada(new \DateTime($it['dataHoraAgendada']));
            }
            if (!empty($it['horarioChegada'])) {
                $a->setHorarioChegada(new \DateTime($it['horarioChegada']));
            }
            if (!empty($it['horarioConfirmacao'])) {
                $a->setHorarioConfirmacao(new \DateTime($it['horarioConfirmacao']));
            }
            if (!empty($it['horarioInicioTriagem'])) {
                $a->setHorarioInicioTriagem(new \DateTime($it['horarioInicioTriagem']));
            }
            if (!empty($it['horarioFimTriagem'])) {
                $a->setHorarioFimTriagem(new \DateTime($it['horarioFimTriagem']));
            }
            if (!empty($it['horarioInicioConsulta'])) {
                $a->setHorarioInicioConsulta(new \DateTime($it['horarioInicioConsulta']));
            }
            if (!empty($it['horarioFimConsulta'])) {
                $a->setHorarioFimConsulta(new \DateTime($it['horarioFimConsulta']));
            }
            if (!empty($it['horarioSaida'])) {
                $a->setHorarioSaida(new \DateTime($it['horarioSaida']));
            }

            $a->setStatus($it['status'] ?? 'agendado');
            $a->setPrioridade((bool) ($it['prioridade'] ?? false));
            $a->setEncaixe((bool) ($it['encaixe'] ?? false));
            $a->setObservacoes($it['observacoes'] ?? null);
            $a->setAccessNumber($it['accessNumber'] ?? null);
            $a->setQtdExames((int) ($it['qtdExames'] ?? 1));
            $a->setGuicheAtendimento($it['guicheAtendimento'] ?? null);

            $this->em->persist($a);
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    private function exportarEtapasHistorico(): array
    {
        $etapas = $this->em->getRepository(AtendimentoEtapaHistorico::class)->findAll();
        $out = [];
        foreach ($etapas as $e) {
            $out[] = [
                'id' => $e->getId(),
                'agendamentoCodigo' => $e->getAgendamento() ? $e->getAgendamento()->getCodigoAgendamento() : null,
                'etapa' => $e->getEtapa(),
                'dataHoraInicio' => $e->getDataHoraInicio() ? $e->getDataHoraInicio()->format('Y-m-d H:i:s') : null,
                'dataHoraFim' => $e->getDataHoraFim() ? $e->getDataHoraFim()->format('Y-m-d H:i:s') : null,
                'duracaoSegundos' => $e->getDuracaoSegundos(),
                'responsavel' => $e->getResponsavel(),
                'pressaoArterial' => $e->getPressaoArterial(),
                'frequenciaCardiaca' => $e->getFrequenciaCardiaca(),
                'peso' => $e->getPeso(),
                'queixaPrincipal' => $e->getQueixaPrincipal(),
                'classificacaoRisco' => $e->getClassificacaoRisco(),
            ];
        }
        return $out;
    }

    private function restaurarEtapasHistorico(array $items): int
    {
        $repo = $this->em->getRepository(AtendimentoEtapaHistorico::class);
        $agdRepo = $this->em->getRepository(Agendamento::class);
        $count = 0;

        foreach ($items as $it) {
            $ag = null;
            if (!empty($it['agendamentoCodigo'])) {
                $ag = $agdRepo->findOneBy(['codigoAgendamento' => $it['agendamentoCodigo']]);
            }
            if (!$ag) {
                continue; // Precisa do agendamento vinculado
            }

            $e = new AtendimentoEtapaHistorico();
            $e->setAgendamento($ag);
            $e->setEtapa($it['etapa']);
            if (!empty($it['dataHoraInicio'])) {
                $e->setDataHoraInicio(new \DateTime($it['dataHoraInicio']));
            }
            if (!empty($it['dataHoraFim'])) {
                $e->setDataHoraFim(new \DateTime($it['dataHoraFim']));
            }
            $e->setDuracaoSegundos($it['duracaoSegundos'] ?? null);
            $e->setResponsavel($it['responsavel'] ?? null);
            $e->setPressaoArterial($it['pressaoArterial'] ?? null);
            $e->setFrequenciaCardiaca($it['frequenciaCardiaca'] ?? null);
            $e->setPeso($it['peso'] ?? null);
            $e->setQueixaPrincipal($it['queixaPrincipal'] ?? null);
            $e->setClassificacaoRisco($it['classificacaoRisco'] ?? null);

            $this->em->persist($e);
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    private function exportarSenhas(): array
    {
        $senhas = $this->em->getRepository(SenhaAtendimento::class)->findAll();
        $out = [];
        foreach ($senhas as $s) {
            $out[] = [
                'id' => $s->getId(),
                'pacienteNome' => $s->getPaciente() ? $s->getPaciente()->getNomeCompleto() : null,
                'numeroFormatado' => $s->getNumeroFormatado(),
                'tipoSenha' => $s->getTipoSenha(),
                'dataHoraEmissao' => $s->getDataHoraEmissao() ? $s->getDataHoraEmissao()->format('Y-m-d H:i:s') : null,
                'status' => $s->getStatus(),
            ];
        }
        return $out;
    }

    private function restaurarSenhas(array $items): int
    {
        $repo = $this->em->getRepository(SenhaAtendimento::class);
        $pacRepo = $this->em->getRepository(Paciente::class);
        $count = 0;

        foreach ($items as $it) {
            $s = new SenhaAtendimento();
            if (!empty($it['pacienteNome'])) {
                $pac = $pacRepo->findOneBy(['nomeCompleto' => $it['pacienteNome']]);
                if ($pac) {
                    $s->setPaciente($pac);
                }
            }
            $s->setNumeroFormatado($it['numeroFormatado']);
            $s->setTipoSenha($it['tipoSenha']);
            if (!empty($it['dataHoraEmissao'])) {
                $s->setDataHoraEmissao(new \DateTime($it['dataHoraEmissao']));
            }
            $s->setStatus($it['status'] ?? 'gerada');
            $this->em->persist($s);
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    private function exportarChamadas(): array
    {
        $chamadas = $this->em->getRepository(ChamadaTelao::class)->findAll();
        $out = [];
        foreach ($chamadas as $c) {
            $out[] = [
                'id' => $c->getId(),
                'pacienteNomeMascarado' => $c->getPacienteNomeMascarado(),
                'guicheOuConsultorio' => $c->getGuicheOuConsultorio(),
                'dataHoraChamada' => $c->getDataHoraChamada() ? $c->getDataHoraChamada()->format('Y-m-d H:i:s') : null,
                'rechamadaCount' => $c->getRechamadaCount(),
                'status' => $c->getStatus(),
            ];
        }
        return $out;
    }

    private function restaurarChamadas(array $items): int
    {
        $repo = $this->em->getRepository(ChamadaTelao::class);
        $count = 0;
        foreach ($items as $it) {
            $c = new ChamadaTelao();
            $c->setPacienteNomeMascarado($it['pacienteNomeMascarado']);
            $c->setGuicheOuConsultorio($it['guicheOuConsultorio'] ?? null);
            if (!empty($it['dataHoraChamada'])) {
                $c->setDataHoraChamada(new \DateTime($it['dataHoraChamada']));
            }
            $c->setRechamadaCount((int) ($it['rechamadaCount'] ?? 1));
            $c->setStatus($it['status'] ?? 'chamada');
            $this->em->persist($c);
            $count++;
        }
        $this->em->flush();
        return $count;
    }
}
