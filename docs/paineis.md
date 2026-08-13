Com base na análise minuciosa do vídeo, o sistema apresentado é um **Dashboard de Gestão em Tempo Real de Painel de Senhas**, desenvolvido em Power BI e executado via script em Python.

A narradora explica detalhadamente o funcionamento da ferramenta, que foi instalada em um monitor no corredor central da unidade de saúde. O objetivo é que todos os colaboradores tenham uma visão clara e imediata do cenário da clínica (demandas, gargalos e tempo de espera), sem expor dados sensíveis dos pacientes.

Abaixo, apresento a descrição exaustiva de cada tela, campo e explicação dada no vídeo.

---

### TELA 1: PACIENTES AGUARDANDO ATENDIMENTO (Pré-Atendimento)

**Utilidade:** Monitorar o fluxo de pacientes que já retiraram a senha, mas ainda não iniciaram o exame. Permite à gestão identificar gargalos na recepção ou nas salas de exame em tempo real e agir proativamente.

#### 1. Cabeçalho de Resumo (Indicadores Gerais)

* **SENHAS (Ex: 5):** Quantidade total de pacientes atualmente na fila de espera.
* **MÉDIA TEMPO P/ RECEPÇÃO (Ex: 00:20):** Tempo médio que os pacientes aguardam desde a emissão da senha até serem chamados no guichê.
* **MÉDIA TEMPO DE RECEPÇÃO (Ex: 00:02):** Tempo médio que o colaborador do guichê gasta para realizar o cadastro/atendimento do paciente.
* **MÉDIA TEMPO TOTAL ATENDIMENTO (Ex: 00:53):** Tempo médio total que o paciente está na clínica (desde a emissão da senha até o momento atual de espera).
* **MAIOR TEMPO DE ATEND. (Ex: 02:13):** Mostra o paciente que está há mais tempo aguardando na clínica, servindo como alerta máximo.

#### 2. Tabela de Detalhamento de Pacientes

* **SENHA:** O número do ticket do paciente (Ex: B4, M013).
* **TIPO SENHA:** A fila/especialidade à qual o paciente pertence (Ex: Biópsia, Mamografia).
* **ETAPA ATUAL:** O status do paciente.
* *Exemplos:* "AG RECEPÇÃO" (Aguardando ser chamado no guichê) ou "AG ATENDIMENTO" (Já passou pelo guichê e aguarda ser chamado para a sala de exame).


* **QTD:** Quantidade de exames que aquele paciente irá realizar.
* **ATENDIMENTO:** Número de registro do atendimento no sistema.
* **AN:** *Access Number* (Número de acesso do exame).
* *Explicação da narradora:* Como o painel fica em local público (corredor), **não se pode colocar o nome do paciente por questões de sigilo**. Os campos "Atendimento" e "AN" permitem que a equipe médica identifique o paciente no sistema interno caso precisem intervir.


* **DATA:** Data do atendimento.
* **HORA SENHA:** Horário exato em que o totem emitiu a senha.
* **INÍCIO RECEPÇÃO:** Horário em que o paciente sentou no guichê.
* **FIM RECEPÇÃO:** Horário em que o guichê liberou o paciente.
* **TEMPO TOTAL & SINALIZAÇÃO VISUAL (Ícones):**
* **Check Verde:** O tempo de espera está dentro do aceitável.
* **Alerta Amarelo/Vermelho (!):** Indica que o paciente ultrapassou o limite de tempo configurado.
* *Explicação prática dada:* Uma paciente aguardava há 2 horas e 13 minutos para uma biópsia (ícone amarelo de alerta). A narradora explica que biópsias demandam mais tempo e usam duas salas. Além disso, a paciente poderia estar com a pressão alta e a equipe de enfermagem precisou estabilizá-la antes do procedimento. O painel aponta *onde* está a demora, cabendo à gestão investigar o *porquê* (se é uma intercorrência médica ou ineficiência).


* **GUICHÊ:** Qual posto de atendimento (recepcionista) atendeu o paciente.
* **PROCEDIMENTO:** O exame específico a ser feito (Ex: PAAF - Punção Aspirativa por Agulha Fina, Mamografia).

---

### TELA 2: PACIENTES COM ATENDIMENTOS FINALIZADOS (Pós-Atendimento)

**Utilidade:** Fornecer um panorama histórico de produtividade do dia. Ajuda a gestão a medir a performance das salas, equipamentos e recepcionistas.

#### 1. Cabeçalho de Resumo (Indicadores Gerais)

* **SENHAS (Ex: 13):** Número total de exames finalizados até o momento.
* **MÉDIA TEMPO P/ RECEPÇÃO:** Tempo médio de espera pela recepção dos casos finalizados.
* **MÉDIA TEMPO DE RECEPÇÃO:** Tempo médio gasto no balcão da recepção.
* **MÉDIA ESPERA P/ EXAME/CONSULTA (Ex: 00:31):** Tempo médio que o paciente ficou aguardando na sala de espera *após* sair da recepção até entrar na sala de exame.
* **MÉDIA TOTAL ATENDIMENTO (Ex: 00:39):** Tempo médio geral de permanência do paciente na clínica (início ao fim).
* **MAIOR TEMPO ATENDIMENTO (Ex: 01:10):** O maior tempo que um paciente levou para ter sua jornada concluída no dia.

#### 2. Tabela de Detalhamento de Pacientes

* Os campos são quase idênticos aos da Tela 1 (Senha, Tipo, Etapa, QTD, Atendimento, AN, Datas e Horários da recepção).
* **ETAPA ATUAL:** Todos aparecem como "ATEND FINALIZADO".
* **A grande diferença é o campo "HR EXAME / CONSULTA":**
* *Explicação da narradora:* Este horário marca o momento exato em que a primeira imagem do exame foi capturada no aparelho (primeira captura de imagem). Isso garante a precisão de quando o exame clínico de fato começou.



#### 3. Painéis Gráficos e Analíticos (Parte Inferior da Tela)

A metade inferior desta tela possui seis gráficos cruciais para a inteligência de negócios da clínica:

* **PROCEDIMENTO POR HORA (Gráfico de Barras):** Mostra o volume de exames realizados em cada faixa de horário (Ex: das 06h às 07h foram 7 exames; das 07h às 08h foram 5).
* **TIPO DE SENHA POR HORA (Gráfico de Barras Empilhadas):** Detalha o gráfico anterior, mostrando a proporção de cada exame por hora (Ex: às 06h, foram 4 Biópsias e 3 Mamografias).
* **QTD E MÉDIA EM MINUTOS POR PROCEDIMENTOS (Gráfico Misto - Barras e Linha):** Mostra a quantidade de cada tipo de exame (barras) e a linha do tempo médio de execução.
* *Explicação dada:* A narradora nota que há duas barras para Mamografia. Ela explica que a gestão separou a "Mamografia Convencional" da "Mamografia com Tomossíntese", pois a tomossíntese é feita em menos aparelhos e demora mais tempo, logo, não seria justo misturar as médias estatísticas.


* **TIPO DE SENHA POR APARELHO/CONSULTA (Gráfico de Barras Horizontais):** Mede a produtividade de cada sala/equipamento. (Ex: "MMG Sala 2" fez 3 exames, "US Sala 1" fez 3).
* *Utilidade:* Se uma sala estiver produzindo muito menos que as outras, a gestão pode investigar se há falha no equipamento ou lentidão do técnico/médico operador.


* **ATENDIMENTOS POR GUICHÊ (Gráfico Treemap/Blocos):** Mede a produtividade dos recepcionistas. (Ex: Guichê 2 fez 8 atendimentos, Guichê 4 fez 5).
* *Utilidade:* Identifica gargalos na recepção. Se um colaborador está atendendo muito menos, a gestão intervém para saber se ele está com dificuldades sistêmicas ou problemas de performance.


* **SINALIZAÇÃO ATEND. POR TEMPO TOTAL (Gráfico de Pizza):** Mostra a porcentagem de pacientes cujo tempo total de permanência ficou dentro da meta "Verde" (no exemplo do vídeo, estava 100% verde).

---

### TELA DE CONFIGURAÇÃO DE PARÂMETROS (Regras de Cores/SLA)

No final do vídeo, a narradora mostra como a equipe de tecnologia configurou a inteligência por trás das cores de alerta (Verde, Amarelo, Vermelho).

* **Utilidade:** Garantir que o sistema seja justo na cobrança de tempo, entendendo que cada procedimento tem uma complexidade diferente.
* **Tabela "TEMPO POR PROCEDIMENTO":**
* **CÓDIGO E PROCEDIMENTO:** Lista todos os exames da clínica (Consulta, Ecografia, Mamografia, Biópsia, etc.).
* **INTERVALO (MINUTOS) e COR:** Define a régua de SLA (Service Level Agreement) de cada um.
* *Explicação dada:* Uma Biópsia demora por natureza uns 40 minutos na sala. Portanto, a regra dela permite que o paciente fique Verde até 119 minutos, Amarelo entre 120 e 149 minutos, e Vermelho só acima de 150 minutos.
* Já a Mamografia é um exame rápido. Para ela, o paciente fica Verde apenas até 59 minutos; de 60 a 119 já vira Amarelo, e acima de 120 já acende o Vermelho.



### Resumo da Ferramenta

O painel é uma ferramenta de **Gestão à Vista**. Ele transforma dados brutos do sistema hospitalar em informações visuais e codificadas por cores, permitindo que a liderança da clínica haja instantaneamente ao ver um guichê ocioso, um exame atrasado ou um paciente aguardando além do limite tolerável, tudo isso mantendo a privacidade garantida pela LGPD.