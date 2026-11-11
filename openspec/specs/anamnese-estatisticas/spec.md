# anamnese-estatisticas Specification

## Purpose

Define como as estatísticas da anamnese (painel, panorama do item, análises complementares, relatórios A4 e CSV)
contam os itens do catálogo, para que todas as telas usem a mesma regra de contagem.

## Requirements

### Requirement: Nomes do mesmo termo contam como um item
Em todas as estatísticas da anamnese, os itens do catálogo que pertencem a um mesmo termo do thesaurus SHALL
contar como um item só, com o nome e a categoria do termo. Itens sem termo SHALL continuar contando como hoje.

#### Scenario: Prevalência consolidada
- **WHEN** um paciente tem COVID 2020 num exame e COVID 2022 em outro, e ambos pertencem ao termo "Covid"
- **THEN** a prevalência mostra uma linha "Covid" que conta esse paciente uma vez, e não há linhas por ano

#### Scenario: Multimorbidade
- **WHEN** um paciente tem Diabetes, COVID 2020 e COVID 2021 marcados
- **THEN** ele conta com 2 comorbidades (Diabetes e Covid)

#### Scenario: Novo diagnóstico
- **WHEN** um paciente com COVID 2020 numa anamnese anterior recebe COVID 2022 numa anamnese nova
- **THEN** a Covid não é contada como novo diagnóstico desse paciente

#### Scenario: Comorbidades associadas no panorama de outro item
- **WHEN** o administrador abre o panorama de Diabetes
- **THEN** a Covid aparece uma vez só nas outras comorbidades, nos itens que aparecem junto e nas combinações

### Requirement: Panorama de um nome do termo abre o termo
O panorama do item, seu relatório A4 e seu CSV SHALL tratar o endereço de qualquer nome de um termo como o
termo consolidado.

#### Scenario: Endereço de um ano
- **WHEN** o administrador abre o panorama do item COVID 2021
- **THEN** a tela mostra o panorama de "Covid", com todos os anos somados

### Requirement: Transparência da consolidação
As telas e os relatórios SHALL informar quando um item reúne vários nomes do catálogo, listando esses nomes.

#### Scenario: Aviso no panorama
- **WHEN** o administrador abre o panorama de "Covid"
- **THEN** a tela informa que o item reúne COVID 2019 … COVID 2026

#### Scenario: Relatório A4
- **WHEN** o administrador gera o relatório A4 do painel
- **THEN** as definições do relatório listam os itens consolidados pelo thesaurus e os nomes de cada um

### Requirement: CSV de pesquisa consolidado
O CSV de pesquisa (uma linha por exame) SHALL ter uma coluna por item consolidado, com 1 quando qualquer nome do
termo foi marcado no exame.

#### Scenario: Coluna única de Covid
- **WHEN** o administrador exporta o CSV de um período em que há exames com COVID 2021
- **THEN** o cabeçalho tem uma coluna "Covid", sem colunas por ano, e esses exames têm 1 nela

### Requirement: Registro original preservado
A consolidação SHALL valer só para as estatísticas: as marcações importadas da API e o prontuário do paciente
SHALL continuar com o nome original de cada item.

#### Scenario: Prontuário
- **WHEN** o administrador abre o prontuário de um paciente com COVID 2021 marcado
- **THEN** o prontuário mostra "COVID 2021"

### Requirement: Combinações contam quem tem todos os itens
Toda contagem de combinação de comorbidades da anamnese SHALL contar os pacientes que têm todas as comorbidades da
combinação, com ou sem outras, e nunca só os que têm exatamente aquele conjunto. Um paciente SHALL poder entrar em
várias combinações, e cada tela SHALL dizer sobre qual total o percentual é calculado.

#### Scenario: Dupla no painel
- **WHEN** 866 dos 2.660 pacientes com anamnese no período têm Diabetes e Hipertensão, e só 156 deles não têm outra comorbidade
- **THEN** "Combinações mais frequentes" mostra Diabetes + Hipertensão com 866 pacientes e 32,6%

#### Scenario: Mesmo número da matriz de associação
- **WHEN** a matriz de associação mostra, na medida "% sobre o total de pacientes", 32,6% para Diabetes e Hipertensão
- **THEN** a combinação Diabetes + Hipertensão mostra o mesmo 32,6%

#### Scenario: Duplas e trios no painel
- **WHEN** o administrador abre o painel ou o relatório A4
- **THEN** a lista mostra as 5 duplas e os 3 trios de comorbidades com mais pacientes, com o percentual sobre os pacientes com anamnese no período

#### Scenario: Panorama do item
- **WHEN** o administrador abre o panorama de Diabetes, com 1.008 pacientes, dos quais 866 também têm Hipertensão
- **THEN** a combinação Hipertensão mostra 866 pacientes e 85,9% dos pacientes com Diabetes, o mesmo do "% condicional" da matriz

#### Scenario: Combinações de duas outras comorbidades no panorama
- **WHEN** o administrador abre o panorama de um item
- **THEN** a lista mostra as 4 outras comorbidades e as 4 duplas de outras comorbidades mais frequentes entre os pacientes com o item, contando quem tem todas, com ou sem outras
