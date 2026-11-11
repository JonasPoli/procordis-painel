# Design

## Context

Motivação em proposal.md. Antes, o painel (`AnamneseEstatisticaService::obterPainel()`) e o panorama do item
(`AnamneseItemService::calcular()`) contavam o conjunto exato de comorbidades de cada paciente. A matriz de associação
(`AnamneseAnaliseService::associacao()`) já contava pares "com ou sem outras". As listas têm 8 linhas na tela e nos A4.

## Goals / Non-Goals

**Goals:** uma contagem única, reutilizável, por "tem todos os itens"; números iguais aos da matriz; mesmo tamanho
de lista (8 linhas) para não mexer na paginação dos relatórios A4.

**Non-Goals:** mudar a matriz de associação, a multimorbidade ou os perfis clínicos (perfis continuam mutuamente
exclusivos de propósito).

## Decisions

**1. Duplas e trios separados, não uma lista única por número de pacientes.** Contando "tem todos", um trio nunca
tem mais pacientes que cada uma das suas duplas, então uma lista única ordenada só por pacientes seria feita só de
duplas. Por isso o painel mostra as 5 duplas e os 3 trios mais frequentes (8 linhas, como antes), com um rótulo
separando os grupos. No panorama do item, as linhas são as outras comorbidades de quem tem o item: 4 com uma outra
(o par com o item) e 4 com duas outras (o trio com o item).

**2. Contagem por subconjuntos.** `AnamneseEstatisticaService::combinacoesFrequentes()` gera, para cada paciente, as
combinações de tamanho 2 e 3 (ou 1 e 2 no panorama) contidas no seu conjunto de comorbidades e soma. Com cerca de 10
comorbidades no catálogo, um paciente gera no máximo 45 duplas e 120 trios; com ~5 mil pacientes é instantâneo.
Desempate pela chave dos ids, para a ordem ser estável entre execuções.

**3. Base do percentual.** Painel e A4: pacientes com anamnese no período (igual ao "% sobre o total" da matriz).
Panorama do item: pacientes com o item (igual ao "% condicional" da matriz, linha = o item). A linha "Nenhuma outra
comorbidade" sai do panorama porque só existe no conjunto exato; o gráfico "Outras comorbidades de quem tem o item"
continua mostrando quantos não têm outra.

## Risks / Trade-offs

- [Quem estava acostumado com os números antigos (156, 5,9%) estranha a mudança] → textos dizem a regra e a base, e
  `docs/anamnese.md` registra a decisão com o exemplo.
- [Um paciente aparece em várias linhas, e a soma passa de 100%] → dito nos textos da tela e dos A4.
