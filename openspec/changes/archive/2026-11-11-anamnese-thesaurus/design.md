# Design

## Context

Motivação em proposal.md. Estado atual que molda a solução:

- O catálogo (`classificacao_estudo`) tem 25 itens em produção (11/11/2026), dos quais 8 são "COVID 2019" a
  "COVID 2026" (ids 4–11), todos comorbidade. As vacinas da Covid são "VACINA DA COVID DOSE N" (categoria vacina,
  usadas no gráfico de dose).
- Todas as telas estatísticas partem de `AnamneseEstatisticaService::base()`, que lê `exame_classificacao` e
  monta `exames[ag]['cids'][classificacao_id]`, mais `catalogo()` (`id => nome, categoria, dose`).
  `AnamneseAnaliseService` e `AnamneseItemService` consomem essa base. Exceções: `linhasExportacao()` (CSV de
  pesquisa) tem SQL próprio, e a tela do catálogo e o prontuário leem as tabelas direto.
- O sync cria itens novos por dois caminhos: `sincronizarCatalogo()` (ORM) e `garantirClassificacoes()` (SQL
  direto, quando uma marcação chega com código desconhecido).

## Goals / Non-Goals

**Goals:**
- Um ponto único de consolidação, para que painel, panorama, análises, A4 e CSV mudem juntos.
- Anos futuros de Covid entram sozinhos, sem ninguém precisar abrir o admin.
- URLs existentes do panorama (`?item=<id>`) continuam funcionando.

**Non-Goals:**
- Não renomear nem apagar itens do catálogo ou marcações.
- Não juntar AVC/Derrame nem Quimio/Tratamento Quimio: ficam como sugestão para o cliente decidir.
- Não consolidar no prontuário (é o registro do exame).
- Hierarquia de termos (termo dentro de termo) e sinônimos fora do catálogo da anamnese.

## Decisions

**1. Termo como entidade própria, item com `termo_id` + `termo_manual`.**
Tabela `anamnese_termo` (id, nome único, categoria, padroes TEXT com uma regra por linha, observacao, criado_em,
atualizado_em) e, em `classificacao_estudo`, `termo_id` (FK, ON DELETE SET NULL) e `termo_manual` (bool).
Alternativa descartada: guardar só regras e calcular tudo pelo nome. Sem `termo_manual` não haveria como
desligar um item que a regra pega ("desmarcar COVID 2019"). Outra alternativa, apontar um item para outro
item ("COVID 2020 → COVID 2019"), não dá nome próprio ao conceito nem categoria.
`padroes` é TEXT (não JSON) para a migration ser igual em MySQL 8 (local) e MariaDB 11 (produção).

**2. Termo efetivo de um item = escolha manual, senão a regra.**
`AnamneseThesaurusService::mapa()` resolve cada item: se `termo_manual`, vale `termo_id` (inclusive nulo);
senão, o primeiro termo (menor id) cuja regra reconhece o nome. A resolução roda na leitura, então "COVID 2027"
criado por qualquer caminho do sync já sai consolidado na primeira tela. O `termo_id` dos itens automáticos é um
espelho, regravado por `aplicarRegras()` no fim do sync do catálogo, depois de `garantirClassificacoes()` e ao
salvar um termo; serve para consulta e backup, não decide nada.

**3. Regra de nome: curinga simples, nome inteiro, comparação normalizada.**
Nome e regra passam por `AnamneseCondicoes::normalizar()` (maiúsculas, sem acento, pontuação vira espaço), com
`#` → `\d` e `*` → `.*`, ancorada (`^…$`). Por isso `COVID ####` reconhece "Covid-2027" e não reconhece
"VACINA DA COVID DOSE 1" nem "COVID 20211". Expressão regular livre foi descartada: o cadastro é feito por
gente do admin, e um erro de regex quebraria o painel.

**4. Chave do termo nas estatísticas = menor id entre os itens do termo.**
`catalogo()` passa a devolver uma entrada por item consolidado. Para um termo, a chave é o menor id dos seus
itens (4 para a Covid), com `nome`/`categoria` do termo, `termo` (id) e `variantes` (nomes originais). Um mapa
`id do item → chave` é aplicado em `base()` e em `linhasExportacao()` na hora de montar `cids`. Assim os ids
continuam inteiros positivos (nada muda no tipo de `cids`, nos templates e nos CSVs) e `?item=14` continua
sendo Diabetes. `?item=<qualquer nome do termo>` é convertido para a chave pelo `AnamneseItemService`.
Alternativa descartada: id negativo para termos (`-1`), estável mas exigiria mudar validações e URLs.
A chave muda se o item de menor id sair do termo; aceitável, porque só afeta links salvos de um termo editado.

**5. Dose de vacina e condições pelo nome.**
`dose` de um termo = a dose comum a todos os itens, senão nula (um termo que junte doses diferentes sai do
gráfico de dose; o thesaurus avisa ao misturar vacinas). `AnamneseCondicoes::itensPorCondicao()` testa o nome
do termo e, se não reconhecer, os nomes originais, para que um termo "Pressão" com "HIPERTENSÃO" continue no
perfil cardiovascular.

**6. Telas.**
Rotas novas em `AnamneseAdminController` sob `/admin/anamnese/thesaurus` (lista, novo, editar, excluir, voltar
item ao automático), com CSRF como o catálogo. O formulário do termo lista todos os itens do catálogo com
checkbox: marcar um item fora do termo grava vínculo manual; desmarcar um item do termo grava exclusão manual;
o resto segue a regra. As sugestões reaproveitam `AnamneseCondicoes::itensSemelhantes()` sobre o catálogo sem
consolidação. Painel, panorama e A4 mostram os nomes reunidos a partir de `variantes`.

**7. Termo inicial na migration.**
A migration cria o termo "Covid" com a regra `COVID ####` e liga os itens com
`UPPER(TRIM(nome)) REGEXP '^COVID[ -]?[0-9]{4}$'` (sintaxe aceita por MySQL 8 e MariaDB 11). Mesmo sem esse
UPDATE a regra consolidaria na leitura; ele só deixa o `termo_id` coerente desde o primeiro minuto.

## Risks / Trade-offs

- [O catálogo e o painel mudam de número de uma hora para outra: a Covid vira uma linha só e a média de
  comorbidades cai] → avisos de "reúne N nomes" nas telas e no A4; a resposta ao cliente explica a diferença.
- [Regra ampla demais junta o que não devia (ex.: `COVID *`)] → a semente usa `COVID ####`; a tela mostra os
  itens de cada termo antes e depois de salvar; desligar manualmente resolve sem mexer na regra.
- [Resolução na leitura custa a cada requisição] → o catálogo tem dezenas de itens e poucos termos; o mapa é
  calculado uma vez por requisição.
- [`doctrine:migrations:status` falha em produção por `serverVersion=8.0` × MariaDB 11.1.6] → corrigir o
  `.env.local` antes do deploy, com backup, como nos outros webapps em 09/11/2026.

## Migration Plan

1. Merge do PR na main.
2. No wab001, como runcloud: backup do `.env.local` e do banco (só as tabelas da anamnese), correção do
   `serverVersion`, `git pull`, `doctrine:migrations:migrate --env=prod`, `./build.sh`.
3. Conferir: thesaurus com "Covid" e 8 nomes; painel com uma linha "Covid"; `?item=14`, `?item=15` e `?item=7`.

Rollback: `doctrine:migrations:migrate prev` remove a tabela e as colunas (as marcações não são tocadas) e
`git checkout` do commit anterior. Sem migration revertida, apagar o termo "Covid" na tela já devolve os anos
separados.
