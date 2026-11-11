<?php

namespace App\Tests\Service;

use App\Entity\AnamneseTermo;
use App\Entity\ClassificacaoEstudo;
use App\Service\AnamneseThesaurusService as T;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Thesaurus da anamnese: regra de nome, vínculo automático × manual, conflitos e espelho do termo_id.
 */
class AnamneseThesaurusServiceTest extends KernelTestCase
{
    /**
     * @dataProvider nomesCovid
     */
    public function testRegraCovidPorAno(string $nome, bool $esperado): void
    {
        $this->assertSame($esperado, T::casa('COVID ####', $nome), $nome);
    }

    public static function nomesCovid(): array
    {
        return [
            'ano futuro' => ['COVID 2027', true],
            'hífen e minúsculas' => ['Covid-2020', true],
            'espaços sobrando' => ['  covid   2019 ', true],
            'sem espaço' => ['COVID2021', true],
            'vacina' => ['VACINA DA COVID DOSE 1', false],
            'sem ano' => ['COVID', false],
            'ano com 5 dígitos' => ['COVID 20211', false],
            'outro texto antes' => ['HISTÓRICO COVID 2020', false],
        ];
    }

    public function testRegrasSemAcentoECuringa(): void
    {
        $this->assertTrue(T::casa('Hipertensao', 'HIPERTENSÃO'));
        $this->assertTrue(T::casa('QUIMIO*', 'QUIMIOTERAPIA'));
        $this->assertFalse(T::casa('QUIMIO*', 'TRATAMENTO QUIMIO'));
        $this->assertTrue(T::casa('*QUIMIO*', 'TRATAMENTO QUIMIO'));
        $this->assertNull(T::regex('*'), 'Regra sem letra ou número pegaria o catálogo inteiro');
        $this->assertNull(T::regex(' ## '));
    }

    public function testManualPrevaleceEConflitoFicaComOPrimeiroTermo(): void
    {
        $termos = [
            2 => ['nome' => 'Infecções', 'padroes' => ['COVID*', 'DENGUE']],
            1 => ['nome' => 'Covid', 'padroes' => ['COVID ####']],
        ];
        $itens = [
            4 => ['nome' => 'COVID 2019', 'termo_id' => null, 'termo_manual' => true],   // excluído à mão
            5 => ['nome' => 'COVID 2020', 'termo_id' => null, 'termo_manual' => false],  // duas regras: vale o termo 1
            12 => ['nome' => 'DENGUE', 'termo_id' => null, 'termo_manual' => false],
            13 => ['nome' => 'DERRAME', 'termo_id' => 1, 'termo_manual' => true],        // ligado à mão
            14 => ['nome' => 'DIABETES', 'termo_id' => 2, 'termo_manual' => false],      // espelho velho: a regra decide
        ];

        $v = T::resolver($itens, $termos);

        $this->assertSame(['termo' => null, 'origem' => 'manual', 'casam' => [1, 2]], $v[4]);
        $this->assertSame(['termo' => 1, 'origem' => 'regra', 'casam' => [1, 2]], $v[5]);
        $this->assertSame(2, $v[12]['termo']);
        $this->assertSame(['termo' => 1, 'origem' => 'manual', 'casam' => []], $v[13]);
        $this->assertNull($v[14]['termo']);
    }

    public function testConsolidacaoEEspelhoNoBanco(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);

        $termo = (new AnamneseTermo())->setNome('Covid')->setCategoria('comorbidade')->setPadroes("COVID ####\n\nCOVID ####");
        $em->persist($termo);
        $ids = [];
        foreach (['COVID 2021', 'COVID 2019', 'DIABETES', 'COVID 2027', 'VACINA DA COVID DOSE 1'] as $i => $nome) {
            $c = (new ClassificacaoEstudo())->setCodClassificacao(100 + $i)->setNome($nome)->setCategoria(ClassificacaoEstudo::sugerirCategoria($nome));
            $em->persist($c);
            $ids[$nome] = $c;
        }
        $em->flush();
        $this->assertSame(['COVID ####'], $termo->getListaPadroes(), 'Regras repetidas e linhas vazias são descartadas');

        /** @var T $t */
        $t = static::getContainer()->get(T::class);
        $c = $t->consolidacao();
        $chave = $ids['COVID 2021']->getId();
        $this->assertSame([$chave], array_keys($c['grupos']), 'Um grupo, com a chave no menor id');
        $this->assertSame('Covid', $c['grupos'][$chave]['nome']);
        $this->assertSame(['COVID 2019', 'COVID 2021', 'COVID 2027'], $c['grupos'][$chave]['variantes']);
        $this->assertSame($chave, $c['chave'][$ids['COVID 2027']->getId()]);
        $this->assertArrayNotHasKey($ids['VACINA DA COVID DOSE 1']->getId(), $c['chave']);

        // Espelho: os 3 itens automáticos ganham termo_id; rodar de novo não muda nada
        $this->assertSame(3, $t->aplicarRegras());
        $this->assertSame(0, $t->aplicarRegras());
        $conn = $em->getConnection();
        $this->assertSame(3, (int) $conn->fetchOne('SELECT COUNT(*) FROM classificacao_estudo WHERE termo_id = ?', [$termo->getId()]));

        // Exclusão manual não é desfeita pela regra
        $conn->update('classificacao_estudo', ['termo_id' => null, 'termo_manual' => 1], ['id' => $ids['COVID 2019']->getId()]);
        $this->assertSame(0, $t->aplicarRegras());
        $this->assertNull($t->vinculos()[$ids['COVID 2019']->getId()]['termo']);
        $this->assertCount(2, $t->consolidacao()['grupos'][$chave]['itens']);
    }
}
