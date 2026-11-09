# Produto e regras do MVP

## Objetivo do piloto

Dar à criança uma visão clara do que fazer agora, como saber se terminou e quanto ganhou. Dar ao responsável um lugar para configurar a rotina, revisar entregas e conciliar a mesada. O piloto é bem-sucedido quando a família consegue operar um ciclo completo no celular, conferir os pontos e registrar o pagamento sem recorrer à planilha para calcular valores.

## Papéis e permissões

| Papel | Acesso |
| --- | --- |
| Operação | Provisiona família, adultos, relações e vínculos via comando administrativo auditado no ambiente da aplicação. Sem cadastro público |
| Responsável | Cadastra filhos da família, gerencia tarefas e conversão, reabre ocorrências, revisa entregas e fecha ou baixa contas dos filhos vinculados |
| Filho ou filha | Vê apenas o próprio painel, tarefas, avisos, avaliações, extrato e mesadas. Envia e reenvia as próprias entregas |

O parentesco é um rótulo no cadastro. Use pai, mãe, padrasto, madrasta, avô, avó e responsável. O acesso depende do vínculo explícito adulto–filho. Parentesco por si só não autoriza acesso. A operação habilita o primeiro adulto e os demais responsáveis. Ao cadastrar um filho, o adulto precisa confirmar que está autorizado a fazê-lo e fica vinculado ao perfil. Para WhatsApp, cada conta ativa tem um número único normalizado. Registrar nome, data de nascimento e WhatsApp próprio apto da criança. Alterações de número passam por verificação e revogação das sessões anteriores.

## Tarefas e prazos

Cada tarefa tem título, critério de aceite em linguagem observável, filho ou filhos atribuídos, pontos inteiros positivos, periodicidade, data de início, regras de prazo e modo de foto (desativada, opcional ou obrigatória). Foto opcional é o padrão proposto. O editor mostra as próximas datas e o valor equivalente antes de salvar.

| Periodicidade | Configuração proposta |
| --- | --- |
| Diária | Dias da semana selecionáveis e um horário para seg–sex e outro para sáb–dom. Uma ocorrência por dia selecionado |
| Semanal | Um dia da semana fixo por tarefa. A semana local começa na segunda |
| Mensal | Dia 1–31 ou último dia. Dia inexistente recua para o último dia real do mês |
| Anual | Mês e dia fixos. Em 29/2, recua para 28/2 em ano comum |

Em todas as periodicidades, o horário de dia útil ou fim de semana é escolhido pela data real do vencimento. Horários podem coincidir. Cada ocorrência abre no início do período local (dia, segunda, primeiro dia do mês ou 1º de janeiro) e vence na data e no horário configurados. Na ativação ou após edição, gera-se a ocorrência do período corrente se o prazo ainda estiver à frente, respeitando a data de início. A disponibilização efetiva começa no momento da ativação, se posterior ao início do período. Desativar ou editar vale para ocorrências futuras ainda não abertas. Ocorrências abertas preservam o enunciado, os pontos, a conversão e o horário originais. O responsável pode ajustar uma data específica por reabertura após o vencimento. Mudanças ficam auditadas.

Para rotinas escolares, use dias selecionados e horário aproximado, com o contexto no critério. Feriados e férias podem ser tratados com uma pausa por intervalo de datas futuras, aplicada pelo dia local original do vencimento a ocorrências que ainda não abriram. O MVP não inclui calendário escolar, prazo relativo a um evento, várias cobranças por dia na mesma tarefa nem metas flexíveis de N vezes por semana. Múltiplos dias fixos podem ser cobertos por uma tarefa diária nos dias escolhidos. Para uma rotina com vários pontos de checagem e um pagamento por dia, mantenha uma ocorrência diária e escreva as checagens no critério.

## Entrega, revisão e reabertura

1. A criança abre o card, toca em **Concluir**, vê o critério e pode tirar ou escolher uma foto conforme a configuração. Pode escrever até 255 caracteres e tocar em **Enviar para revisão**.
2. O servidor recebe e valida a entrega antes do prazo. A partir do instante do vencimento, bloqueia novos envios. Um envio aceito continua na fila de revisão após vencer.
3. O responsável recebe aviso no app e WhatsApp, se habilitado. Abre a entrega, confere critério, foto e comentário e escolhe **Aprovar** ou **Pedir ajuste**. Aprovação pode ter mensagem. Pedido de ajuste requer mensagem concreta. Limite proposto para ambos os perfis de mensagem é 255 caracteres.
4. Aprovação credita os pontos uma vez. O pedido de ajuste permite reenviar até o prazo vigente. Após esse prazo, o responsável define uma nova data e hora, com motivo, para reabrir uma ocorrência vencida que ainda não foi aprovada e não está em revisão. Uma reabertura estende somente aquela ocorrência. Pode coexistir com a ocorrência do dia seguinte, identificada pela data original.
5. A criança recebe o resultado no app e, quando habilitado, por WhatsApp com link autenticado. Códigos e avisos saem do número fixo do DAG. O comentário completo fica no app por padrão. O envio de texto livre também pelo WhatsApp depende da validação de privacidade no piloto.

Estados visíveis: **A fazer**, **Em revisão**, **Precisa de ajuste**, **Aprovada** e **Prazo encerrado**. A indicação **Reaberta** acompanha o novo prazo. Um pedido de ajuste continua no histórico mesmo depois de expirar. Sem desconto por atraso ou por ajuste. Tarefa aprovada encerra a ocorrência.

## Pontos, conversão e mesada

- Proposta de tabela para o MVP: uma taxa linear por família, com versões por vigência e histórico. Exemplo de configuração: 1 ponto vale R$ 0,10. Uma tarefa de 5 pontos vale R$ 0,50. O editor aceita centavos inteiros por ponto e exibe o equivalente em reais.
- A ocorrência guarda a taxa vigente quando abre e o número de pontos. Ao aprovar, o extrato recebe esses valores. Uma alteração na tabela vale para ocorrências que ainda vão abrir. Somar centavos por crédito permite reunir taxas de vigências diferentes sem recalcular o passado.
- O painel separa **Pontos disponíveis**, **Mesada a receber** e **Já recebi**. Pontos disponíveis são os créditos aprovados que ainda não entraram em conta. A geração manual captura todos os créditos disponíveis do filho naquele instante, em uma conta **Pendente**, e o saldo disponível volta a zero. Pode haver mais de uma conta pendente. A confirmação de pagamento muda a conta para **Paga**, registra quem e quando, e preserva os pontos ganhos depois do fechamento. O pagamento acontece fora do DAG.
- Na virada para o dia 1, no fuso da família, o fechamento automático separa créditos aprovados antes do corte e ainda sem conta. Não cria conta vazia. A competência automática é o mês anterior. Se houver indisponibilidade, recupera em ordem as competências vencidas desde a ativação do perfil e aplica o corte original. O fechamento manual processa primeiro os cortes automáticos já vencidos, depois gera uma conta com o saldo atual. Entregas em revisão na virada entram no mês local em que forem aprovadas. Uma aprovação exatamente na virada pertence ao novo mês.
- O gráfico mensal agrupa pontos e valores ganhos pela data local de aprovação. Contas mostram data de geração, competência do fechamento automático quando houver, créditos incluídos e status. O valor **Pago no mês** usa a data local da baixa. O saldo **A receber** soma contas pendentes, mesmo que sejam de meses anteriores.

### Exemplo de virada

31/10 às 21h: uma entrega é aprovada por 100 pontos, equivalentes a R$ 10,00. Na virada, o sistema cria uma mesada pendente de R$ 10,00. Em 01/11 às 09h, outra entrega é aprovada por 20 pontos. A criança vê 20 pontos disponíveis e R$ 10,00 a receber. Ao marcar a primeira mesada como paga, os 20 pontos continuam disponíveis. Se o processo automático rodar às 10h por indisponibilidade, os 20 pontos ainda ficam fora do fechamento de outubro.

## Fora da primeira versão

Cadastro público, login infantil mediado, importação automática do Sheets, transferência via Pix, pagamentos parciais, estorno pela interface, tarefas sem recorrência, Web Push, envio offline, rankings e metas de poupança automáticas. O bônus das planilhas pode ser cadastrado como tarefa mensal com avaliação manual.
