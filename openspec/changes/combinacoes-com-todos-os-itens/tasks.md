# Tasks

## 1. Contagem

- [x] 1.1 Criar a contagem de combinações por "tem todos os itens" (duplas e trios a partir do conjunto de cada paciente, ordem por pacientes e desempate estável) e usar no painel (5 duplas + 3 trios, % sobre os pacientes com anamnese); verificar com teste: Diabetes + Hipertensão conta quem tem as duas com ou sem outras e bate com o "% sobre o total" da matriz
- [x] 1.2 Usar a mesma contagem no panorama do item (4 com uma outra + 4 com duas outras, % sobre quem tem o item) e no CSV do item; verificar com o `AnamneseItemServiceTest` atualizado e com o teste de banco (Hipertensão no panorama de Diabetes = % condicional da matriz)

## 2. Telas e documentação

- [x] 2.1 Textos do painel, do panorama e dos dois relatórios A4 dizendo a regra e a base do % (e separando duplas de trios); verificar com o teste do relatório A4 (folhas e gráficos inalterados) e o `lint:twig`
- [x] 2.2 Atualizar `docs/anamnese.md` com a regra das combinações; verificar lendo o trecho

## 3. Integração e produção

- [ ] 3.1 Suíte completa (`APP_ENV=test ./vendor/bin/phpunit`), `lint:twig` e `lint:container` verdes; PR e merge na main
- [ ] 3.2 Deploy no wab001 como runcloud (backup: o commit anterior `86fff2a` é o ponto de volta; sem migration nem composer): `git pull` e `PHP=/RunCloud/Packages/php83rc/bin/php ./build.sh`; conferir com os serviços no servidor que Diabetes + Hipertensão mostra 866 (32,6%) no painel e 85,9% no panorama de Diabetes
- [ ] 3.3 Lançar as horas no ticket #13423 (cliente 212, projeto 82, sem e-mail ao cliente)

## Workflow follow-up

- Arquivar o change com sincronização das specs depois da conferência em produção.
