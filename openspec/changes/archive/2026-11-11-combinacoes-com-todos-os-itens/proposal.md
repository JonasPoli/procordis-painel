# Proposal

## Why

Em 11/11/2026 o Jonas viu no painel da anamnese "Diabetes · Hipertensão — 156 · 5,9%" em "Combinações mais
frequentes", enquanto a matriz de associação mostrava 48% para o mesmo par. As duas contas estavam certas, mas
medem coisas diferentes: a lista contava o **conjunto exato** (só Diabetes e Hipertensão, nenhuma outra
comorbidade), e a matriz conta **quem tem os dois**, com ou sem outras. Ele decidiu: as combinações contam
sempre "quantos têm os dois", nunca "quantos têm só esse conjunto". Hoje são 866 pacientes com Diabetes e
Hipertensão, e não 156.

## What Changes

- "Combinações mais frequentes" do painel e a Tabela 1 do relatório A4: cada combinação conta os pacientes que
  têm todas as suas comorbidades, com ou sem outras. A lista mostra as 5 duplas e os 3 trios mais frequentes,
  com o % sobre os pacientes com anamnese no período (o mesmo do "% sobre o total" da matriz).
- "Combinações mais frequentes" do panorama do item, a Tabela 3 do A4 do item e o CSV do item: pacientes com o
  item que também têm todas as outras comorbidades da combinação (4 com uma outra, 4 com duas outras), com o %
  sobre quem tem o item (o mesmo do "% condicional" da matriz). Sai a linha "Nenhuma outra comorbidade", que só
  existe no conjunto exato e já aparece no gráfico "Outras comorbidades de quem tem o item".
- Os textos dizem a base do % e que um paciente entra em várias linhas.

## Capabilities

### New Capabilities
(nenhuma)

### Modified Capabilities
- `anamnese-estatisticas`: novo requisito sobre como as combinações de comorbidades são contadas.

## Impact

- Código: `AnamneseEstatisticaService` (combinações do painel e contagem reutilizável), `AnamneseItemService`
  (combinações do panorama e CSV do item); templates do painel, do panorama e dos dois relatórios A4.
- Sem migration nem mudança de banco. Deploy: `git pull` e `./build.sh` no wab001.
- Documentação: `docs/anamnese.md`.
