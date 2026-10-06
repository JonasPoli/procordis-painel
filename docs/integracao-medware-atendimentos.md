# Integração Medware — Painel de atendimentos

> Documento de referência para o painel público de atendimentos (consultas, ECG, ECO, teste ergométrico e demais procedimentos).
> A **seção A** reúne as decisões e verificações da equipe. A **seção B** reproduz as orientações recebidas sobre a API Procordis em 06/10/2026.

---

## A. Decisões e verificações da equipe

### A.1. Arquitetura

| Parte | Projeto | Onde |
|---|---|---|
| Carga, sincronização diária e base de dados | **procordis-painel** (este repositório) | Produção: `ssh runcloud@67.205.162.23` → `~/webapps/procordis-painel` |
| Página pública com gráficos e tabela de histórico | **site** `procordis-site` | https://procordis.org.br/transparencia/atendimentos — o domínio é servido por `~/webapps/app-procordis-producao` (mesmo servidor; `~/webapps/app-procordis` é outra cópia do repositório); local: `~/work/procordis-site` |
| Dados que a API não fornecer (histórico antigo, números consolidados) | Extraídos e sistematizados dos documentos de https://procordis.org.br/transparencia/ | Importados no procordis-painel |

- O painel compartilha com o site **apenas dados agregados** (totais por período e categoria), sem dados pessoais de pacientes.
- Proposta (a confirmar): endpoint JSON público, somente leitura, no painel (precedente: `src/Controller/pub/PainelApiController.php`), consumido e cacheado pelo site.
- Cliente Medware existente: `src/Service/MedwareApiClientService.php` (já usa `/Acesso/login` e `/Medware/Agendamento/Listar`).

### A.2. Verificação do ambiente (06/10/2026)

- `GET https://api.procordis.org.br/api/Acesso` responde `Clinica: ASSOCIACAO PROCORDIS ARARAQUARA`.
- Swagger da instância Procordis: versão **1.5.9.26**. Comparado com o Swagger genérico (`apiclinicas.medware.com.br`, 1.5.9.24), `Agendamento/Listar` e `Agendamento/ListarResumido` são **idênticos** (parâmetros, descrição e respostas).
- Diferenças da 1.5.9.26: novos `GET /ClassificacaoEstudo`, `GET /Paciente/ClassificacoesEstudo`, `GET /Paciente/{codPaciente}/ClassificacoesEstudo`, `POST /Medware/AgendamentoWeb/Salvar`; removidos os webhooks e `POST /mcp`.
- `Paciente/Listar` retorna no máximo 100 pacientes — não serve para extração completa; os dados do paciente vêm dentro de cada agendamento.
- O ambiente local do painel está em **modo simulação, sem token**. Testes autenticados precisam rodar em produção.

### A.3. Estratégia de carga (proposta)

1. **Sondagem** (comando somente leitura em produção, sem exibir dados pessoais): estrutura real do retorno, comportamento de `pageSize` acima de 500, data mais antiga disponível e lista de procedimentos distintos.
2. **Carga histórica** dia a dia; dia que retornar `pageSize` registros é tratado como incompleto e refeito com `pageSize` maior ou subdividido por unidade/médico.
3. **Atualização diária**: dia anterior completo + reconciliação de uma janela móvel (ex.: últimos 30 dias) para capturar mudanças de status e cancelamentos; avaliar `ultimaDataHora` depois de validado.
4. **Upsert por `codAgendamento`**; períodos registrados como concluídos só após persistência integral.
5. **Regra de contagem** (validar com a clínica): `status = -1` (ativo) e `codStatusAgendamento` 4 (Atendido) ou 5 (Liberado), agrupado por código do procedimento e mapeado para categorias (Consulta, ECG, ECO, Ergométrico…).

### A.3.1. Resultado da sondagem em produção (06/10/2026)

- **Histórico disponível desde 08/2012.** Volume por ano (ListarResumido): 2012–2021 entre 140 e 1.600 agendamentos/ano (todos Liberados — carga migrada); 2023: 7,2 mil; 2024: 12,6 mil; 2025: 18,2 mil; 2026 até out.: 13,6 mil.
- **pageSize maior que 500 é respeitado** (30 dias: 500 com pageSize=500, 1.058 com 20.000). Máximo observado em um dia: 104 → a carga dia a dia com pageSize 1000 é completa.
- **Formato real do `Listar`:** lista de objetos; `status` vem como **texto** `'ATIVO'`/`'CANCELADO'` (no `ListarResumido` é `-1`/`0`); flags (`consulta`, `encaixe`, `particular`) vêm como `0`/`-1`; `retorno` como `'SIM'`/`'NÃO'`; datas `dd/MM/yyyy HH:mm`. `codigoTuss` e `codigo` vêm sempre nulos. `medico.especialidade` às vezes traz um nome de pessoa.
- **Procedimentos em uso:** 5 Consulta médica ambulatorial, 39 Consulta de Retorno, 6 Ecocardiograma transtorácico, 7 Eletrocardiograma, 10 Teste Ergométrico, 8 Holter, 9 Mapa, 40 Retorno Mapa, 41 Retorno Holter.
- **Histórico completo carregado em 06/10/2026:** 5.179 dias (08/2012 a 05/10/2026), 51.597 agendamentos. Antes de 24/11/2022 o eco era lançado como **17 Laudo de Ecocardiograma**; a partir dessa data como **6 Ecocardiograma transtorácico** (sem sobreposição: nenhum paciente com os dois no mesmo dia) — ambos na categoria Ecocardiogramas. Até 2022 a base tem praticamente só ecocardiogramas (carga migrada); consultas, ECG, Holter e MAPA aparecem a partir do fim de 2022 e o Teste Ergométrico a partir de 09/2025.
- **Estágios:** realizado é quase sempre 5 (Liberado); 4 (Atendido) é raro. Há muitos agendamentos antigos parados em 1 (Agendado) que não contam.
- `ultimaDataHora` devolveu só agendamentos do mês corrente (janela padrão) — não serve para reconciliar meses antigos; a reconciliação é por recaptura dos últimos dias.
- `ProcedPlanoOp/Listar` sem filtros retorna vazio; o catálogo de procedimentos é montado a partir dos próprios agendamentos.

### A.3.2. Regra de contagem e categorias

- **Atendimento realizado** = `status` ativo **e** `codStatusAgendamento` 4 ou 5, contado pela **data agendada** (`Atendimento::ESTAGIOS_REALIZADOS`).
- Cada procedimento recebe uma categoria automaticamente pela descrição (`AtendimentoConsolidacaoService::classificar`); o admin pode trocar (vira manual).
- Categorias padrão: Consultas (inclui Consulta de Retorno), Ecocardiogramas, Eletrocardiogramas (ECG), Testes ergométricos (esteira), Holter 24h, MAPA, Outros procedimentos e **Retorno de Holter/MAPA (retirada)** — esta oculta no site para não contar o mesmo exame duas vezes (**validar com a clínica**).

### A.3.3. Comandos

| Comando | O que faz |
|---|---|
| `php bin/console app:medware:sondar` | Sondagem somente leitura (estrutura, pageSize, cancelados, profundidade, volume por ano, `ultimaDataHora`, procedimentos). Sem dados pessoais; relatório em `var/medware-sondagem/`. |
| `php bin/console app:atendimentos:capturar-historico --de=2012-08-01` | Carga bruta dia a dia em `atendimento_captura_dia`. Retoma de onde parou; dia truncado é refeito com pageSize maior. |
| `php bin/console app:atendimentos:capturar-historico --recentes=45` | Rotina diária: recaptura os últimos 45 dias até ontem (mudanças de estágio, cancelamentos, reagendamentos). |
| `php bin/console app:atendimentos:capturar-historico --resumo` | Dias capturados, completos e total de registros. |
| `php bin/console app:atendimentos:consolidar [--todos]` | Consolida os dias capturados em `atendimento` (um registro por `codAgendamento`, sem nome/CPF) e no catálogo de procedimentos. |

### A.3.4. Cron (produção)

```cron
# Painel de atendimentos: recaptura 45 dias e consolida (madrugada)
40 4 * * * cd /home/runcloud/webapps/procordis-painel && /usr/bin/flock -n var/atendimentos.lock sh -c '/RunCloud/Packages/php82rc/bin/php bin/console app:atendimentos:capturar-historico --recentes=45 --env=prod --no-debug; /RunCloud/Packages/php82rc/bin/php bin/console app:atendimentos:consolidar --env=prod --no-debug' >> var/log/atendimentos-sync.log 2>&1
# Domingo: recaptura o último ano inteiro (correções tardias)
10 3 * * 0 cd /home/runcloud/webapps/procordis-painel && /usr/bin/flock -n var/atendimentos.lock sh -c '/RunCloud/Packages/php82rc/bin/php bin/console app:atendimentos:capturar-historico --recentes=400 --env=prod --no-debug; /RunCloud/Packages/php82rc/bin/php bin/console app:atendimentos:consolidar --env=prod --no-debug' >> var/log/atendimentos-sync.log 2>&1
```

### A.3.5. API pública (consumida pelo site)

Somente leitura, sem autenticação, só agregados. CORS liberado para as origens em `ATENDIMENTOS_CORS_ORIGENS` (`.env`). `Cache-Control: public, max-age=900`.

| Endpoint | Parâmetros | Retorno |
|---|---|---|
| `GET /api/publico/atendimentos/serie` | `agrupamento=dia\|mes\|ano` (padrão `mes`), `de`, `ate` (`AAAA-MM-DD`; diário limitado a 1.100 dias, padrão últimos 90) | `periodos`, `rotulos`, `series[]` (`slug`, `nome`, `cor`, `total`, `valores[]`), `total`, `pacientes` (distintos por período), `historico`, `atualizadoEm`, `regra` |
| `GET /api/publico/atendimentos/resumo` | — | Totais por categoria em todo o histórico, total geral, primeira/última data |

### A.3.6. Página pública no site

`https://procordis.org.br/transparencia/atendimentos` (projeto `procordis-site`, link destacado no Portal da Transparência). O site lê esta API por um proxy próprio (`/transparencia/atendimentos/dados`) com cache de 15 min e reserva de 7 dias se o painel cair. Mostra o total e os totais por tipo, o gráfico geral de linhas (dia/mês/ano, períodos, liga e desliga de tipos), um gráfico por tipo e a tabela de histórico com download em CSV. O mês/ano em andamento aparece tracejado e fica fora da variação. URL da API no site: `PAINEL_ATENDIMENTOS_API`. Publicado em 06/10/2026 (procordis-site PR #4); no mesmo deploy o `.env.local` do site ao vivo passou de `APP_ENV=env` para `APP_ENV=prod` (backup `.env.local.bak-20261006213159`), o que tirou as páginas de erro em modo debug.

### A.3.6. Admin

Menu **Atendimentos Realizados** (`/admin/atendimentos`): indicadores, gráfico de linhas por tipo (dia/mês/ano), tabela de histórico, situação da captura e botão "Atualizar agora"; telas de **Procedimentos** (tipo de cada procedimento) e **Categorias** (nome, cor, ordem, exibir no site).

### A.4. Pendências

- [x] Rodar a sondagem em produção (06/10/2026, resultado em A.3.1).
- [ ] Confirmar com a clínica: estágios 4 e 5 como realizado, data agendada como referência e se Retorno Holter/MAPA é só retirada do aparelho.
- [x] Montar a tabela procedimento → categoria (automática + ajuste no admin).
- [ ] Verificar se procedimentos da mesma visita viram agendamentos separados e se há procedimentos lançados fora da agenda.
- [x] Definir o formato do compartilhamento painel → site (API pública, A.3.5).
- [ ] Levantar quais documentos da transparência têm números de atendimento e de quais períodos.

---

## B. Orientações para integração com a API Procordis (recebidas em 06/10/2026)

**Data da verificação:** 06/10/2026
**Versão identificada no Swagger do ambiente:** 1.5.9.26
**Endereço base da API:** `https://api.procordis.org.br/api`

### 1. Objetivo da integração

A integração tem como objetivo alimentar o site do Procordis com informações sobre os atendimentos e procedimentos, permitindo apresentar gráficos de evolução e uma tabela histórica de consultas, ECG, ECO, teste ergométrico e demais procedimentos.

O fluxo proposto contempla uma carga inicial do histórico e uma rotina diária para consultar os agendamentos do dia anterior. Para manter o histórico consistente, também será necessário tratar alterações posteriores, como mudanças de status, reagendamentos e cancelamentos.

### 2. Documentação e alcance desta orientação

Referências do próprio ambiente Procordis:

- [Swagger interativo](https://api.procordis.org.br/api/swagger/index.html).
- [Contrato OpenAPI da versão 1.5.9.26](https://api.procordis.org.br/api/swagger/v1.5.9.26/swagger.json).

As informações sobre os parâmetros e os contratos publicados foram conferidas nesses endereços. Não foi realizada consulta autenticada aos dados do cliente.

O código-fonte local disponível é de uma implementação anterior, com diferenças em relação ao contrato publicado. Por isso, os comportamentos internos identificados nesse código são apresentados como referência técnica, e não como confirmação da versão instalada no Procordis.

### 3. Autenticação

O integrador deverá utilizar um acesso autorizado à API. Nas consultas autenticadas, enviar o token no cabeçalho:

```http
Authorization: Bearer {TOKEN}
Accept: application/json
```

O token e as credenciais devem permanecer no servidor da integração, sem exposição no código executado pelo navegador. A emissão e a renovação do token deverão seguir o contrato de autenticação do ambiente.

### 4. Endpoint principal

```http
GET https://api.procordis.org.br/api/Medware/Agendamento/Listar
```

O endpoint é documentado como uma consulta de agendamentos de um período.

#### 4.1. Parâmetros publicados

| Parâmetro | Tipo no Swagger | Finalidade |
|---|---|---|
| `codagendamento` | Inteiro | Código de um agendamento específico. |
| `codStatusAgendamento` | Inteiro | Estágio do agendamento. Ver tabela de status abaixo. |
| `status` | Inteiro | Situação do registro: `-1` para ativo e `0` para cancelado. Quando não informado, a documentação declara o retorno de ambos. |
| `codUnidade` | Inteiro | Código da unidade. |
| `codpaciente` | Inteiro | Código do paciente. |
| `codmedico` | Inteiro | Código do médico. |
| `codEspecialidade` | Inteiro | Código da especialidade médica. |
| `dataInicio` | String | Data inicial no formato `dd/MM/yyyy`. |
| `dataFim` | String | Data final no formato `dd/MM/yyyy`. |
| `ultimaDataHora` | String | Descrito como última data de acesso, no formato `dd/MM/yyyy`. A semântica interna precisa de confirmação na versão instalada. |
| `pageSize` | Inteiro | Valor padrão documentado: `500`. O máximo aceito não está informado. |

O contrato apresenta os parâmetros como opcionais. Para uma extração controlada, recomenda-se informar explicitamente as duas datas.

#### 4.2. Estágios do agendamento

| `codStatusAgendamento` | Descrição |
|---|---|
| 1 | Agendado |
| 2 | Confirmado |
| 3 | Recebido |
| 4 | Atendido |
| 5 | Liberado |
| 6 | Faltou |
| 7 | Suspenso |
| 8 | Reagendado |

**Atenção:** `status` e `codStatusAgendamento` representam conceitos diferentes. O primeiro indica se o registro está ativo ou cancelado; o segundo indica seu estágio no atendimento.

#### 4.3. Regras de período documentadas

- Sem `dataInicio` e `dataFim`, e sem código de agendamento ou paciente, a consulta utiliza o período de um ano antes da data atual até um mês depois.
- Se apenas uma das datas for informada, a outra é calculada com uma janela de 30 dias.
- A documentação não informa uma data mínima de histórico disponível.

A janela padrão não significa que dados anteriores a um ano tenham sido apagados ou estejam indisponíveis. A abrangência histórica efetiva precisa ser verificada com datas explícitas no banco do cliente.

### 5. Respostas às dúvidas sobre volume e sincronização

#### 5.1. Como buscar mais de 500 registros?

O endpoint publica `pageSize` com valor padrão de 500. Não publica parâmetros como `page`, `pageNumber`, `offset` ou um cursor de continuação.

Portanto, não há uma forma documentada de solicitar a próxima página. Não se deve presumir que repetir a consulta retornará os registros seguintes.

Uma estratégia inicial é dividir a carga por períodos menores, por exemplo, meses, semanas ou dias. Se necessário, também podem ser utilizados os filtros documentados de unidade ou médico, desde que as subdivisões cubram todo o escopo e os resultados sejam consolidados por código de agendamento.

**Se uma consulta retornar a quantidade configurada em `pageSize`, deve ser tratada como potencialmente incompleta.** Mesmo que exatamente 500 registros existam no período, a quantidade retornada, isoladamente, não permite distinguir esse caso de um truncamento.

Se o volume continuar excedendo o limite mesmo após a subdivisão, será necessário confirmar um `pageSize` maior ou disponibilizar uma estratégia de paginação/exportação que permita recuperar todos os registros. O contrato atual não documenta filtros por hora para subdividir um único dia.

#### 5.2. Qual é o `pageSize` máximo?

Está confirmado apenas o **valor padrão de 500**. O contrato não informa um valor máximo, nem descreve o tratamento de valores acima do permitido.

Assim, 500 não deve ser apresentado como máximo confirmado. Também não se deve prometer que valores como 1.000, 5.000 ou superiores serão aceitos sem validar a implementação ou realizar um teste autenticado controlado.

#### 5.3. `ultimaDataHora` pode ser utilizado como cursor incremental?

O Swagger descreve o parâmetro como última data de acesso e documenta o formato `dd/MM/yyyy`. Não documenta sua utilização como cursor de paginação.

No código local anterior, o parâmetro aplica um filtro equivalente a:

```sql
AG.DTHRULTMODIFICACAO >= dataInformada
```

Nesse código, a seleção considera a última modificação do agendamento; a ordenação, entretanto, é pela data agendada. O controller também valida o parâmetro como data sem horário.

Essa implementação indica uma possível finalidade de sincronização por alterações, mas precisa ser confirmada na versão 1.5.9.26 do Procordis. O parâmetro não deve ser usado para avançar páginas com base no último item recebido.

Antes de adotá-lo em produção, é necessário validar:

- Qual campo é utilizado como referência de alteração.
- Quais operações atualizam esse campo, inclusive cancelamentos e mudanças de status.
- Se a comparação inclui a data informada.
- Como o filtro se combina com `dataInicio` e `dataFim`.
- Como recuperar integralmente as alterações quando o volume excede `pageSize`.
- Qual horário/fuso é utilizado pelo servidor para definir a data de corte.

Se confirmado o filtro por data, a integração deve trabalhar com sobreposição e atualizar os registros existentes por `codAgendamento`. A marca de sincronização só deve avançar após a conclusão e persistência de toda a extração.

Consultar apenas alterações de agendamentos cuja data agendada seja ontem pode deixar de recuperar correções de registros mais antigos.

#### 5.4. Existe rate limit?

O Swagger consultado não informa uma cota de requisições ou uma política de rate limit para esse endpoint. A ausência dessa informação não comprova que o servidor, proxy ou túnel não aplique restrições.

Não há base suficiente para informar um número de requisições permitido por minuto.

Para a carga inicial, recomenda-se iniciar com consultas sequenciais, evitar paralelismo excessivo e tratar falhas temporárias com novas tentativas espaçadas. Caso o ambiente retorne HTTP 429, registrar o ocorrido e respeitar `Retry-After`, quando presente.

#### 5.5. Até qual data o histórico está disponível?

Não há data mínima de retenção publicada no contrato. A disponibilidade depende dos registros preservados no banco e das regras da versão instalada.

A validação deve identificar o primeiro período necessário para a integração e comparar consultas históricas com os registros existentes no sistema. Uma resposta vazia, isoladamente, não comprova ausência de histórico: filtros, situação do registro e regras de visibilidade também podem influenciar o retorno.

No código local anterior há uma validação de intervalo de até 366 dias. Esse limite não foi confirmado na versão do Procordis. Para a carga inicial, utilizar intervalos menores e explícitos permite controlar melhor volume, desempenho e conferência.

### 6. Procedimentos na mesma visita e contagem dos atendimentos

No modelo observado no código local, cada agendamento possui um procedimento associado. Quando consulta, ECG e ECO são cadastrados individualmente na agenda, a expectativa é que sejam representados por agendamentos com códigos próprios.

Esse comportamento deve ser confirmado com um exemplo real do Procordis. Não se pode garantir, apenas pelo contrato de listagem, que todos os procedimentos executados em uma visita estejam registrados na agenda. Procedimentos lançados somente em guia, faturamento ou outro fluxo podem exigir outra fonte para compor o total realizado.

Para construir os indicadores:

- Validar com a clínica quais estágios representam procedimento realizado. Os códigos 4 e 5 são documentados como Atendido e Liberado, mas a regra de inclusão deve refletir o fluxo da clínica.
- Não contar agendamentos cancelados como procedimentos realizados.
- Consolidar por `codAgendamento`, para evitar duplicidade entre extrações.
- Agrupar pelo código do procedimento, utilizando a descrição para exibição.
- Diferenciar quantidade de procedimentos de quantidade de pacientes ou visitas. Um paciente pode realizar vários procedimentos no mesmo dia.
- Confirmar a data adequada ao indicador. A data agendada é a data prevista; não comprova, por si só, a data efetiva da execução.

O contrato não documenta um identificador de visita que permita agrupar automaticamente vários procedimentos de um mesmo atendimento. Usar somente paciente e data como chave de visita pode unir atendimentos distintos.

### 7. Formato da resposta de `Agendamento/Listar`

O Swagger do Procordis declara sucesso HTTP 200, mas **não disponibiliza o esquema do corpo da resposta desse endpoint**. Portanto, ainda não é possível apresentar um contrato definitivo de campos e tipos da versão instalada.

#### 7.1. Referência preliminar de campos

A relação abaixo foi identificada no código local anterior. Deve ser conferida com uma resposta autenticada da versão do Procordis antes de ser utilizada como contrato de integração.

| Grupo | Campos identificados |
|---|---|
| Agendamento | `codAgendamento`, `codAgenda`, `codUnidade`, `dataHoraMarcacao`, `dataHoraAgendada`, `dataHoraConfirmado`, `dataHoraChegada`, `dataHoraLiberacao`, `dataHoraReferencia`, `codStatusAgendamento`, `intervalo`, `retorno`, `encaixe`, `obs`, `resultadoPronto` |
| `medico` | `codMedico`, `nome`, `cpf`, `numeroConselho`, `ufConselho`, `especialidade` |
| `medicoSolicitante` | `codMedicoSolicitante`, `nomeSolicitante`, `cpfSolicitante`, `numeroConselhoSolicitante`, `ufConselhoSolicitante`, `especialidadeSolicitante` |
| `paciente` | `codPaciente`, `nome`, `nomeSocial`, `dataNascimento`, `matricula`, `telefone`, `email`, `cpf`, `peso`, `altura`, `sexo`, `naturalidade`, `nacionalidade` |
| `procedimentoPlanoOperadora` | `codProcedimento`, `descricaoProcedimento`, `codigoTuss`, `consulta`, `codPlano`, `codOperadora`, `particular`, `descricaoPlano`, `valor` |

Nesse código, a resposta é uma lista de objetos; as datas com horário são formatadas como `dd/MM/yyyy HH:mm`, o nascimento como `dd/MM/yyyy` e o valor como uma string numérica formatada. Campos de data ausentes podem ser apresentados como string vazia.

Tipos, nulabilidade, campos adicionais e representação de flags devem ser confirmados no retorno real. Em particular, o campo `status` está documentado como filtro na versão publicada, mas sua presença no corpo de `Agendamento/Listar` não pode ser afirmada com base no Swagger.

#### 7.2. Documentação definitiva da resposta

Para concluir a documentação do endpoint, será necessário obter uma amostra anonimizada do retorno real e conferir:

- Estrutura de nível superior e objetos internos.
- Nomes exatos e tipos dos campos.
- Tratamento de valores nulos e strings vazias.
- Representação de datas, valores e flags.
- Campos de identificação do procedimento e do cancelamento.
- Comportamento quando a consulta não encontra registros.
- Comportamento quando a consulta alcança `pageSize`.

### 8. Endpoint auxiliar: `Agendamento/ListarResumido`

```http
GET https://api.procordis.org.br/api/Medware/Agendamento/ListarResumido
```

Esse endpoint possui esquema de resposta documentado no Swagger e pode ajudar na conferência de estágios e cancelamentos.

#### 8.1. Parâmetros

| Parâmetro | Finalidade |
|---|---|
| `codAgendamento` | Código de um agendamento específico. |
| `codStatusAgendamento` | Estágio do agendamento. |
| `status` | `-1` para ativos e `0` para cancelados; sem filtro, retorna ambos. |
| `dataInicio` | Data inicial em `dd/MM/yyyy`. |
| `dataFim` | Data final em `dd/MM/yyyy`. |
| `pageSize` | Quantidade máxima de registros solicitada; padrão de 500. O máximo aceito não está informado. |

Sem datas e sem código de agendamento, utiliza o período entre um mês antes da data atual e a data atual. Quando não há código de agendamento e apenas uma data é informada, calcula uma janela de 30 dias.

#### 8.2. Campos documentados

| Campo | Tipo documentado | Descrição |
|---|---|---|
| `codAgendamento` | Inteiro | Identificador do agendamento. |
| `dataHoraAgendada` | String, podendo ser nula | Data e hora previstas em `dd/MM/yyyy HH:mm`. |
| `codStatusAgendamento` | Inteiro | Estágio atual do agendamento. |
| `status` | Inteiro | Situação: ativo `-1` ou cancelado `0`. |

Exemplo ilustrativo, com valores fictícios e estrutura baseada no esquema publicado:

```json
[
  {
    "codAgendamento": 123,
    "dataHoraAgendada": "05/10/2026 08:00",
    "codStatusAgendamento": 4,
    "status": -1
  }
]
```

Esse retorno não contém a identificação do procedimento e, por isso, não substitui a consulta detalhada necessária aos gráficos. Também não possui paginação de continuação documentada.

### 9. Exemplos de consulta

Os exemplos abaixo utilizam os parâmetros publicados. Não representam testes autenticados já executados nem garantem extração completa de períodos com volume acima do limite efetivo.

#### 9.1. Carga histórica por período

```http
GET https://api.procordis.org.br/api/Medware/Agendamento/Listar?dataInicio=01%2F09%2F2026&dataFim=30%2F09%2F2026&pageSize=500
Authorization: Bearer {TOKEN}
Accept: application/json
```

Se a quantidade retornada atingir 500, subdividir o intervalo e verificar a cobertura de todos os resultados antes de concluir a carga.

#### 9.2. Consulta do dia anterior

Considerando uma execução em 06/10/2026, o dia anterior é 05/10/2026:

```http
GET https://api.procordis.org.br/api/Medware/Agendamento/Listar?dataInicio=05%2F10%2F2026&dataFim=05%2F10%2F2026&pageSize=500
Authorization: Bearer {TOKEN}
Accept: application/json
```

Na rotina automática, calcular a data do dia anterior conforme o calendário operacional da clínica e confirmar sua compatibilidade com o servidor. No código local anterior, o período filtra a data agendada.

#### 9.3. Filtrar registros ativos

```http
GET https://api.procordis.org.br/api/Medware/Agendamento/Listar?dataInicio=05%2F10%2F2026&dataFim=05%2F10%2F2026&status=-1&pageSize=500
Authorization: Bearer {TOKEN}
```

Para reconciliar cancelamentos, também é necessário consultar a situação cancelada ou executar uma consulta sem o filtro `status`, conforme o contrato. Extrair somente ativos não informa quais registros anteriormente importados foram cancelados.

#### 9.4. Filtrar um estágio de atendimento

```http
GET https://api.procordis.org.br/api/Medware/Agendamento/Listar?dataInicio=05%2F10%2F2026&dataFim=05%2F10%2F2026&status=-1&codStatusAgendamento=4&pageSize=500
Authorization: Bearer {TOKEN}
```

Esse exemplo seleciona o estágio Atendido. Se a clínica considerar também Liberado, realizar a consulta correspondente ao código 5 ou buscar sem filtro de estágio e aplicar a regra validada na integração. O contrato documenta um inteiro, sem indicar suporte a uma lista de códigos nesse parâmetro.

#### 9.5. Consultar um agendamento específico

```http
GET https://api.procordis.org.br/api/Medware/Agendamento/Listar?codagendamento=123
Authorization: Bearer {TOKEN}
```

#### 9.6. Conferir cancelamentos pelo endpoint resumido

```http
GET https://api.procordis.org.br/api/Medware/Agendamento/ListarResumido?dataInicio=01%2F09%2F2026&dataFim=30%2F09%2F2026&status=0&pageSize=500
Authorization: Bearer {TOKEN}
```

O mesmo cuidado com completude se aplica quando o retorno atingir `pageSize`.

#### 9.7. Consulta por última alteração — sujeita à validação

```http
GET https://api.procordis.org.br/api/Medware/Agendamento/Listar?ultimaDataHora=05%2F10%2F2026&pageSize=500
Authorization: Bearer {TOKEN}
```

Esse exemplo demonstra a sintaxe documentada do parâmetro. Não comprova uma sincronização integral: conforme o Swagger, a ausência de datas e de código de agendamento/paciente aplica uma janela padrão de consulta. Alterações de agendamentos fora dessa janela podem exigir outro escopo de consulta, dependendo da implementação.

### 10. Fluxo recomendado para implementação

#### 10.1. Carga inicial

1. Definir o período histórico necessário e as unidades incluídas.
2. Validar uma consulta pequena e conferir os campos reais.
3. Comparar uma amostra com os registros do sistema, incluindo vários procedimentos na mesma visita.
4. Extrair o histórico em intervalos controlados.
5. Tratar retornos que atinjam `pageSize` como potencialmente incompletos e resolver a subdivisão ou o limite antes de avançar.
6. Inserir ou atualizar os dados por `codAgendamento`.
7. Registrar os períodos concluídos, a quantidade retornada, as falhas e os horários de execução.
8. Conferir os totais de uma amostra antes de disponibilizar os indicadores.

#### 10.2. Atualização diária

1. Executar uma rotina no servidor da integração, independentemente de o site estar aberto.
2. Consultar explicitamente o dia anterior.
3. Atualizar os registros por `codAgendamento`, evitando duplicidade nas reexecuções.
4. Reconciliar mudanças e cancelamentos de períodos anteriores com uma estratégia validada. Consultar somente ontem não mantém todo o histórico atualizado.
5. Registrar sucesso apenas após a extração completa e a persistência dos dados.
6. Reprocessar falhas sem avançar indevidamente a marca de sincronização.

#### 10.3. Apresentação dos indicadores

Os gráficos e a tabela devem utilizar a mesma regra de contagem: situação ativa, estágios definidos pela clínica, código do procedimento e data adequada ao indicador. Recomenda-se apresentar a data da última atualização concluída.

Os indicadores de quantidade podem ser produzidos sem expor dados pessoais dos pacientes no site público.

### 11. Tratamento de respostas e falhas

O contrato de `Agendamento/Listar` publica os seguintes códigos:

| HTTP | Tratamento recomendado |
|---|---|
| 200 | Processar o retorno e conferir estrutura e completude. |
| 400 | Conferir filtros e formatos; registrar a mensagem retornada. |
| 401 | Verificar validade do token e seguir o fluxo de autenticação autorizado. |
| 404 | Examinar o corpo da resposta; não presumir, sem validação, que significa apenas ausência de registros. |
| 500 | Registrar período, parâmetros e horário; tentar novamente de forma controlada quando apropriado. |

Timeouts e respostas de infraestrutura também devem ser registrados. Um período com falha não deve ser marcado como sincronizado.

### 12. Pontos pendentes para concluir o contrato da integração

Antes de considerar a integração validada para produção, é necessário confirmar:

| Ponto | Situação atual |
|---|---|
| Valor padrão de `pageSize` | Confirmado no Swagger: 500. |
| Máximo aceito em `pageSize` | Não informado; requer implementação ou teste autenticado. |
| Recuperação integral de mais de 500 registros | Não há paginação de continuação documentada. |
| Semântica de `ultimaDataHora` | Filtro de modificação identificado apenas no código local anterior; confirmar na versão do cliente. |
| Limite de requisições | Não documentado; confirmar no ambiente. |
| Data mais antiga disponível | Não documentada; conferir o histórico real. |
| Resposta de `Agendamento/Listar` | Sem esquema no Swagger; obter amostra anonimizada e validar campos/tipos. |
| Vários procedimentos na mesma visita | Validar exemplo real e cobertura dos procedimentos lançados fora da agenda. |
| Regra de procedimentos realizados | Definir estágios e data de referência com a clínica. |
| Cancelamentos e alterações posteriores | Definir e testar reconciliação do histórico. |

A carga por períodos e a consulta diária são caminhos iniciais viáveis, mas a garantia de cobertura completa depende da resolução do limite de resultados e da validação do retorno real da versão instalada.
