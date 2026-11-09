# Backlog e critérios de aceite

Ordem sugerida de entrega. Cada etapa precisa ter uma demonstração com dados fictícios em homologação. A configuração da uazapi e o teste de envio pelo número dedicado começam junto com a fundação.

| Ordem | Entrega | Aceite principal |
| --- | --- | --- |
| 1 | Fundação, família e autenticação | Operação cadastra a família e o adulto pelo comando Laravel. Adulto entra com OTP, cadastra um filho e registra autorização. Filho entra no próprio perfil por OTP. Sessões e dados isolados por vínculo |
| 2 | Tarefas e calendário | Adulto cadastra as quatro frequências, horários úteis e de fim de semana, dias e atribuição. Prévia bate com ocorrências. Criança encontra o próximo prazo e filtra por frequência |
| 3 | Entrega e revisão | Criança envia com câmera, galeria ou só texto conforme a tarefa. Adulto aprova ou pede ajuste. Prazo bloqueia envio e adulto pode reabrir. Aprovação gera um crédito |
| 4 | Extrato e mesada | Configurar taxa e consultar versões. Fechamentos manual e automático geram conta pendente e retiram créditos do disponível. Baixa e histórico conciliam pontos, ganhos e pagamentos |
| 5 | PWA e avisos | Instala em Android e iOS. Polling atualiza avisos com app aberto. Códigos e avisos chegam pelo número dedicado e links abrem com autenticação e perfil corretos |
| 6 | Operação e piloto | Deploy e restauração exercitados, retenção operacional e avisos de privacidade revisados, família piloto completa um ciclo de uso |

## Cenários de aceite

| ID | Cenário | Resultado esperado |
| --- | --- | --- |
| A01 | Pedir OTP para número cadastrado, inexistente e revogado | Resposta pública uniforme. Apenas perfil elegível recebe. Código expirado, errado acima do limite, antigo após reenvio ou reutilizado é recusado |
| A02 | Abrir link de outro filho, sem vínculo ou de outra família, incluindo URL de foto | Nenhum dado é devolvido. Após login, apenas o perfil autorizado acessa o link interno |
| A03 | Ativar tarefa diária seg–sex, outra diária todos os dias com horário distinto e uma semanal de domingo | A primeira não aparece sábado ou domingo. A segunda usa o horário de fim de semana nesses dias. A semanal vence domingo no horário correspondente |
| A04 | Recorrência mensal no dia 31 e anual em 29/2 | Mês curto recua para o último dia. Ano comum usa 28/2. Rodar o gerador duas vezes produz uma ocorrência por filho e período |
| A05 | Alterar pontos, taxa ou título de tarefa com ocorrência aberta | Ocorrência aberta e histórico preservam condições. Próxima ocorrência elegível usa versão e taxa novas. Troca de periodicidade não duplica o compromisso no dia da transição |
| A06 | Enviar no instante do prazo ou depois, com prazo reaberto ou em revisão | Prazo vencido bloqueia. Reabertura permite envio até o novo limite. Envio anterior ao limite permanece em revisão. Reabrir em revisão ou depois de aprovada é recusado |
| A07 | Enviar comentário com 255 e 256 caracteres, foto inválida, foto de galeria e captura | 255 aceitos e 256 recusados no servidor. Foto incompatível recebe erro acionável. Evidência privada e sem metadados. Testar seleção de HEIC e orientação de compatibilidade |
| A08 | Dois responsáveis decidem ao mesmo tempo ou a criança dá toque duplo | Uma tentativa por chave, uma decisão por tentativa e no máximo um crédito por ocorrência. Não há aviso de resultado duplicado |
| A09 | Adulto pede ajuste, criança reenvia e adulto aprova | Histórico preserva as duas tentativas. Ajuste não gera pontos. Aprovação usa pontos e taxa congelados. Testar reenvio antes e após reabertura |
| A10 | Gerar duas contas manuais simultâneas ou concorrer com fechamento automático | Cada crédito entra em uma única conta. Prévia desatualizada pede nova confirmação. Retentativa com a mesma chave retorna a conta existente |
| A11 | Scheduler indisponível na virada com aprovações antes, exatamente no corte e depois | Quando recupera, o fechamento anterior inclui apenas créditos anteriores ao corte. Mês sem saldo é registrado sem conta. Aprovação após a virada entra no mês novo |
| A12 | Baixar conta enquanto a criança ganha pontos e repetir a baixa | Estado da conta fica pago com ator e data originais. Novos créditos continuam disponíveis. Série mensal separa ganho e pagamento |
| A13 | Simular desconexão da instância, limite de chamadas e timeout em envio | Aviso interno permanece. A fila aguarda reconexão, respeita o limite e não reenvia automaticamente um envio de resultado incerto. A operação confere o remetente antes de retomar. Retentativas não alteram crédito nem revisão |
| A14 | Abrir PWA offline, trocar de perfil em dispositivo compartilhado e voltar online | Tela offline genérica. Sem acesso ao cache de fotos ou dados da sessão anterior. Polling retoma e atualiza avisos autorizados |
| A15 | Restaurar backup e rodar limpeza de fotos | Pontos, contas e vínculos conciliam. Fotos acessíveis só por autorização. Foto expirada é removida e o histórico continua legível |
| A16 | Usar fluxo completo com leitor de tela e zoom, câmera negada e rede móvel instável | Critérios e estados são legíveis. Há alternativa via galeria. Falha recuperável não apaga o formulário aberto e uma retentativa não duplica envio |
| A17 | Pausar uma tarefa em datas futuras, com ocorrência corrente já aberta | Ocorrência aberta preservada. Nenhuma ocorrência é criada para o intervalo pausado e a rotina volta depois dele |

Testar recorrência, cortes e concorrência com relógio controlado e PostgreSQL real. Usar simulador de WhatsApp para testes automatizados e instância uazapi isolada com números de teste autorizados em homologação. Validar no Chrome Android e Safari iOS, instalado e no navegador. Testar um link aberto pelo próprio WhatsApp com sessão válida e expirada.

## Preparação do piloto

1. Confirmar fuso, horários, dias escolares e responsáveis que receberão avisos. Cadastrar três a cinco tarefas iniciais, com critérios curtos e taxa combinada.
2. Validar com a família o aviso de privacidade, prazo de retenção de fotos, meios de correção e revogação, e quais tarefas permitem foto. Validar as obrigações aplicáveis ao piloto antes de usar dados reais.
3. Parear a instância uazapi com o número dedicado do DAG e confirmar o remetente no aparelho que recebe a mensagem. Exercitar desconexão e recuperação, validar opt-ins e confirmar a entrega ao WhatsApp que a criança usará. Validar se o texto do responsável pode constar no WhatsApp. Enquanto isso, usar o texto completo no detalhe autenticado.
4. Fazer um ensaio de fechamento antecipando o relógio em homologação. Conferir pontos, R$, conta pendente e baixa, incluindo aprovação após a virada. Exercitar o restauro em ambiente separado.
5. No piloto, observar se a criança encontra a próxima tarefa e entende o critério, se o responsável consegue revisar sem ajuda e se ambos distinguem disponível, a receber e pago. Medir falhas de entrega e tempo de revisão com dados operacionais mínimos. Encerrar a validação após conferir um fechamento e um pagamento reais com a família.

## Escolhas propostas para validar

| Tema | Premissa inicial |
| --- | --- |
| Conversão | Taxa linear por família. Usar R$ 0,10 por ponto nos exemplos |
| Foto | Opcional por padrão, configurável por tarefa e desativada nas atividades íntimas |
| Feriados | Pausa manual antecipada e horário fixo aproximado para rotinas relativas |
| Aprovação na virada | Competência pela data de aprovação, com conta anterior preservada |
| Histórico anterior | Início com saldo zero. Se a família quiser continuidade, confirmar ano, totais e status antes de carregar resumos |
| Aviso de revisão | Resultado e link pelo WhatsApp, com texto completo dentro do DAG até validar envio externo |
