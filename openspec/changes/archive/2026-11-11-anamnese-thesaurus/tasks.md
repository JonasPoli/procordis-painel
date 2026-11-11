# Tasks

## 1. Modelo e regra de nome

- [x] 1.1 Criar a entidade `AnamneseTermo` (+ repositório) e os campos `termo` e `termoManual` em `ClassificacaoEstudo`; verificar com `doctrine:schema:validate` no banco de teste
- [x] 1.2 Escrever a migration (tabela `anamnese_termo`, colunas, FK com ON DELETE SET NULL, termo "Covid" com a regra `COVID ####` e UPDATE dos itens COVID AAAA) com `down()` que desfaz tudo; verificar com migrate/migrate prev/migrate num banco local com os 25 itens de produção
- [x] 1.3 Implementar a regra de nome (`#` dígito, `*` qualquer trecho, nome inteiro, comparação normalizada) e verificar com teste unitário: "COVID 2027" e "Covid-2020" entram, "VACINA DA COVID DOSE 1", "COVID" e "COVID 20211" não

## 2. Resolução do termo e consolidação nas estatísticas

- [x] 2.1 Criar `AnamneseThesaurusService` com `mapa()` (manual prevalece; senão primeiro termo cuja regra casa; conflitos registrados) e `aplicarRegras()` (espelha `termo_id` dos itens automáticos); verificar com teste de banco cobrindo vínculo por regra, vínculo manual, exclusão manual e conflito
- [x] 2.2 Consolidar `AnamneseEstatisticaService::catalogo()`, `base()` e `linhasExportacao()` pelo mapa (chave = menor id do termo, nome/categoria do termo, `variantes`, dose comum) e expor a conversão de id; verificar com teste de banco: prevalência com uma linha "Covid", multimorbidade 2 (não 3), sem novo diagnóstico de Covid no segundo ano, CSV com uma coluna "Covid"
- [x] 2.3 Fazer `AnamneseItemService::obter()` converter `?item=` de qualquer nome do termo para a chave e `AnamneseCondicoes::itensPorCondicao()` testar também os nomes originais; verificar com teste: panorama de COVID 2021 mostra "Covid" com todos os anos, e o panorama de Diabetes lista Covid uma vez nas outras comorbidades
- [x] 2.4 Chamar `aplicarRegras()` no fim da sincronização do catálogo e depois de `garantirClassificacoes()`; verificar com o `AnamneseSyncServiceTest` (item "COVID 2027" vindo da API sai ligado ao termo)
- [x] 2.5 Incluir `anamnese_termo` no backup/restauração (ordem antes de `classificacao_estudo` e na limpeza); verificar lendo `TABELAS_ORDEM` e com a suíte completa verde

## 3. Telas do admin

- [x] 3.1 Rotas e templates do thesaurus (lista com itens, regras, conflitos e sugestões; formulário com checkboxes dos itens; excluir; voltar ao automático), com CSRF; verificar com teste de controller: exige login, cria termo, nome repetido é recusado, desmarcar grava exclusão manual, excluir devolve os itens
- [x] 3.2 Coluna "Termo" (automático/manual) no catálogo e link para o thesaurus no painel, no catálogo e no menu; verificar no teste de controller que o catálogo mostra o termo
- [x] 3.3 Mostrar os nomes reunidos: tabela de prevalência do painel (título com os nomes), aviso no panorama do item e na versão A4 do item, e lista de itens consolidados nas definições do relatório A4 do painel; verificar com o teste do relatório A4 (folhas e contagem de gráficos inalteradas) e com o panorama renderizado
- [x] 3.4 Atualizar `docs/anamnese.md` (thesaurus, regra de nome, consolidação, rotas novas) e verificar que as rotas citadas existem em `debug:router`

## 4. Integração

- [x] 4.1 Rodar `APP_ENV=test ./vendor/bin/phpunit`, `lint:twig templates` e `lint:container`; tudo verde
- [x] 4.2 Conferir no servidor local com uma cópia do catálogo de produção: `/admin/anamnese`, `?item=14`, `?item=15`, `?item=7`, thesaurus e catálogo sem erro, Covid em uma linha
- [x] 4.3 Abrir o PR no GitHub (JonasPoli/procordis-painel) e fazer o merge na main

## 5. Produção (wab001)

- [x] 5.1 Backup antes: cópia do `.env.local` (`.env.local.bak-20261111-serverversion`) e dump das tabelas `classificacao_estudo` e `doctrine_migration_versions` em `var/backup/`; conferir que os arquivos existem e não estão vazios
- [x] 5.2 Corrigir `serverVersion` para `11.1.6-MariaDB` no `.env.local` e conferir que `doctrine:migrations:status --env=prod` volta a funcionar
- [x] 5.3 Deploy como runcloud: `git pull`, `doctrine:migrations:migrate --env=prod`, `PHP=/RunCloud/Packages/php83rc/bin/php ./build.sh` (sem `composer install`: o composer.lock não muda); conferir a migration gravada
- [x] 5.4 Conferência depois em painel.procordis.org.br: thesaurus com "Covid" e 8 nomes, painel com uma linha "Covid", `?item=14`, `?item=15` e `?item=7`, e o próximo sync sem erro no `var/log/anamnese-sync.log`
  (11/11: termo "Covid" com 8 itens no banco; serviços das telas rodados no servidor com os dados reais — Covid em uma linha, 43,5% em 12 meses; `?item=14`/`15` com a Covid uma vez só e `?item=7` abrindo "Covid"; rotas novas respondendo 302 para o login; sync #173 com sucesso; o Jonas conferiu o painel logado e mandou print das combinações já consolidadas.)
- [x] 5.5 Registrar no sistema WAB (cliente 212, projeto 82): ticket com as horas e resposta programada ao cliente com print e URL
  (Ticket #13423, 4 h não faturáveis, registro #13768, concluído. Por decisão do Jonas em 11/11, registrado como ticket aberto por ele, sem e-mail ao cliente.)

## Workflow follow-up

- Arquivar o change com sincronização das specs depois da conferência em produção.
