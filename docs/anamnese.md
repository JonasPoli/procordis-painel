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

`/admin/anamnese` — somente dados agregados, sem nome de paciente. Filtros: período, sexo, faixa etária, tipo de atendimento, procedimento.

- Indicadores: pacientes e exames com anamnese, cobertura do preenchimento, comorbidades por paciente, multimorbidade, novos diagnósticos
- Prevalência por item (cor por categoria) e tabela completa
- Multimorbidade e combinações mais frequentes de comorbidades
- Evolução mensal das principais comorbidades
- Pirâmide etária, prevalência por sexo e mapa de calor por faixa etária
- Co-ocorrência ("quem tem uma, tem a outra?")
- Novos diagnósticos por mês e por item
- Vacina Covid: dose mais alta declarada por faixa etária
- Perfil por tipo de atendimento e por procedimento
- Qualidade do preenchimento: exames com/sem anamnese por mês, por procedimento e por médico

**Como ler:** prevalência = pacientes com o item marcado em algum exame do período ÷ pacientes com anamnese no período.
A ausência da marcação não prova ausência da condição.

Outras telas: `/admin/anamnese/catalogo` (categorias e itens ativos), `/admin/anamnese/sincronizacao` (execuções, totais e botões
para rodar agora) e `/admin/anamnese/exportar.csv` (uma linha por exame, paciente pseudonimizado, 0/1 por item — para pesquisa e
para cruzar com os ECGs pelo `cod_agendamento`). O prontuário do paciente mostra a anamnese exame a exame e as alterações cadastrais.

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
