# Proposal

## Why

O catálogo da anamnese vindo da API tem um item de Covid por ano: COVID 2019, COVID 2020 … COVID 2026 (ids 4 a 11 em
produção, em 11/11/2026), e a cada ano a API pode criar mais um. Hoje cada ano é um item separado em todas as telas.
Com isso, a Covid aparece fatiada em até 8 linhas na prevalência, nas comorbidades associadas do panorama do item
(ex.: `/admin/anamnese/item?item=14`, Diabetes, e `?item=15`, Hipertensão) e nas combinações. Pior: um paciente
que marcou Covid em três anos conta como três comorbidades na multimorbidade, e a Covid de um ano novo vira
"novo diagnóstico" em quem já tinha registrado Covid antes. A Procordis pediu que todos os anos sejam
considerados a mesma comorbidade.

## What Changes

- Novo **thesaurus da anamnese**: cadastro de termos (ex.: "Covid"), cada um com seus vários nomes no catálogo.
  Um item entra no termo por regra de nome (`COVID ####`, que já pega COVID 2027, 2028…) ou por escolha manual
  no admin, que prevalece sobre a regra.
- Tela nova `/admin/anamnese/thesaurus` para criar, editar e excluir termos, ver os nomes de cada um e as
  sugestões de itens parecidos. O catálogo mostra o termo de cada item.
- Todas as estatísticas da anamnese passam a contar o termo como um item só: painel, panorama do item,
  análises complementares, relatórios A4 e os CSVs (inclusive o de pesquisa, com uma coluna por termo).
  O endereço do panorama de qualquer nome do termo abre o termo consolidado.
- As telas avisam quando um item reúne vários nomes (ex.: "Covid — reúne Covid 2019 … Covid 2026").
- A sincronização do catálogo aplica as regras aos itens novos.
- Migração cria o termo "Covid" com a regra `COVID ####` e liga os 8 itens existentes.
- O prontuário do paciente continua mostrando o nome original (é o registro do exame, não estatística).
- Não há mudança na API Medware nem nos dados importados: nada é apagado nem renomeado.

## Capabilities

### New Capabilities
- `anamnese-thesaurus`: cadastro de termos da anamnese, seus nomes e regras, o vínculo (automático ou manual)
  dos itens do catálogo a um termo e a aplicação das regras aos itens novos.
- `anamnese-estatisticas`: como as estatísticas da anamnese (painel, panorama do item, análises, relatórios A4
  e CSV) contam os itens; nesta mudança, a regra de que os nomes de um mesmo termo contam como um item só.

### Modified Capabilities
(nenhuma: o projeto ainda não tinha specs)

## Impact

- Banco: tabela nova `anamnese_termo`; colunas `termo_id` e `termo_manual` em `classificacao_estudo`
  (migration com o termo "Covid"). Backup/restauração passam a incluir a tabela nova.
- Código: `AnamneseEstatisticaService` (catálogo consolidado e mapa item → termo), `AnamneseItemService`,
  `AnamneseCondicoes`, `AnamneseSyncService` (aplica as regras), novo `AnamneseThesaurusService`, entidade
  `AnamneseTermo`, `AnamneseAdminController` (rotas do thesaurus) e templates do painel, item, catálogo e A4.
- Produção: deploy com migration no wab001; antes, corrigir o `serverVersion` do `.env.local` do painel
  (o `doctrine:migrations:status` falha hoje), como nos outros webapps em 09/11/2026.
- Documentação: `docs/anamnese.md`.
