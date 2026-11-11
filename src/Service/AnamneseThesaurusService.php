<?php

namespace App\Service;

use App\Entity\AnamneseTermo;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Thesaurus da anamnese: diz a que termo pertence cada item do catálogo e monta a consolidação usada
 * nas estatísticas (os itens de um termo contam como um item só).
 *
 * Vínculo de um item:
 * - termo_manual = 1: vale o termo_id gravado (inclusive nenhum) — escolha feita no admin;
 * - senão: o primeiro termo (menor id) com uma regra de nome que reconhece o item; sem regra que reconheça, nenhum.
 *
 * A resolução é feita na leitura, então um item novo da API (ex.: COVID 2027) já sai consolidado.
 * O termo_id dos itens automáticos é só um espelho, regravado por aplicarRegras().
 *
 * Regra de nome: comparada ao nome inteiro, sem diferença de maiúsculas, acentos e pontuação;
 * "#" = um dígito, "*" = qualquer trecho, espaço = espaço opcional.
 */
class AnamneseThesaurusService
{
    /** @var array{termos: array<int, array>, itens: array<int, array>, vinculos: array<int, array>}|null */
    private ?array $dados = null;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** Expressão regular da regra de nome, ou null se a regra não tiver ao menos uma letra ou número. */
    public static function regex(string $regra): ?string
    {
        $n = AnamneseCondicoes::normalizar($regra, '*#');
        if (!preg_match('/[A-Z0-9]/', $n)) {
            return null;
        }
        $r = '';
        foreach (str_split($n) as $ch) {
            $r .= match ($ch) {
                '#' => '[0-9]',
                '*' => '.*',
                ' ' => ' ?',
                default => preg_quote($ch, '/'),
            };
        }

        return '/^' . $r . '$/';
    }

    public static function casa(string $regra, string $nome): bool
    {
        $re = self::regex($regra);

        return $re !== null && preg_match($re, AnamneseCondicoes::normalizar($nome)) === 1;
    }

    /**
     * Cálculo puro do vínculo de cada item.
     *
     * @param array<int, array{nome: string, termo_id: ?int, termo_manual: bool}> $itens  id => item
     * @param array<int, array{nome: string, padroes: list<string>}>            $termos id => termo
     *
     * @return array<int, array{termo: ?int, origem: ?string, casam: list<int>}> origem: 'manual', 'regra' ou null
     */
    public static function resolver(array $itens, array $termos): array
    {
        ksort($termos);
        $regex = [];
        foreach ($termos as $tid => $t) {
            $regex[$tid] = array_values(array_filter(array_map(fn (string $p) => self::regex($p), $t['padroes'])));
        }

        $out = [];
        foreach ($itens as $id => $item) {
            $nome = AnamneseCondicoes::normalizar($item['nome']);
            $casam = [];
            foreach ($regex as $tid => $lista) {
                foreach ($lista as $re) {
                    if (preg_match($re, $nome)) {
                        $casam[] = (int) $tid;
                        break;
                    }
                }
            }
            if ($item['termo_manual']) {
                $tid = $item['termo_id'] !== null && isset($termos[$item['termo_id']]) ? (int) $item['termo_id'] : null;
                $out[$id] = ['termo' => $tid, 'origem' => 'manual', 'casam' => $casam];
            } else {
                $out[$id] = ['termo' => $casam[0] ?? null, 'origem' => $casam ? 'regra' : null, 'casam' => $casam];
            }
        }

        return $out;
    }

    /** @return array<int, array{id: int, nome: string, categoria: string, padroes: list<string>, observacao: ?string}> */
    public function termos(): array
    {
        return $this->dados()['termos'];
    }

    /** @return array<int, array{id: int, cod: int, nome: string, categoria: string, ativo: bool, termo_id: ?int, termo_manual: bool}> */
    public function itens(): array
    {
        return $this->dados()['itens'];
    }

    /** @return array<int, array{termo: ?int, origem: ?string, casam: list<int>}> id do item => vínculo efetivo */
    public function vinculos(): array
    {
        return $this->dados()['vinculos'];
    }

    /**
     * Itens agrupados por termo, para as estatísticas. A chave de cada grupo é o menor id entre os seus itens,
     * para os ids continuarem inteiros positivos e as URLs antigas (?item=<id>) continuarem valendo.
     *
     * @return array{chave: array<int, int>, grupos: array<int, array{termo: int, nome: string, categoria: string, itens: list<int>, variantes: list<string>}>}
     */
    public function consolidacao(): array
    {
        ['termos' => $termos, 'itens' => $itens, 'vinculos' => $vinculos] = $this->dados();

        $porTermo = [];
        foreach ($vinculos as $id => $v) {
            if ($v['termo'] !== null) {
                $porTermo[$v['termo']][] = $id;
            }
        }

        $chave = [];
        $grupos = [];
        foreach ($porTermo as $tid => $ids) {
            sort($ids);
            $k = $ids[0];
            $variantes = array_map(fn (int $id) => $itens[$id]['nome'], $ids);
            natcasesort($variantes);
            $grupos[$k] = [
                'termo' => $tid,
                'nome' => $termos[$tid]['nome'],
                'categoria' => $termos[$tid]['categoria'],
                'itens' => $ids,
                'variantes' => array_values($variantes),
            ];
            foreach ($ids as $id) {
                $chave[$id] = $k;
            }
        }

        return ['chave' => $chave, 'grupos' => $grupos];
    }

    /**
     * Regrava o termo_id dos itens automáticos conforme as regras (espelho para consulta e backup).
     *
     * @return int itens alterados
     */
    public function aplicarRegras(): int
    {
        $this->limpar();
        ['itens' => $itens, 'vinculos' => $vinculos] = $this->dados();
        $conn = $this->em->getConnection();
        $alterados = 0;
        foreach ($itens as $id => $item) {
            if ($item['termo_manual'] || $vinculos[$id]['termo'] === $item['termo_id']) {
                continue;
            }
            $conn->update('classificacao_estudo', ['termo_id' => $vinculos[$id]['termo']], ['id' => $id]);
            $alterados++;
        }
        $this->limpar();

        return $alterados;
    }

    /**
     * Pares de itens com nomes possivelmente equivalentes que ainda não estão no mesmo termo (só sugestão).
     *
     * @return list<array{a: int, b: int, motivo: string}>
     */
    public function sugestoes(): array
    {
        $vinculos = $this->vinculos();
        $pares = AnamneseCondicoes::itensSemelhantes(array_map(fn (array $i) => ['nome' => $i['nome'], 'categoria' => $i['categoria']], $this->itens()));

        return array_values(array_filter($pares, function (array $p) use ($vinculos) {
            $ta = $vinculos[$p['a']]['termo'] ?? null;

            return $ta === null || $ta !== ($vinculos[$p['b']]['termo'] ?? null);
        }));
    }

    /**
     * Grava o termo (nome, categoria, regras) e as mudanças feitas nas caixas de seleção do formulário.
     *
     * Só conta como escolha do usuário o item cuja marcação mudou em relação ao que o formulário mostrava
     * (os itens do termo antes de salvar). As regras novas valem para o resto. Uma marcação que a regra já
     * cumpre fica automática; só vira manual o que contraria a regra.
     *
     * @param list<int|string> $marcados ids dos itens marcados
     *
     * @return array{manuais: int, automaticos: int} itens gravados como escolha manual e itens alterados pela regra
     */
    public function salvar(AnamneseTermo $termo, array $marcados): array
    {
        // Membros antes de salvar (com as regras antigas): é o que o formulário mostrava marcado
        $this->limpar();
        $antes = [];
        if ($termo->getId() !== null) {
            foreach ($this->vinculos() as $id => $v) {
                $antes[$id] = $v['termo'] === $termo->getId();
            }
        }

        $termo->setAtualizadoEm(new \DateTime());
        $this->em->persist($termo);
        $this->em->flush();
        $this->limpar();

        $tid = (int) $termo->getId();
        ['itens' => $itens, 'termos' => $termos] = $this->dados();
        // O que as regras (já as novas) diriam de cada item se ele não fosse manual
        $porRegra = self::resolver(array_map(fn (array $i) => ['termo_manual' => false] + $i, $itens), $termos);
        $marcados = array_flip(array_map('intval', $marcados));
        $conn = $this->em->getConnection();

        $manuais = 0;
        foreach (array_keys($itens) as $id) {
            $marcado = isset($marcados[$id]);
            if ($marcado === ($antes[$id] ?? false)) {
                continue; // sem mudança na caixa: segue a regra ou a escolha manual que já existia
            }
            $regraLiga = $porRegra[$id]['termo'] === $tid;
            if ($marcado) {
                $novo = $regraLiga ? ['termo_id' => $tid, 'termo_manual' => 0] : ['termo_id' => $tid, 'termo_manual' => 1];
            } else {
                $novo = $regraLiga ? ['termo_id' => null, 'termo_manual' => 1] : ['termo_id' => $porRegra[$id]['termo'], 'termo_manual' => 0];
            }
            $conn->update('classificacao_estudo', $novo, ['id' => $id]);
            $manuais += $novo['termo_manual'];
        }

        return ['manuais' => $manuais, 'automaticos' => $this->aplicarRegras()];
    }

    /** Exclui o termo: seus itens voltam a contar separados e ao modo automático. Nada do catálogo é apagado. */
    public function excluir(AnamneseTermo $termo): void
    {
        $this->em->getConnection()->update('classificacao_estudo', ['termo_id' => null, 'termo_manual' => 0], ['termo_id' => $termo->getId()]);
        $this->em->remove($termo);
        $this->em->flush();
        $this->aplicarRegras();
    }

    /** Devolve o item às regras de nome (desfaz a escolha manual). */
    public function voltarAoAutomatico(int $itemId): void
    {
        $this->em->getConnection()->update('classificacao_estudo', ['termo_manual' => 0], ['id' => $itemId]);
        $this->aplicarRegras();
    }

    /** Esquece o que foi lido do banco (depois de gravar termos ou vínculos). */
    public function limpar(): void
    {
        $this->dados = null;
    }

    private function dados(): array
    {
        if ($this->dados !== null) {
            return $this->dados;
        }

        $conn = $this->em->getConnection();
        try {
            $termos = [];
            foreach ($conn->fetchAllAssociative('SELECT id, nome, categoria, padroes, observacao FROM anamnese_termo ORDER BY id') as $r) {
                $termos[(int) $r['id']] = [
                    'id' => (int) $r['id'],
                    'nome' => (string) $r['nome'],
                    'categoria' => (string) $r['categoria'],
                    'padroes' => (new AnamneseTermo())->setPadroes($r['padroes'])->getListaPadroes(),
                    'observacao' => $r['observacao'],
                ];
            }
            $itens = [];
            foreach ($conn->fetchAllAssociative('SELECT id, cod_classificacao, nome, categoria, ativo, termo_id, termo_manual FROM classificacao_estudo ORDER BY id') as $r) {
                $itens[(int) $r['id']] = [
                    'id' => (int) $r['id'],
                    'cod' => (int) $r['cod_classificacao'],
                    'nome' => (string) $r['nome'],
                    'categoria' => (string) $r['categoria'],
                    'ativo' => (bool) $r['ativo'],
                    'termo_id' => $r['termo_id'] !== null ? (int) $r['termo_id'] : null,
                    'termo_manual' => (bool) $r['termo_manual'],
                ];
            }
        } catch (DbalException) {
            // Banco ainda sem a migration do thesaurus (janela do deploy): estatísticas sem consolidação.
            return $this->dados = ['termos' => [], 'itens' => [], 'vinculos' => []];
        }

        return $this->dados = ['termos' => $termos, 'itens' => $itens, 'vinculos' => self::resolver($itens, $termos)];
    }
}
