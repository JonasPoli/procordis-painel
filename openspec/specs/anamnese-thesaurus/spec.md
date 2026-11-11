# anamnese-thesaurus Specification

## Purpose

Permite cadastrar termos da anamnese (ex.: "Covid") com os vários nomes que o catálogo da API usa para a mesma
condição, para que as estatísticas tratem esses nomes como um item só, sem alterar os dados importados.

## Requirements

### Requirement: Cadastro de termos do thesaurus
O painel SHALL ter uma tela de thesaurus da anamnese, só para usuário autenticado, que lista os termos com nome,
categoria, regras de nome e os itens do catálogo ligados a cada um, e permite criar, editar e excluir termos.
O nome do termo SHALL ser único.

#### Scenario: Criar um termo
- **WHEN** o administrador cria o termo "Covid" com categoria Comorbidade
- **THEN** o termo aparece na lista do thesaurus com seus itens ligados

#### Scenario: Nome repetido
- **WHEN** o administrador tenta criar um segundo termo chamado "Covid"
- **THEN** o termo não é gravado e a tela avisa que o nome já existe

#### Scenario: Acesso sem login
- **WHEN** um visitante sem login abre a tela do thesaurus
- **THEN** o painel exige autenticação

### Requirement: Regras de nome do termo
Cada termo SHALL aceitar regras de nome, uma por linha, comparadas ao nome do item sem diferença de maiúsculas,
acentos e pontuação. Na regra, `#` SHALL valer um dígito e `*` qualquer trecho, e a regra SHALL valer para o nome
inteiro.

#### Scenario: Ano novo de Covid
- **WHEN** o termo "Covid" tem a regra `COVID ####` e o catálogo recebe o item "COVID 2027"
- **THEN** o item "COVID 2027" passa a pertencer ao termo "Covid"

#### Scenario: Grafia diferente
- **WHEN** o catálogo tem o item "Covid-2020"
- **THEN** a regra `COVID ####` também o reconhece

#### Scenario: Vacina não entra
- **WHEN** o catálogo tem o item "VACINA DA COVID DOSE 1"
- **THEN** a regra `COVID ####` não o reconhece e a vacina continua separada

### Requirement: Escolha manual prevalece sobre a regra
O administrador SHALL poder ligar ou desligar manualmente qualquer item de um termo. A escolha manual SHALL
prevalecer sobre as regras de nome, inclusive nas sincronizações seguintes, até o administrador devolver o item
ao modo automático.

#### Scenario: Ligar manualmente
- **WHEN** o administrador marca o item "DERRAME" no termo "AVC", que não tem regra para esse nome
- **THEN** "DERRAME" passa a pertencer ao termo "AVC"

#### Scenario: Desligar um item que a regra pegaria
- **WHEN** o administrador desmarca "COVID 2019" no termo "Covid"
- **THEN** "COVID 2019" volta a ser um item separado e a sincronização seguinte não o religa

#### Scenario: Voltar ao automático
- **WHEN** o administrador devolve "COVID 2019" ao modo automático
- **THEN** a regra `COVID ####` volta a ligá-lo ao termo "Covid"

### Requirement: Um item pertence a no máximo um termo
Cada item do catálogo SHALL pertencer a no máximo um termo. Quando as regras de dois termos reconhecem o mesmo
item, SHALL valer o termo cadastrado primeiro, e a tela do thesaurus SHALL avisar o conflito.

#### Scenario: Regras em conflito
- **WHEN** as regras dos termos "Covid" e "Infecções" reconhecem "COVID 2021" e "Covid" foi cadastrado antes
- **THEN** "COVID 2021" pertence a "Covid" e a tela mostra o aviso de conflito

### Requirement: Excluir termo
Excluir um termo SHALL desligar seus itens, que voltam a contar separados e ao modo automático, sem apagar
nenhum item do catálogo nem nenhuma marcação de exame.

#### Scenario: Exclusão
- **WHEN** o administrador exclui o termo "Covid"
- **THEN** COVID 2019 … COVID 2026 voltam a aparecer como itens separados e as marcações continuam guardadas

### Requirement: Termo de cada item no catálogo
A tela do catálogo SHALL mostrar o termo de cada item e se o vínculo é automático (por regra) ou manual.

#### Scenario: Catálogo com termo
- **WHEN** o administrador abre o catálogo
- **THEN** a linha de "COVID 2022" mostra o termo "Covid" e a indicação "automático"

### Requirement: Sugestões de itens parecidos
A tela do thesaurus SHALL listar pares de itens do catálogo com nomes possivelmente equivalentes que ainda não
estão no mesmo termo, só como sugestão; nada é juntado sem decisão do administrador.

#### Scenario: Sugestão sem junção automática
- **WHEN** o catálogo tem "QUIMIO" e "TRATAMENTO QUIMIO" fora de qualquer termo
- **THEN** o par aparece nas sugestões e os dois continuam separados

### Requirement: Termo inicial da Covid
A implantação SHALL criar o termo "Covid", categoria Comorbidade, com a regra `COVID ####`, ligando os itens
COVID 2019 a COVID 2026 já existentes.

#### Scenario: Depois da implantação
- **WHEN** a mudança é implantada
- **THEN** o thesaurus tem o termo "Covid" com 8 nomes, e as vacinas da Covid continuam fora dele
