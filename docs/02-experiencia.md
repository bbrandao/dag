# Experiência e identidade

## Direção visual

**Do And Gain · DAG**. Na área da criança, usar **Missões**, **Ganhos** e **Avisos**. Na área do adulto, usar **Tarefas**, **Revisões** e **Mesadas**. A voz é curta, concreta e acolhedora. Ícone proposto para o app: um check inclinado dentro de uma moeda lilás, com traço grosso para leitura em tamanho pequeno.

| Uso | Cor |
| --- | --- |
| Fundo | Creme #FFF9EE |
| Texto | Ameixa #251637 |
| Ação principal | Roxo #5B35C9 com texto branco |
| Destaque de pontos | Amarelo #FFD45C com texto ameixa |
| Aprovação | Verde-claro #BFEFD5 com texto ameixa |
| Ajuste ou prazo encerrado | Coral-claro #FFD4C9 com texto ameixa |

Tipografia proposta: fonte de sistema para textos e títulos com peso alto. Cartões arredondados, ícones simples, espaço entre seções e uma ação primária por tela. Reservar cor forte para a ação e os pontos. Confirmação de aprovação pode ter uma animação curta, respeitando a preferência de movimento reduzido. Comparar progresso apenas com o próprio histórico. [WCAG 2.2](https://www.w3.org/TR/WCAG22/)

## Mapa de telas

| Tela | Conteúdo e ações |
| --- | --- |
| Login | Informar WhatsApp, pedir código, colar ou digitar código, reenviar após espera, voltar para corrigir número. Aviso breve de uso do canal |
| Criança · Missões | Pontos disponíveis no topo. Seção **Hoje**, com próxima missão em destaque, seguida por **Próximas**. Filtros **Todas**, **Diárias**, **Semanais**, **Mensais**, **Anuais**. Ordenação por prazo dentro de cada seção. Em revisão, ajustes e prazos encerrados têm rótulo e agrupamento próprios |
| Criança · Detalhe | Título, até quando, pontos, checklist do critério e ação contextual. Ver comentário do responsável e tentativas anteriores |
| Criança · Entrega | **Tirar foto**, **Escolher foto**, prévia com remover/trocar, comentário com contador e botão de envio. Exibir progresso e preservar o conteúdo em memória numa falha recuperável |
| Criança · Ganhos | Pontos disponíveis, a receber e já recebido. Extrato, evolução dos últimos seis meses e detalhe da conta. Mostrar competência ou data de geração, valor e status |
| Criança · Avisos | Resultado de revisão e reabertura, com link para a ocorrência e marcação de leitura |
| Responsável · Início | Seletor de filho, fila de revisões, pontos disponíveis, valores a receber e pagos, evolução mensal e contagem de aprovadas, em revisão e com prazo encerrado |
| Responsável · Tarefas | Lista com filtros, atribuição por filho, editor de recorrência e prévia de datas e valores. Detalhe da ocorrência permite reabrir e informar motivo |
| Responsável · Revisões | Fila ordenada pelo envio mais antigo. Detalhe com critério vigente, prazo, histórico, foto e comentário. Aprovar ou pedir ajuste |
| Responsável · Mesadas | Extrato, prévia do fechamento, confirmação com pontos e valor, detalhe da conta pendente e botão **Marcar como paga** com confirmação |
| Responsável · Família | Cadastrar filho, dados e preferências, taxa atual e histórico. Controles para notificações, revogação de acesso e contato de privacidade |

### Exemplo de card no celular

> **Arrumar a cama**
>
> Hoje até 08:00 · **+10 pontos**
>
> Cama arrumada, coberta dobrada e toalha estendida.
>
> **Concluir**

- Envio confirmado: “Enviado! Agora é só esperar a revisão.”
- Ajuste: “Tem um detalhe para ajustar” com instrução do responsável, prazo e **Enviar de novo** quando disponível.
- Prazo encerrado: “O prazo terminou. Um responsável pode reabrir a missão.”
- Reabertura: “Você pode enviar até amanhã, às 19:00.”
- Dia vazio: “Tudo certo por hoje. Veja as próximas missões.”
- Mesada pendente: “R$ 10,00 a receber. Seu responsável ainda vai registrar o pagamento.”
- Falha de câmera: “Não conseguimos abrir a câmera. Você pode escolher uma foto da galeria.”
- Sem conexão: “Você está sem internet. Conecte-se para atualizar ou enviar.”

## Acessibilidade e instalação

- Alvo de WCAG 2.2 AA. Botões principais com área de toque de 44 × 44 CSS px ou maior, foco visível, contraste verificado, leitor de tela, navegação por teclado e uso a 200% de zoom. Cor sempre acompanhada de texto ou ícone. Anunciar resultado do envio e erros sem deslocar o foco indevidamente. Gráficos têm tabela com os mesmos dados. [WCAG 2.2](https://www.w3.org/TR/WCAG22/)
- PWA em HTTPS com manifesto, ícones 192/512 e maskable, modo standalone, instruções de adicionar à tela inicial em iOS e promoção de instalação onde suportada. O app funciona no navegador mesmo sem instalação. Testar links do WhatsApp também no navegador interno, com orientação para abrir no navegador padrão quando necessário. [Instalação de PWAs](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable)
- Polling proposto a cada 30 segundos com aba visível, consulta imediata ao voltar ao app ou reconectar e espera progressiva após erro. Fora do app aberto, o canal previsto é WhatsApp. Polling atualiza avisos sem interromper a ação atual. Checagens de prazo e autorização são feitas no servidor.
