<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\AtendimentoConsolidacaoService;
use App\Tests\Support\AtendimentoAmostra as A;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * API pública de atendimentos (consumida pelo site) e telas do admin.
 */
class AtendimentosControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        static::getContainer()->get('cache.app')->clear();

        $conn = $this->em->getConnection();
        A::gravarDia($conn, '2026-08-10', [A::item(1, '10/08/2026 08:00', 5), A::item(2, '10/08/2026 09:00', 7)]);
        A::gravarDia($conn, '2026-09-15', [A::item(3, '15/09/2026 08:00', 6), A::item(4, '15/09/2026 09:00', 6, 5, 'CANCELADO')]);
        static::getContainer()->get(AtendimentoConsolidacaoService::class)->processarPendentes();
    }

    public function testSerieMensalPublica(): void
    {
        $this->client->request('GET', '/api/publico/atendimentos/serie?agrupamento=mes', [], [], ['HTTP_ORIGIN' => 'https://procordis.org.br']);

        $this->assertResponseIsSuccessful();
        $r = $this->client->getResponse();
        $this->assertSame('https://procordis.org.br', $r->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('public', (string) $r->headers->get('Cache-Control'));

        $d = json_decode($r->getContent(), true);
        $this->assertSame(['2026-08', '2026-09'], $d['periodos']);
        $this->assertSame([2, 1], $d['total']['valores']);
        $this->assertNotContains('retirada-equipamento', array_column($d['series'], 'slug'));
        $this->assertStringNotContainsString('PACIENTE', $r->getContent(), 'A API pública não expõe pacientes');
        $this->assertStringNotContainsString('DR TESTE', $r->getContent());
    }

    public function testSerieDiariaComPeriodo(): void
    {
        $this->client->request('GET', '/api/publico/atendimentos/serie?agrupamento=dia&de=2026-09-14&ate=2026-09-16');

        $this->assertResponseIsSuccessful();
        $d = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(['2026-09-14', '2026-09-15', '2026-09-16'], $d['periodos']);
        $this->assertSame([0, 1, 0], $d['total']['valores']);
    }

    public function testOrigemNaoPermitidaNaoRecebeCors(): void
    {
        $this->client->request('GET', '/api/publico/atendimentos/serie', [], [], ['HTTP_ORIGIN' => 'https://exemplo.com']);

        $this->assertResponseIsSuccessful();
        $this->assertNull($this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * @dataProvider parametrosInvalidos
     */
    public function testParametrosInvalidos(string $query): void
    {
        $this->client->request('GET', '/api/publico/atendimentos/serie?' . $query);

        $this->assertResponseStatusCodeSame(400);
    }

    public static function parametrosInvalidos(): iterable
    {
        yield 'agrupamento' => ['agrupamento=semana'];
        yield 'data' => ['de=10/09/2026'];
    }

    public function testResumo(): void
    {
        $this->client->request('GET', '/api/publico/atendimentos/resumo');

        $this->assertResponseIsSuccessful();
        $d = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(3, $d['total']);
        $porSlug = array_column($d['categorias'], 'total', 'slug');
        $this->assertSame(['consulta' => 1, 'ecocardiograma' => 1, 'eletrocardiograma' => 1], array_filter($porSlug));
        $this->assertSame('2026-08-10', $d['historico']['primeiraData']);
    }

    /**
     * @dataProvider rotasAdmin
     */
    public function testAdminExigeLogin(string $url): void
    {
        $this->client->request('GET', $url);

        $this->assertTrue(in_array($this->client->getResponse()->getStatusCode(), [302, 401], true));
    }

    /**
     * @dataProvider rotasAdmin
     */
    public function testAdminRenderiza(string $url, string $texto): void
    {
        $this->logar();
        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', $texto);
    }

    public static function rotasAdmin(): iterable
    {
        yield 'painel mensal' => ['/admin/atendimentos', 'Atendimentos realizados'];
        yield 'painel diário' => ['/admin/atendimentos?agrupamento=dia', 'Atendimentos realizados'];
        yield 'painel anual' => ['/admin/atendimentos?agrupamento=ano', 'Atendimentos realizados'];
        yield 'procedimentos' => ['/admin/atendimentos/procedimentos', 'Procedimentos'];
        yield 'categorias' => ['/admin/atendimentos/categorias', 'Tipos de atendimento'];
    }

    public function testAdminAlteraCategoriaDoProcedimento(): void
    {
        $this->logar();
        $crawler = $this->client->request('GET', '/admin/atendimentos/procedimentos');
        $form = $crawler->selectButton('Salvar alterações')->form();

        $conn = $this->em->getConnection();
        $idEcg = (int) $conn->fetchOne('SELECT id FROM atendimento_procedimento WHERE cod_procedimento = 7');
        $idOutros = (int) $conn->fetchOne("SELECT id FROM atendimento_categoria WHERE slug = 'outros'");
        $form["categoria[$idEcg]"]->select((string) $idOutros);
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/atendimentos/procedimentos');
        $this->assertSame(['outros', 1], array_values($conn->fetchAssociative('SELECT c.slug, p.categoria_manual FROM atendimento_procedimento p JOIN atendimento_categoria c ON c.id = p.categoria_id WHERE p.id = ?', [$idEcg])));

        // A série pública reflete a mudança (cache invalidado).
        $this->client->request('GET', '/api/publico/atendimentos/serie?agrupamento=mes');
        $porSlug = array_column(json_decode($this->client->getResponse()->getContent(), true)['series'], null, 'slug');
        $this->assertSame([1, 0], $porSlug['outros']['valores']);
        $this->assertSame([0, 0], $porSlug['eletrocardiograma']['valores']);
    }

    public function testAdminOcultaCategoriaDoSite(): void
    {
        $this->logar();
        $crawler = $this->client->request('GET', '/admin/atendimentos/categorias');
        $form = $crawler->selectButton('Salvar')->form();
        $idConsulta = (int) $this->em->getConnection()->fetchOne("SELECT id FROM atendimento_categoria WHERE slug = 'consulta'");
        $form["cat[$idConsulta][site]"]->untick();
        $this->client->submit($form);

        $this->client->request('GET', '/api/publico/atendimentos/serie?agrupamento=mes');
        $d = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNotContains('consulta', array_column($d['series'], 'slug'));
        $this->assertSame([1, 1], $d['total']['valores']);
    }

    private function logar(): void
    {
        $user = (new User())->setUsername('admin-atendimentos')->setPassword('x')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
