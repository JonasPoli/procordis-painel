<?php

namespace App\Service;

/**
 * Reconhece, pelo nome do item do catálogo, as condições usadas nas análises complementares da anamnese.
 *
 * O catálogo (ClassificacaoEstudo) não tem um campo estruturado para "condição cardiovascular" nem para
 * "tipo de condição": a correspondência é feita pelo nome, e as telas sempre mostram quais itens foram
 * considerados. Nada aqui altera ou corrige os dados.
 */
final class AnamneseCondicoes
{
    /** Condições do "perfil cardiovascular registrado". */
    public const CONDICOES = [
        'hipertensao' => ['rotulo' => 'Hipertensão', 'padrao' => '/\bHIPERTENS|\bHAS\b|PRESSAO ALTA/', 'exceto' => '/PULMONAR/'],
        'diabetes' => ['rotulo' => 'Diabetes', 'padrao' => '/\bDIABET|\bDM\b|\bDM ?[12]\b/', 'exceto' => '/\bPRE.?DIABET/'],
        'infarto' => ['rotulo' => 'Infarto', 'padrao' => '/\bINFART|\bIAM\b/', 'exceto' => null],
        'avc' => ['rotulo' => 'AVC', 'padrao' => '/\bAVC\b|\bAVE\b|ACIDENTE VASCULAR/', 'exceto' => null],
        'cateterismo' => ['rotulo' => 'Cateterismo', 'padrao' => '/\bCATETERISMO|\bCATE\b/', 'exceto' => null],
        'angioplastia' => ['rotulo' => 'Angioplastia', 'padrao' => '/\bANGIOPLAST/', 'exceto' => null],
    ];

    /** Condições que contam como "histórico cardiovascular registrado". */
    public const HISTORICO_CARDIOVASCULAR = ['infarto', 'avc', 'cateterismo', 'angioplastia'];

    /** Tipos de condição dos novos registros (a ordem é a da legenda). */
    public const TIPOS = [
        'cardiometabolico' => 'Cardiometabólicos',
        'cardiovascular' => 'Cardiovasculares',
        'infeccioso_historico' => 'Infecciosos / históricos',
        'outros' => 'Outros',
    ];

    private const PADRAO_FAMILIAR = '/FAMILIAR|HEREDIT|\bPAIS\b|\bPAI\b|\bMAE\b/';

    private const PADROES_TIPO = [
        'cardiometabolico' => '/\bHIPERTENS|\bHAS\b|PRESSAO ALTA|DIABET|\bDM\b|DISLIPID|COLESTEROL|TRIGLIC|OBESI|SOBREPESO|METABOLIC/',
        'cardiovascular' => '/INFART|\bIAM\b|\bAVC\b|\bAVE\b|ACIDENTE VASCULAR|DERRAME|CATETERISMO|\bCATE\b|ANGIOPLAST|STENT|ARRITMI|FIBRILA|FLUTTER|INSUFICIENCIA CARDIACA|\bICC?\b|MARCA.?PASSO|REVASCULARIZ|SAFENA|MAMARIA|VALV|CORONARI|\bDAC\b|CARDIOPAT|CARDIOMEGAL|ANEURISMA|TROMBO|\bTVP\b|\bTEV\b|SOPRO|ANGINA|CIRURGIA CARDIACA/',
        'infeccioso_historico' => '/COVID|CHAGAS|FEBRE REUMATICA|DENGUE|TUBERCUL|HEPATITE|\bHIV\b|SIFILIS|INFEC|HISTORICO|ANTECEDENTE/',
    ];

    /** Grupos de nomes que costumam designar a mesma condição (apenas para sinalizar conferência). */
    private const SINONIMOS = [
        '/\bAVC\b|\bAVE\b|ACIDENTE VASCULAR|DERRAME/',
        '/\bINFART|\bIAM\b/',
        '/\bHIPERTENS|\bHAS\b|PRESSAO ALTA/',
        '/\bDIABET|\bDM\b/',
        '/QUIMIO/',
        '/RADIO ?TERAPIA|\bRADIO\b/',
        '/MARCA.?PASSO/',
    ];

    /** Maiúsculas, sem acentos e sem pontuação: base para todas as comparações de nome. */
    public static function normalizar(string $nome): string
    {
        $s = mb_strtoupper(trim($nome), 'UTF-8');
        $s = strtr($s, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N',
        ]);

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Z0-9 ]+/', ' ', $s)));
    }

    /**
     * Condição cardiovascular que o nome do item representa, ou null.
     * Itens de histórico familiar não contam: descrevem parentes, não o paciente.
     */
    public static function condicao(string $nome): ?string
    {
        $n = self::normalizar($nome);
        if (preg_match(self::PADRAO_FAMILIAR, $n)) {
            return null;
        }
        foreach (self::CONDICOES as $chave => $c) {
            if (preg_match($c['padrao'], $n) && !($c['exceto'] && preg_match($c['exceto'], $n))) {
                return $chave;
            }
        }

        return null;
    }

    /**
     * Itens do catálogo por condição.
     *
     * @param array<int, array{nome: string, categoria: string}> $catalogo
     *
     * @return array<string, list<int>> chave da condição => ids dos itens (vacinas ficam de fora)
     */
    public static function itensPorCondicao(array $catalogo): array
    {
        $mapa = array_fill_keys(array_keys(self::CONDICOES), []);
        foreach ($catalogo as $cid => $item) {
            if (($item['categoria'] ?? '') === 'vacina') {
                continue;
            }
            $chave = self::condicao($item['nome']);
            if ($chave !== null) {
                $mapa[$chave][] = (int) $cid;
            }
        }

        return $mapa;
    }

    /** Tipo de condição de um item (para os novos registros). */
    public static function tipo(string $nome): string
    {
        $n = self::normalizar($nome);
        if (preg_match(self::PADRAO_FAMILIAR, $n)) {
            return 'infeccioso_historico';
        }
        foreach (self::PADROES_TIPO as $tipo => $padrao) {
            if (preg_match($padrao, $n)) {
                return $tipo;
            }
        }

        return 'outros';
    }

    /**
     * Pares de itens com nomes possivelmente equivalentes. Só sinaliza; não junta nada.
     *
     * @param array<int, array{nome: string, categoria: string}> $catalogo
     *
     * @return list<array{a: int, b: int, motivo: string}>
     */
    public static function itensSemelhantes(array $catalogo): array
    {
        $nomes = [];
        foreach ($catalogo as $cid => $item) {
            if (($item['categoria'] ?? '') !== 'vacina') {
                $nomes[(int) $cid] = self::normalizar($item['nome']);
            }
        }

        $pares = [];
        $ids = array_keys($nomes);
        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $motivo = self::motivoSemelhanca($nomes[$a], $nomes[$b]);
                if ($motivo !== null) {
                    $pares[] = ['a' => $a, 'b' => $b, 'motivo' => $motivo];
                }
            }
        }

        return $pares;
    }

    private static function motivoSemelhanca(string $a, string $b): ?string
    {
        if ($a === '' || $b === '') {
            return null;
        }
        if ($a === $b) {
            return 'Mesmo nome';
        }
        // Nomes que só diferem por números (ex.: "DOSE 1" x "DOSE 2") são itens distintos de propósito
        if (preg_replace('/\d+/', '', $a) === preg_replace('/\d+/', '', $b)) {
            return null;
        }
        // Histórico familiar fala de parentes: não é o mesmo registro que a condição do próprio paciente
        if ((bool) preg_match(self::PADRAO_FAMILIAR, $a) !== (bool) preg_match(self::PADRAO_FAMILIAR, $b)) {
            return null;
        }
        [$curto, $longo] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];
        if (mb_strlen($curto) >= 4 && preg_match('/\b' . preg_quote($curto, '/') . '\b/', $longo)) {
            return 'Um nome contém o outro';
        }
        foreach (self::SINONIMOS as $padrao) {
            if (preg_match($padrao, $a) && preg_match($padrao, $b)) {
                return 'Termos que costumam designar a mesma condição';
            }
        }
        if (min(strlen($a), strlen($b)) >= 6 && levenshtein($a, $b) <= 2) {
            return 'Grafia muito parecida';
        }

        return null;
    }
}
