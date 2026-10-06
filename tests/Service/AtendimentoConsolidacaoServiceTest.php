<?php

namespace App\Tests\Service;

use App\Entity\Atendimento;
use App\Entity\AtendimentoProcedimento;
use App\Service\AtendimentoConsolidacaoService;
use App\Service\AtendimentoSerieService;
use App\Tests\Support\AtendimentoAmostra as A;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Consolidação da camada bruta em atendimento e séries para os gráficos.
 */
class AtendimentoConsolidacaoServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $tool = new SchemaTool($this->em);
        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        static::getContainer()->get('cache.app')->clear();
    }

    public function testConsolidaRealizadosCanceladosEFaltas(): void
    {
        A::gravarDia($this->conn, '2026-10-05', [
            A::item(1, '05/10/2026 08:00', 5),                       // consulta liberada
            A::item(2, '05/10/2026 08:30', 6, 4),                    // eco atendido
            A::item(3, '05/10/2026 09:00', 7, 5, 'CANCELADO'),       // cancelado não conta
            A::item(4, '05/10/2026 09:30', 7, 6),                    // faltou não conta
            A::item(5, '05/10/2026 10:00', 41, 5),                   // retorno de holter
        ]);

        $r = $this->servico()->processarPendentes();

        $this->assertSame(['dias' => 1, 'agendamentos' => 5, 'realizados' => 3, 'removidos' => 0, 'ignorados' => 0], $r);
        $this->assertSame([1 => 1, 2 => 1, 3 => 0, 4 => 0, 5 => 1], $this->realizadosPorCodigo());
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT cancelado FROM atendimento WHERE cod_agendamento = 3'));

        $a = $this->em->getRepository(Atendimento::class)->findOneBy(['codAgendamento' => 1]);
        $this->assertSame('F', $a->getSexo());
        $this->assertSame(66, $a->getIdade());
        $this->assertSame('2026-10-05 08:00', $a->getDataHoraAgendada()->format('Y-m-d H:i'));
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM atendimento_captura_dia WHERE processado_em IS NULL'));
    }

    public function testProcedimentosSaoClassificadosPelaDescricao(): void
    {
        A::gravarDia($this->conn, '2026-10-05', array_map(fn ($cod, $i) => A::item($i + 1, '05/10/2026 08:00', $cod), array_keys(A::PROCEDIMENTOS), array_keys(array_keys(A::PROCEDIMENTOS))));
        $this->servico()->processarPendentes();

        $categorias = $this->conn->fetchAllKeyValue('SELECT p.cod_procedimento, c.slug FROM atendimento_procedimento p JOIN atendimento_categoria c ON c.id = p.categoria_id ORDER BY p.cod_procedimento');
        $this->assertSame([
            5 => 'consulta', 6 => 'ecocardiograma', 7 => 'eletrocardiograma', 8 => 'holter', 9 => 'mapa',
            10 => 'teste-ergometrico', 39 => 'consulta', 40 => 'retirada-equipamento', 41 => 'retirada-equipamento',
        ], $categorias);
    }

    public function testDiaCompletoRemoveAgendamentoQueSumiuEIncompletoNao(): void
    {
        A::gravarDia($this->conn, '2026-10-05', [A::item(1, '05/10/2026 08:00', 5), A::item(2, '05/10/2026 09:00', 6)]);
        $this->servico()->processarPendentes();

        // Recaptura incompleta sem o agendamento 2: não remove.
        A::gravarDia($this->conn, '2026-10-05', [A::item(1, '05/10/2026 08:00', 5)], false);
        $this->servico()->processarPendentes();
        $this->assertSame(2, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM atendimento'));

        // Recaptura completa: o 2 foi reagendado para outro dia e sai deste.
        A::gravarDia($this->conn, '2026-10-05', [A::item(1, '05/10/2026 08:00', 5)]);
        $r = $this->servico()->processarPendentes();
        $this->assertSame(1, $r['removidos']);

        // E reaparece na nova data quando o outro dia é capturado.
        A::gravarDia($this->conn, '2026-10-07', [A::item(2, '07/10/2026 09:00', 6)]);
        $this->servico()->processarPendentes();
        $this->assertSame('2026-10-07', $this->conn->fetchOne('SELECT data FROM atendimento WHERE cod_agendamento = 2'));
    }

    public function testMudancaDeEstagioAtualizaORegistro(): void
    {
        A::gravarDia($this->conn, '2026-10-05', [A::item(1, '05/10/2026 08:00', 5, 1)]);
        $this->servico()->processarPendentes();
        $this->assertSame([1 => 0], $this->realizadosPorCodigo());

        A::gravarDia($this->conn, '2026-10-05', [A::item(1, '05/10/2026 08:00', 5, 5)]);
        $this->servico()->processarPendentes();
        $this->assertSame([1 => 1], $this->realizadosPorCodigo());
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM atendimento'));
    }

    public function testCategoriaManualNaoEhSobrescrita(): void
    {
        A::gravarDia($this->conn, '2026-10-05', [A::item(1, '05/10/2026 08:00', 41)]);
        $this->servico()->processarPendentes();

        $proc = $this->em->getRepository(AtendimentoProcedimento::class)->findOneBy(['codProcedimento' => 41]);
        $holter = $this->em->getRepository(\App\Entity\AtendimentoCategoria::class)->findOneBy(['slug' => 'holter']);
        $proc->setCategoria($holter)->setCategoriaManual(true);
        $this->em->flush();

        $this->servico()->processarPendentes(true);
        $this->assertSame('holter', $this->conn->fetchOne('SELECT c.slug FROM atendimento_procedimento p JOIN atendimento_categoria c ON c.id = p.categoria_id WHERE p.cod_procedimento = 41'));
    }

    public function testSerieMensalPreencheMesesSemAtendimentoEOcultaCategoriasForaDoSite(): void
    {
        A::gravarDia($this->conn, '2026-07-10', [A::item(1, '10/07/2026 08:00', 5), A::item(2, '10/07/2026 09:00', 6, 5, 'ATIVO', 2000)]);
        A::gravarDia($this->conn, '2026-09-02', [A::item(3, '02/09/2026 08:00', 5), A::item(4, '02/09/2026 09:00', 40)]);
        $this->servico()->processarPendentes();

        /** @var AtendimentoSerieService $series */
        $series = static::getContainer()->get(AtendimentoSerieService::class);
        $s = $series->serie('mes');

        $this->assertSame(['2026-07', '2026-08', '2026-09'], $s['periodos']);
        $this->assertSame(['jul/2026', 'ago/2026', 'set/2026'], $s['rotulos']);
        $this->assertSame([2, 0, 1], $s['total']['valores'], 'Retirada de Holter/MAPA fica fora do total do site');
        $this->assertSame([2, 0, 1], $s['pacientes']['valores']);
        $porSlug = array_column($s['series'], null, 'slug');
        $this->assertSame([1, 0, 1], $porSlug['consulta']['valores']);
        $this->assertSame([1, 0, 0], $porSlug['ecocardiograma']['valores']);
        $this->assertArrayNotHasKey('retirada-equipamento', $porSlug);

        $admin = array_column($series->serie('mes', null, null, false)['series'], null, 'slug');
        $this->assertSame([0, 0, 1], $admin['retirada-equipamento']['valores']);

        $diaria = $series->serie('dia', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-03'));
        $this->assertSame(['01/09/2026', '02/09/2026', '03/09/2026'], $diaria['rotulos']);
        $this->assertSame([0, 1, 0], $diaria['total']['valores']);
    }

    /**
     * @dataProvider descricoes
     */
    public function testClassificar(string $descricao, bool $consulta, string $esperado): void
    {
        $this->assertSame($esperado, AtendimentoConsolidacaoService::classificar($descricao, $consulta));
    }

    public static function descricoes(): iterable
    {
        yield ['Ecocardiograma transtorárico', false, 'ecocardiograma'];
        yield ['ECO COM DOPPLER', false, 'ecocardiograma'];
        yield ['Eletrocardiograma - EGPC', false, 'eletrocardiograma'];
        yield ['ECG', false, 'eletrocardiograma'];
        yield ['Teste Ergométrico', false, 'teste-ergometrico'];
        yield ['TESTE DE ESTEIRA', false, 'teste-ergometrico'];
        yield ['Holter', false, 'holter'];
        yield ['Mapa', false, 'mapa'];
        yield ['Retorno Mapa', false, 'retirada-equipamento'];
        yield ['Consulta de Retorno', true, 'consulta'];
        yield ['Atendimento cardiológico', true, 'consulta'];
        yield ['Mapeamento qualquer', false, 'outros'];
    }

    /**
     * @dataProvider statusApi
     */
    public function testCancelado(mixed $status, bool $esperado): void
    {
        $this->assertSame($esperado, AtendimentoConsolidacaoService::cancelado($status));
    }

    public static function statusApi(): iterable
    {
        yield 'texto ativo' => ['ATIVO', false];
        yield 'texto cancelado' => ['CANCELADO', true];
        yield 'resumido ativo' => [-1, false];
        yield 'resumido cancelado' => [0, true];
        yield 'ausente' => [null, false];
    }

    private function servico(): AtendimentoConsolidacaoService
    {
        return static::getContainer()->get(AtendimentoConsolidacaoService::class);
    }

    /** @return array<int, int> */
    private function realizadosPorCodigo(): array
    {
        return array_map('intval', $this->conn->fetchAllKeyValue('SELECT cod_agendamento, realizado FROM atendimento ORDER BY cod_agendamento'));
    }
}
