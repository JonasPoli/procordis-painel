<?php

namespace App\Tests\Controller;

use App\Entity\AnamneseTermo;
use App\Entity\ClassificacaoEstudo;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tela do thesaurus da anamnese: cadastro de termos, regras de nome, escolhas manuais e exclusão.
 */
class AnamneseThesaurusControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        $this->conn = $this->em->getConnection();

        foreach (['COVID 2019', 'COVID 2020', 'COVID 2021', 'DENGUE', 'QUIMIO', 'TRATAMENTO QUIMIO', 'VACINA DA COVID DOSE 1'] as $i => $nome) {
            $c = (new ClassificacaoEstudo())->setCodClassificacao(10 + $i)->setNome($nome)->setCategoria(ClassificacaoEstudo::sugerirCategoria($nome));
            $this->em->persist($c);
            $this->ids[$nome] = 0;
            $itens[$nome] = $c;
        }
        $user = (new User())->setUsername('admin-thesaurus')->setPassword('x')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();
        foreach ($itens as $nome => $c) {
            $this->ids[$nome] = $c->getId();
        }
        $this->client->loginUser($user);
    }

    public function testCriaTermoPelaRegraERecusaNomeRepetidoERegraVazia(): void
    {
        $this->salvar('/admin/anamnese/thesaurus/novo', ['nome' => 'Covid', 'categoria' => 'comorbidade', 'padroes' => "COVID ####\n", 'itens' => []]);
        $this->assertResponseRedirects('/admin/anamnese/thesaurus');
        $html = $this->client->followRedirect()->html();
        $this->assertStringContainsString('Termo “Covid” salvo com 3 itens.', $html);
        $this->assertStringContainsString('automático', $html);

        $this->assertSame(['COVID 2019', 'COVID 2020', 'COVID 2021'], $this->itensDoTermo('Covid'));
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM classificacao_estudo WHERE termo_manual = 1'), 'Ligados pela regra, não por escolha manual');

        $this->salvar('/admin/anamnese/thesaurus/novo', ['nome' => 'covid', 'categoria' => 'comorbidade', 'padroes' => '', 'itens' => []]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Já existe um termo chamado “Covid”', $this->client->getResponse()->getContent());

        $this->salvar('/admin/anamnese/thesaurus/novo', ['nome' => 'Tudo', 'categoria' => 'outros', 'padroes' => '*', 'itens' => []]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM anamnese_termo'));
    }

    public function testEscolhasManuaisEVoltarAoAutomatico(): void
    {
        $termo = $this->termo('Covid', 'COVID ####');
        $url = '/admin/anamnese/thesaurus/' . $termo->getId();

        // Formulário vem com os itens da regra marcados
        $crawler = $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $this->assertCount(3, $crawler->filter('input[name="itens[]"]:checked'));

        // Desmarca COVID 2019 (contra a regra) e marca DENGUE (sem regra): duas escolhas manuais
        $this->salvar($url, ['nome' => 'Covid', 'categoria' => 'comorbidade', 'padroes' => 'COVID ####', 'itens' => [$this->ids['COVID 2020'], $this->ids['COVID 2021'], $this->ids['DENGUE']]]);
        $this->assertResponseRedirects('/admin/anamnese/thesaurus');
        $this->assertSame(['COVID 2020', 'COVID 2021', 'DENGUE'], $this->itensDoTermo('Covid'));
        $this->assertEquals(['termo_id' => null, 'termo_manual' => 1], $this->conn->fetchAssociative('SELECT termo_id, termo_manual FROM classificacao_estudo WHERE id = ?', [$this->ids['COVID 2019']]));

        // A regra não desfaz a escolha manual
        static::getContainer()->get(\App\Service\AnamneseThesaurusService::class)->aplicarRegras();
        $this->assertSame(['COVID 2020', 'COVID 2021', 'DENGUE'], $this->itensDoTermo('Covid'));

        // A lista mostra as escolhas manuais; COVID 2019 volta ao automático e à Covid
        $crawler = $this->client->request('GET', '/admin/anamnese/thesaurus');
        $this->assertStringContainsString('Escolhas manuais', $crawler->html());
        $this->client->request('POST', '/admin/anamnese/thesaurus/item/' . $this->ids['COVID 2019'] . '/automatico', ['_token' => $this->token($crawler)]);
        $this->assertResponseRedirects('/admin/anamnese/thesaurus');
        $this->assertSame(['COVID 2019', 'COVID 2020', 'COVID 2021', 'DENGUE'], $this->itensDoTermo('Covid'));
    }

    public function testExcluirDevolveOsItensESugestoesNaoJuntamNada(): void
    {
        $termo = $this->termo('Covid', 'COVID ####');
        $this->conn->update('classificacao_estudo', ['termo_id' => $termo->getId(), 'termo_manual' => 1], ['id' => $this->ids['DENGUE']]);

        $crawler = $this->client->request('GET', '/admin/anamnese/thesaurus');
        $html = $crawler->html();
        $this->assertStringContainsString('QUIMIO', $html, 'Quimio × Tratamento Quimio aparece como sugestão');
        $this->assertStringContainsString('Criar termo com os dois', $html);
        $this->assertNull($this->conn->fetchOne('SELECT termo_id FROM classificacao_estudo WHERE id = ?', [$this->ids['QUIMIO']]));

        $this->client->request('POST', '/admin/anamnese/thesaurus/' . $termo->getId() . '/excluir', ['_token' => $this->token($crawler)]);
        $this->assertResponseRedirects('/admin/anamnese/thesaurus');
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM anamnese_termo'));
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM classificacao_estudo WHERE termo_id IS NOT NULL OR termo_manual = 1'));
        $this->assertSame(7, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM classificacao_estudo'), 'Nada do catálogo é apagado');
    }

    public function testCatalogoMostraOTermoDeCadaItem(): void
    {
        $this->termo('Covid', 'COVID ####');

        $html = $this->client->request('GET', '/admin/anamnese/catalogo')->html();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Termo (thesaurus)', $html);
        $this->assertMatchesRegularExpression('/COVID 2020.*?Covid.*?automático/s', $html);
    }

    public function testTelasERelatoriosMostramOsNomesReunidos(): void
    {
        $this->termo('Covid', 'COVID ####');
        $paciente = (new \App\Entity\Paciente())->setNomeCompleto('PACIENTE X')->setSexo('F')->setDataNascimento(new \DateTime('1960-01-01'))->setCodPaciente(1);
        $this->em->persist($paciente);
        $this->em->flush();
        $data = (new \DateTime('-1 month'))->format('Y-m-d 09:00:00');
        foreach (['COVID 2019', 'COVID 2020', 'COVID 2021', 'DENGUE'] as $nome) {
            $this->conn->insert('exame_classificacao', [
                'paciente_id' => $paciente->getId(), 'classificacao_id' => $this->ids[$nome], 'cod_agendamento' => 'AG1',
                'cod_paciente' => 1, 'data_exame' => $data, 'primeiro_visto_em' => $data, 'ultimo_visto_em' => $data,
            ]);
        }

        $html = $this->client->request('GET', '/admin/anamnese')->html();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('(3 nomes)', $html);

        // Endereço de um ano abre o termo
        $html = $this->client->request('GET', '/admin/anamnese/item?item=' . $this->ids['COVID 2021'])->html();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('reúne 3 nomes do catálogo, contados como um item só — Covid 2019, Covid 2020, Covid 2021', $html);

        $html = $this->client->request('GET', '/admin/anamnese/item/relatorio?item=' . $this->ids['COVID 2020'])->html();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Nomes reunidos', $html);

        $html = $this->client->request('GET', '/admin/anamnese/relatorio')->html();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Itens consolidados', $html);
        $this->assertStringContainsString('<strong>Covid</strong> (Covid 2019, Covid 2020, Covid 2021)', $html);
    }

    private function termo(string $nome, string $padroes): AnamneseTermo
    {
        $termo = (new AnamneseTermo())->setNome($nome)->setCategoria('comorbidade')->setPadroes($padroes);
        $this->em->persist($termo);
        $this->em->flush();
        static::getContainer()->get(\App\Service\AnamneseThesaurusService::class)->aplicarRegras();

        return $termo;
    }

    private function salvar(string $url, array $dados): void
    {
        $crawler = $this->client->request('GET', $url);
        $this->client->request('POST', $url, $dados + ['_token' => $this->token($crawler)]);
    }

    private function token($crawler): string
    {
        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    /** @return list<string> */
    private function itensDoTermo(string $termo): array
    {
        return $this->conn->fetchFirstColumn('SELECT c.nome FROM classificacao_estudo c JOIN anamnese_termo t ON t.id = c.termo_id WHERE t.nome = ? ORDER BY c.nome', [$termo]);
    }
}
