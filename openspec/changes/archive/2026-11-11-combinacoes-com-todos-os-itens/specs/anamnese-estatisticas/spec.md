# Spec Delta

## ADDED Requirements

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
