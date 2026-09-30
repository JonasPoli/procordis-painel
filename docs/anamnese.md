# Anamnese (ClassificacaoEstudo)

Captura, histórico e painel estatístico da anamnese que a API Procordis expõe em `ClassificacaoEstudo`.

## O que a API entrega

| Endpoint | Uso |
|---|---|
| `GET /api/ClassificacaoEstudo` | Catálogo de itens (diabetes, hipertensão, vacina Covid dose N…) |
| `GET /api/Paciente/ClassificacoesEstudo` | Itens marcados em cada exame num período, paginado (`pagina`, `tamanhoPagina`, `total`, `itens`) |
| `GET /api/Paciente/{codPaciente}/ClassificacoesEstudo` | Itens de todos os exames de um paciente |

Cada item: `codPaciente`, `nomePaciente`, `codAgendamento`, `dataExame` (`dd/mm/aaaa hh:mm`), `codClassificacao`, `classificacao`, `codigo`, `tipo`.

> **Confirmar com a API real:** os nomes dos parâmetros de período e o formato de data enviados
> (`dataInicio`, `dataFim`, `pagina`, `tamanhoPagina`, formato `d/m/Y`) estão nas constantes de
> `src/Service/ProcordisAnamneseApiClient.php`. Se a API usar outros nomes, ajuste só ali.
> O significado de `tipo` também precisa ser confirmado (no exemplo é sempre 0).

## Onde fica guardado

| Tabela | Conteúdo |
|---|---|
| `api_captura` | JSON bruto de cada resposta (anamnese e agendamentos). Resposta idêntica à anterior não é regravada, só `ultima_verificacao_em` muda. Permite reprocessar tudo no futuro. |
| `classificacao_estudo` | Catálogo. `categoria` (comorbidade, fator de risco, vacina, medicação, sintoma, outros) é nossa, sugerida pelo nome e editável no admin; `ativo` tira o item das estatísticas. |
| `exame_classificacao` | Um item marcado num exame. `primeiro_visto_em`, `ultimo_visto_em`, `removido_em` formam o histórico. Nada é apagado. |
| `paciente` | Agora com `cod_paciente` (código Medware, único). É a chave de identificação — nunca mais o nome. |
| `paciente_historico` | Cada mudança de nome, CPF, celular, sexo ou nascimento, com valor anterior e novo. |
| `anamnese_sync_execucao` | Registro de cada sincronização (modo, status, resumo, avisos). |

Sexo, idade, procedimento, convênio e médico vêm do agendamento (`/Medware/Agendamento/Listar`), ligado ao exame por `cod_agendamento`.

## Sincronização

```bash
php bin/console app:anamnese:sync --modo=incremental   # catálogo + últimos 7 dias (--dias=N)
php bin/console app:anamnese:sync --modo=completo      # todo o histórico + remoções + agendamentos
```

- **Incremental:** relê a janela recente (a anamnese pode ser editada depois do exame) e liga exames a agendamentos já existentes.
- **Completo:** relê todo o histórico em janelas anuais (`--desde=2000-01-01`), marca `removido_em` no que sumiu da API,
  atualiza os agendamentos dos últimos 30 dias (`--dias-agendamentos`) e busca até 300 dias de agendamentos antigos que
  ainda faltam (`--max-dias-backfill`). A carga de agendamentos antigos avança noite após noite até completar.
- **Proteções:** só marca remoções quando o período foi lido por inteiro; se a API devolver um período vazio onde havia dados,
  não remove nada e registra aviso. Uma execução em andamento bloqueia outra (até 6 h; `--forcar` ignora).
- **Modo simulação:** com a integração em simulação, as execuções geram dados simulados (pacientes `SIM-…`).
  `--regerar-simulacao` apaga e recria essa base.

### Cron do servidor

```cron
# Anamnese: novos dados às 7h, 12h e 18h; completo às 2h
0 7,12,18 * * * cd /caminho/do/projeto && flock -n var/anamnese.lock php bin/console app:anamnese:sync --modo=incremental --origem=cron >> var/log/anamnese-sync.log 2>&1
0 2 * * *       cd /caminho/do/projeto && flock -n var/anamnese.lock php bin/console app:anamnese:sync --modo=completo --origem=cron >> var/log/anamnese-sync.log 2>&1
```

O fuso do cron deve ser o de São Paulo (ou ajuste as horas para UTC).

## Painel

`/admin/anamnese` — somente dados agregados, sem nome de paciente. Filtros: período, sexo, faixa etária, tipo de atendimento, procedimento e médico.

- Indicadores: pacientes e exames com anamnese, cobertura do preenchimento, comorbidades por paciente, multimorbidade, novos diagnósticos
- Prevalência por item (cor por categoria) e tabela completa
- Multimorbidade e combinações mais frequentes de comorbidades
- Evolução mensal das principais comorbidades
- Pirâmide etária, prevalência por sexo e mapa de calor por faixa etária
- Novos diagnósticos por mês e por item
- Vacina Covid: dose mais alta declarada por faixa etária
- Perfil por tipo de atendimento e por procedimento
- Qualidade do preenchimento: exames com/sem anamnese por mês, por procedimento e por médico

**Como ler:** prevalência = pacientes com o item marcado em algum exame do período ÷ pacientes com anamnese no período.
A ausência da marcação não prova ausência da condição.

### Análises complementares

Calculadas por `AnamneseAnaliseService` sobre a mesma base e os mesmos filtros do painel (`AnamneseEstatisticaService::base()`).
Aparecem no painel e no relatório A4; cada bloco tem um link **CSV** (`/admin/anamnese/exportar-tabela.csv?tabela=…`).

| Bloco | O que mostra | `tabela=` |
|---|---|---|
| Perfil cardiovascular registrado | Pacientes por nº de condições (hipertensão, diabetes, infarto, AVC, cateterismo, angioplastia), por faixa etária, sexo e procedimento | `perfil-cardiovascular` |
| Evolução entre anamneses | Pacientes com anamnese em 2+ dias: sem alteração, com novo item, tempo entre anamneses, itens que mais aparecem depois | `evolucao-itens` |
| Multimorbidade por faixa etária / por sexo | Distribuição, média, mediana, % com 2+ e 4+ comorbidades | `multimorbidade-faixa`, `multimorbidade-sexo` |
| Perfil por sexo e faixa etária | Prevalência dentro de cada grupo sexo × faixa, para as 6 condições ou qualquer item | `sexo-faixa` |
| Perfis clínicos agrupados | 7 grupos mutuamente exclusivos (vale o primeiro critério atendido) | `perfis` |
| Carga clínica por procedimento | Pacientes, exames, média, mediana, 2+, 4+ e histórico cardiovascular | `carga-procedimento` |
| Matriz de associação | Nº de pacientes, % condicional e % do total entre as 10 comorbidades mais frequentes | `associacao` |
| Evolução mensal da carga clínica | Média de comorbidades e % com 2+, 4+, histórico e condição cardiovascular | `carga-mensal` |
| Novos registros por tipo | Novos diagnósticos separados em cardiometabólicos, cardiovasculares, infecciosos/históricos e outros | `novos-tipo` |
| Qualidade e completude | Campos sem preenchimento, anamneses repetidas, possíveis duplicidades, várias doses de vacina, itens parecidos | `qualidade` |

Regras:

- **Paciente** conta uma vez no período (itens = união dos exames do período; sexo e faixa etária do exame mais recente);
  **exame** = agendamento com anamnese; **ocorrência de item** = uma marcação num exame.
- **Condições e tipos são reconhecidos pelo nome do item** (`AnamneseCondicoes`), porque o catálogo não tem campo estruturado para isso.
  O painel lista os itens considerados em cada condição e avisa quando nenhum é encontrado. Se o catálogo usar outro nome
  (ex.: "PRESSÃO ALTA"), ajuste os padrões nessa classe. "Derrame" **não** é somado ao AVC: só é sinalizado para conferência.
- Histórico cardiovascular = infarto, AVC, cateterismo ou angioplastia registrado.
- Grupos com menos de 10 pacientes ficam sem percentual nos mapas de calor e marcados com `*` nas tabelas; meses com menos de 20 pacientes não são medidos.
- Nada é diagnóstico nem escore clínico, e nenhum dado é corrigido automaticamente.

### Panorama de um item

`/admin/anamnese/item?item=<id>` — escolha uma comorbidade (ou qualquer item) no seletor do painel, ou clique no nome do item na
tabela completa. Usa os mesmos filtros e compara os pacientes **com** o item registrado com os pacientes **sem** ele
(`AnamneseItemService`): prevalência e posição, idade e sexo, outras comorbidades, prevalência por faixa etária e sexo, evolução
mensal e novos registros, itens que mais aparecem junto, combinações, perfis clínicos, prevalência por procedimento, tipo de
atendimento e médico, e consistência do registro entre anamneses. Avisa quando o catálogo tem item de nome parecido
(a contagem pode estar dividida). Versão A4 em `/admin/anamnese/item/relatorio` e CSV em `/admin/anamnese/item/exportar.csv`.

## Relatório A4

`/admin/anamnese/relatorio` (botão **Gerar relatório (A4)** no painel, com os mesmos filtros) abre em nova aba um documento formal
em folhas A4 para imprimir ou salvar em PDF. É uma página independente (`templates/admin/anamnese/relatorio.html.twig`), com CSS próprio
(`assets/styles/relatorio-a4.css`) e gráficos em SVG desenhados no servidor (`relatorio-graficos.html.twig`), sem JavaScript.
Cada `<section class="folha">` é uma página de 297 mm: ao mudar o conteúdo de uma folha, confira se ainda cabe.

Outras telas: `/admin/anamnese/catalogo` (categorias e itens ativos), `/admin/anamnese/sincronizacao` (execuções, totais e botões
para rodar agora) e `/admin/anamnese/exportar.csv` (uma linha por exame, paciente pseudonimizado, 0/1 por item — para pesquisa e
para cruzar com os ECGs pelo `cod_agendamento`; inclui médico, nº de comorbidades, nº de condições cardiovasculares e histórico cardiovascular). O prontuário do paciente mostra a anamnese exame a exame e as alterações cadastrais.

## Implantação

1. `php bin/console doctrine:migrations:migrate` (cria as tabelas, preenche `paciente.cod_paciente` a partir de `PAC-<código>` e cria índices)
2. `./build.sh` (novas classes Tailwind)
3. Com a integração em modo real: `php bin/console app:anamnese:sync --modo=completo` uma vez (carga inicial)
4. Configurar o cron acima

## Servidor RunCloud

- Projeto: `/home/runcloud/webapps/procordis-painel`
- PHP de linha de comando: `/RunCloud/Packages/php83rc/bin/php`
- No `.env.local`: `PHP_CLI_BINARY=/RunCloud/Packages/php83rc/bin/php` (usado pelo botão "Rodar agora")
- Build: `PHP=/RunCloud/Packages/php83rc/bin/php ./build.sh`
- Cron (RunCloud → Server → Cron Job, usuário `runcloud`), fuso de São Paulo:

```cron
0 7,12,18 * * * cd /home/runcloud/webapps/procordis-painel && flock -n var/anamnese.lock /RunCloud/Packages/php83rc/bin/php bin/console app:anamnese:sync --modo=incremental --origem=cron --env=prod >> var/log/anamnese-sync.log 2>&1
0 2 * * * cd /home/runcloud/webapps/procordis-painel && flock -n var/anamnese.lock /RunCloud/Packages/php83rc/bin/php bin/console app:anamnese:sync --modo=completo --origem=cron --env=prod >> var/log/anamnese-sync.log 2>&1
```

Se o servidor estiver em UTC (`date` mostra `UTC`), use `0 10,15,21 * * *` e `0 5 * * *`.
