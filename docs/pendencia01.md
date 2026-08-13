# sobre a necessisdade de integraçao do sistema

Prezados, bom dia.

Somos da WAB, empresa responsável pelo desenvolvimento de um novo painel de gestão em tempo real para a Clínica Procordis.

O objetivo do projeto é disponibilizar, tanto para a equipe interna quanto para os pacientes, informações atualizadas sobre o fluxo de atendimento da clínica. O painel deverá apresentar, entre outros indicadores:

pacientes que estão aguardando atendimento;

horário de chegada de cada paciente;

tempo individual de espera;

pacientes que estão aguardando há mais tempo;

etapa atual de cada paciente no fluxo de atendimento;

pacientes em pré-atendimento;

pacientes em atendimento médico;

pacientes cujo atendimento foi finalizado;

quantidade de pacientes aguardando por médico, especialidade, setor ou sala;

tempo médio de espera;

tempo médio de atendimento;

volume de atendimentos realizados;

situação e produtividade dos atendimentos médicos;

histórico dos horários e das etapas percorridas pelo paciente.

Para viabilizar o desenvolvimento, precisaremos integrar o novo painel ao sistema utilizado pela Procordis e desenvolvido pela Midware. Essa integração deverá ser exclusivamente para consulta, sem alteração, exclusão ou inserção de informações no sistema atual.

Solicitamos, portanto, o fornecimento dos seguintes recursos e informações técnicas:

1. Documentação da API

endereço base da API;

relação dos endpoints disponíveis;

parâmetros de consulta;

estrutura das requisições e respostas;

exemplos de requisições;

exemplos de respostas em JSON ou outro formato utilizado;

códigos de retorno e mensagens de erro;

forma de autenticação;

procedimento para geração e renovação de tokens;

limites de requisições por segundo ou por minuto;

regras de paginação e filtros;

versionamento da API;

ambiente de homologação, caso esteja disponível.

2. Credenciais e acessos

credenciais de acesso à API;

identificação do cliente, token, chave ou segredo necessários;

liberação de IP, caso exista restrição;

endereço do ambiente de produção;

endereço do ambiente de homologação;

usuário de consulta com permissão somente de leitura;

procedimento para solicitação ou renovação dos acessos.

As credenciais poderão ser encaminhadas por um canal seguro, separado deste e-mail.

3. Documentação e estrutura dos dados

Precisamos receber o dicionário de dados ou documentação equivalente, contendo:

nome e descrição de cada campo;

tipo de dado;

formato das datas e dos horários;

identificação das chaves e relacionamentos;

códigos de situação utilizados pelo sistema;

descrição de cada status do atendimento;

identificação de pacientes, profissionais, especialidades, setores, salas, agendas e atendimentos;

regras utilizadas para determinar o início e o término de cada etapa;

indicação dos campos que podem ser atualizados durante o atendimento;

informação sobre exclusão lógica, cancelamento, reagendamento ou abandono do atendimento.

Caso não exista uma API que forneça todos os dados necessários, solicitamos a documentação do banco de dados, incluindo o mapa das tabelas, relacionamentos e campos relacionados ao fluxo de atendimento. O acesso, se necessário, deverá ser disponibilizado por meio de um usuário exclusivo, com permissão somente de leitura.

4. Dados necessários para cada atendimento

Precisamos identificar, sempre que estiverem disponíveis no sistema:

identificador interno do paciente;

nome ou nome de exibição do paciente;

número da ficha, senha ou protocolo;

data do atendimento;

horário agendado;

horário de chegada;

horário de confirmação da presença;

horário de entrada na fila;

horário de início do pré-atendimento;

horário de término do pré-atendimento;

horário de início do atendimento médico;

horário de término do atendimento médico;

horário de saída ou encerramento;

situação atual do atendimento;

médico responsável;

especialidade;

setor, sala ou consultório;

prioridade do atendimento;

indicação de encaixe;

informação de cancelamento, ausência, desistência ou abandono;

unidade da clínica, caso o sistema trabalhe com mais de uma unidade.

Para o painel público, utilizaremos apenas informações adequadas à identificação do paciente, como primeiro nome, iniciais, número de senha ou protocolo, conforme a definição da Procordis e as regras de proteção de dados.

5. Atualização em tempo real

O painel precisa trabalhar com informações atualizadas em intervalos de aproximadamente um segundo.

Dessa forma, pedimos que nos informem qual mecanismo de integração é recomendado pela Midware:

consulta periódica à API;

WebSocket;

webhook;

fila de mensagens;

serviço de eventos;

leitura de uma visão ou tabela específica;

outro mecanismo de sincronização em tempo real.

Também precisamos saber se há alguma limitação técnica para consultas frequentes e qual é a frequência máxima permitida sem comprometer o funcionamento do sistema.

Caso seja possível utilizar webhooks ou eventos, precisamos receber a documentação dos eventos relacionados à:

chegada do paciente;

confirmação da presença;

entrada ou mudança de fila;

início e término do pré-atendimento;

chamada do paciente;

início e término do atendimento médico;

mudança de médico, sala, especialidade ou setor;

cancelamento;

ausência;

desistência;

encerramento do atendimento.

6. Segurança e proteção dos dados

A integração será utilizada exclusivamente no projeto da Procordis. A WAB adotará os controles necessários para proteger as credenciais e os dados acessados, respeitando a Lei Geral de Proteção de Dados — LGPD.

Pedimos que nos encaminhem também:

requisitos de segurança exigidos pela Midware;

política de controle de acesso;

necessidade de VPN;

regras de liberação de IP;

requisitos de criptografia;

política de armazenamento ou cache dos dados;

orientações para anonimização ou mascaramento;

restrições para exibição dos dados em painéis públicos;

procedimento para auditoria e rastreamento das consultas.

7. Suporte técnico para a integração

Solicitamos a indicação de um responsável técnico da Midware para auxiliar nas dúvidas relacionadas à integração, especialmente quanto à interpretação dos campos, dos status e das etapas do atendimento.

Pedimos também, se possível, o agendamento de uma reunião técnica entre as equipes da Midware, da WAB e da Procordis para validação do fluxo e dos recursos disponíveis.

O painel precisa estar em funcionamento na inauguração da clínica. Por esse motivo, precisamos receber a documentação, os acessos e a confirmação da viabilidade da integração com a maior brevidade possível.

Caso algum dos dados ou recursos solicitados não esteja disponível, pedimos que nos informem quais alternativas técnicas podem ser oferecidas para que o painel seja desenvolvido dentro do prazo previsto.

Agradecemos desde já e permanecemos à disposição para os alinhamentos necessários.


# o que precisamos fazer
Vamos montrar um sistema local que acessa todos os dados possíveis da medware

O acesso será feito pela api que atualmente estára em api.procordis.org.br

A documentação dos dados existentes está aqui
https://apiclinicas.medware.com.br/api/swagger/index.html

Todos os dados capturados deverão ser possíveis de se listados e visualizados no nosso adm, mas não devem alterar em nada.

Nosso sistema vai fazer a captura de todos os dados possíveis e montar todos os paineis necessários, descritos acima, com os dados.

Como não possuimos acesso, ainda, ao api.procordis.org.br, vamos criar um "gerador de dados" para injetar tados, minuto a mituto, simulando o andamento do processo para que os paineis possam operar adequadamente.

# desenvolva
Todo o painel administrativo, 
Todos os paineis visuais a serem apresentados para o público, cada painel numa rota
Todo o sistema gerador de dados, para simularmos os pacientes chegando, sendo atendidos, fazendo exames, sendo liberados, etc. Leia a documentação para saber tudo que podemos extrair

# Planeje
Faça um plano bem minusiodo, divida em muitas etapas para podemros continuar caso o antigravity de crash. Depois, vamos desenvolver tudo.