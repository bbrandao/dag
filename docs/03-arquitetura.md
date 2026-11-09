# Arquitetura e operação

## Escolha de stack

**Laravel + React/Inertia/TypeScript + PostgreSQL**, em um monólito modular. Laravel concentra regras, sessões, autorização, filas e agendamento. React cuida dos estados do formulário de entrega, da câmera, do polling e da interface responsiva. Inertia liga páginas React a rotas Laravel no mesmo domínio. Node entra no build dos assets. A aplicação usa cookies de sessão e proteção CSRF. O starter kit React é uma base de interface. Substituir autenticação padrão pelo fluxo OTP e remover cadastro público e rotas de senha que ficarem sem uso. Fixar versões suportadas do PHP, Laravel, Node e PostgreSQL em lockfiles e imagens após validar o ambiente do VPS. [Starter kits Laravel](https://laravel.com/index.php/starter-kits) · [Filas Laravel](https://laravel.com/framework/docs/queues)

Livewire também serve se a equipe preferir concentrar o desenvolvimento em PHP. Câmera, prévia, recuperação de upload e polling ainda exigem cuidado no cliente. Next.js com Laravel acrescenta um serviço, coordenação de sessão e um contrato entre aplicações. O MVP tem um frontend web e se beneficia do deploy no mesmo domínio.

## Modelo relacional proposto

| Entidade | Campos e invariantes principais |
| --- | --- |
| `families` | UUID, nome, fuso IANA. Sugestão inicial `America/Bahia`, configurado na implantação |
| `users` | UUID, família, nome, papel de acesso, parentesco quando adulto, celular E.164 cifrado, hash de busca com chave e unicidade, status, versão de sessão |
| `children` | Usuário filho, família, data de nascimento cifrada, status |
| `guardian_child_links` | Família, adulto, filho, criado por, autorização registrada, ativo. Um vínculo por par |
| `permissions_records` | Pessoa, finalidade, versão do aviso, responsável que registrou, data, canal e eventual revogação |
| `conversion_rates` | Família, centavos por ponto, vigência UTC e autor. Uma versão por início de vigência. A primeira taxa precede a primeira ocorrência |
| `task_templates` / `task_versions` | Família, status e versões imutáveis com título, critério, pontos, modo de evidência, recorrência, horários e vigência. Atribuições explícitas por filho e versão |
| `task_pauses` | Modelo, família, intervalo inclusivo de datas locais e autor. Afeta apenas ocorrências que ainda não abriram |
| `task_occurrences` | Família, modelo, versão, filho, chave de período, data local original, abre em, prazo original e vigente, estado, pontos e taxa congelados. Única por modelo, filho e chave de período. Chave inclui a periodicidade e a data de início do período |
| `submissions` / `evidence_files` | Ocorrência, tentativa, autor, texto, data e chave de idempotência. Evidência com chave privada, formato, bytes e expiração. No máximo uma foto por tentativa |
| `reviews` | Uma decisão por tentativa. Adulto, resultado, mensagem e data |
| `point_credits` | Crédito imutável, único por ocorrência, com filho, pontos, taxa, valor em centavos e instante de aprovação |
| `settlement_runs` | Registro do fechamento automático, inclusive quando vazio. Único por filho e mês de competência. Guarda corte e conta gerada, quando houver |
| `payouts` / `payout_items` | Conta de um filho, origem manual ou automática, data, competência quando aplicável, totais, situação, autor e data da baixa. Um item por crédito em todo o sistema |
| `otp_challenges` | Identificador opaco, finalidade, número referenciado, MAC do código, expiração, tentativas e consumo |
| `notifications` / `message_outbox` | Avisos por destinatário, lido em, referência. Entregas WhatsApp com chave evento/destinatário/canal, estado, tentativas, ID retornado pela uazapi e próxima tentativa ou consulta |
| `audit_events` | Família, ator, operação, entidade, instante e campos mínimos. Sem fotos, OTP ou mensagens livres |

Usar chaves estrangeiras compostas por família nas relações que cruzam entidades de domínio, além de políticas Laravel. Indexar ocorrências por família/filho/estado/prazo, revisões por família/estado/data e créditos por filho/instante. Usar UTC em instantes (`timestamptz`), calendário local para regras e centavos em inteiros. Validar limites e multiplicação no servidor. Saldo é derivado dos créditos ainda sem item de conta. Guardar o fuso da família estável no piloto.

## Transações e concorrência

- O gerador usa uma chave de período estável e inserção idempotente. Mudanças de periodicidade entram em vigor depois do vencimento da ocorrência corrente da cadência anterior, sem gerar segunda cobrança para o mesmo compromisso no dia da troca. Prévia é calculada e o gerador materializa apenas o período corrente elegível. Após reinício, recompor ocorrências ainda elegíveis e registrar lacunas operacionais, sem criar tarefas retroativas já vencidas. Antes de classificar uma entrega, reabertura ou revisão, bloquear a ocorrência e validar a transição.
- A aprovação grava revisão, crédito, aviso e evento de WhatsApp na mesma transação. Aprovação, fechamento manual e automático adquirem o mesmo bloqueio por filho primeiro. Use o relógio do banco depois de adquirir o bloqueio para gravar a aprovação e o corte manual. Para respeitar a virada, o fechamento automático compara `approved_at < cutoff`. A restrição de crédito por ocorrência e de item por crédito protege contra duplicação mesmo sob retries.
- Na geração manual, a prévia exclui os créditos já elegíveis para cortes automáticos vencidos e fornece uma impressão digital dos créditos esperados. Com o bloqueio adquirido, executar os cortes automáticos vencidos e comparar o saldo restante com a prévia. Se o conjunto mudou, retornar conflito e solicitar nova confirmação. Guardar chave de idempotência por operação, ator e escopo. Repetir a mesma chave e payload retorna o resultado original. Uma segunda baixa mantém a data original.
- Upload entra em área privada temporária. Validar tamanho, tipo real e dimensões, recodificar para formato suportado removendo metadados, e associar a imagem à tentativa na confirmação da entrega. Limpar objetos temporários e órfãos por rotina. Antes de concluir o POST, conferir prazo e estado novamente. Arquivo rejeitado ou upload interrompido preserva a possibilidade de reenviar dentro do prazo.

## Contrato web inicial

Rotas autenticadas verificam o papel, a família e o vínculo com o filho em todo acesso, inclusive arquivos, downloads, polling e links externos. Identificadores UUID não concedem autorização. Recurso de outra família retorna 404. Entrada inválida retorna 422 nas rotas JSON, transição incompatível ou prazo vencido retorna 409, abuso retorna 429. Formulários Inertia usam redirecionamento com erros e retornam ao detalhe após sucesso.

| Método e rota | Dados e resultado |
| --- | --- |
| POST `/auth/otp` | E.164 e opção de permanecer conectado. O botão identifica o WhatsApp como canal de envio. Resposta genérica, sem revelar se o telefone existe |
| POST `/auth/otp/verify` | ID e código. Consome código e inicia sessão no perfil correto. Retoma somente caminho interno autorizado |
| POST `/logout` | Encerra sessão e limpa dados em memória |
| GET `/app/missoes` | Próprias ocorrências. Filtros de periodicidade e status, paginação e prazo ascendente |
| POST `/occurrences/{id}/submissions` | Multipart com comentário, foto opcional ou exigida e chave de idempotência. Retorna tentativa |
| POST `/submissions/{id}/review` | Decisão, mensagem, chave de idempotência. Retorna decisão e crédito, se aprovado |
| POST `/occurrences/{id}/reopen` | Nova data/hora local e motivo. Retorna o prazo atualizado |
| GET `/evidence/{id}` | Serve foto privada após autorização, com `Cache-Control: private, no-store` |
| GET/POST/PATCH `/guardian/tasks` | Listagem, criação e atualização por ID. Atribuições, pontos, horários, critério e recorrência. Usar ação explícita de arquivar |
| GET/POST/PATCH `/guardian/children` | Listagem autorizada, cadastro e edição por ID. Troca de número em fluxo separado de verificação |
| GET/POST `/guardian/conversion-rates` | Histórico e criação de nova vigência |
| GET `/children/{id}/earnings` | Extrato, pontos disponíveis, contas pendentes, valor pago e série mensal. Autorizado para o próprio filho ou adulto vinculado |
| GET `/guardian/children/{id}/payout-preview` | Totais, corte e token de conferência do saldo |
| POST `/guardian/children/{id}/payouts` | Token e chave de idempotência. Gera conta pendente |
| POST `/guardian/payouts/{id}/paid` | Confirma baixa da conta, com ator, data e chave de idempotência |
| GET `/notifications` / POST `/notifications/{id}/read` | Lista paginada por cursor, contagem de não lidos e marcação idempotente. Resposta privada sem cache |

## WhatsApp e login

Usar uma instância uazapi de produção pareada a um único número dedicado ao DAG, de preferência uma conta WhatsApp Business conforme a recomendação do gateway. Todas as famílias recebem códigos e avisos desse remetente. A operação pareia por QR Code ou código de pareamento, guarda acesso ao aparelho da conta e confere o número conectado antes de liberar envios. Homologação usa simulador ou instância isolada com número de teste. Confirmar plano, limites, tratamento de dados e entrega ao tipo de conta do piloto, inclusive se gerenciada por responsável. Registrar opt-in por finalidade e permitir desativar avisos. [Instâncias uazapi](https://docs.uazapi.com/docs/instances) · [Documentação uazapi](https://docs.uazapi.com/) · [Política WhatsApp Business](https://business.whatsapp.com/policy) · [Contas gerenciadas por pais](https://about.fb.com/br/news/2026/03/apresentamos-as-contas-gerenciadas-por-pais-no-whatsapp/)

- O adaptador Laravel chama `POST /send/text` com o header `token` da instância, o número do destinatário em dígitos internacionais sem `+` e o texto em português. O servidor seleciona a instância pela configuração do ambiente. O cliente não informa remetente, instância ou credenciais. O token administrativo fica reservado à operação. Usar fila e limite compartilhado por instância para os avisos, com capacidade reservada para OTP e respeito a `Retry-After`. [Início rápido](https://docs.uazapi.com/docs/getting-started) · [Autenticação](https://docs.uazapi.com/docs/authentication) · [Envio de texto](https://docs.uazapi.com/endpoint/post/send~text)

- Proposta para OTP: seis dígitos aleatórios, duração de cinco minutos, uso único, intervalo de reenvio de 60 segundos, cinco tentativas por desafio e limite adicional por IP e número entre desafios. Novo desafio invalida o anterior. Usar MAC com chave exclusiva e comparação em tempo constante. Renovar o ID da sessão no login. Usar cookies HttpOnly, Secure e SameSite=Lax, proteção CSRF e expiração de sessão. Revogar sessões quando o perfil ou número for desativado.
- A opção **Permanecer conectado** é desmarcada por padrão. Após validar o código, o Laravel emite um cookie persistente de 30 dias quando solicitado. Sair invalida o token, e alterar a versão da sessão impede a restauração por um cookie anterior.
- Em `APP_ENV=local`, é possível solicitar outro código imediatamente. O novo código invalida o anterior, e o prazo de validade e os limites de verificação continuam ativos.
- Criar o desafio na requisição e adiar a chamada de envio para depois da resposta pública genérica. Usar timeout curto e conferir o número pareado antes de enviar. Mensagem: “DAG · Seu código de acesso é 123456. Ele expira em 5 minutos. Não compartilhe.” O código em claro existe no processo que atende a requisição e no canal de entrega, sem persistência na fila ou nos logs do DAG. Em caso de falha, o usuário pode solicitar outro código após o intervalo. Medir falhas sem registrar conteúdo. Validar cópia e colagem no Android e iOS.
- O DAG monta mensagens transacionais de texto. Para revisão: “Você tem uma entrega para revisar no DAG”. Para resultado: “Uma missão foi aprovada” ou “Uma missão precisa de um ajuste”. Incluir link HTTPS para rota autenticada sem código, nome, foto ou comentário na URL, com prévia de link desativada. Notificar cada responsável vinculado que autorizou avisos e o filho quando elegível. O comentário do adulto fica na entrega, com envio externo de texto livre condicionado conforme a regra de produto.
- Registrar evento no outbox junto com a transação e consumir depois do commit. Guardar o identificador retornado e distinguir aceitação pelo gateway de confirmação de entrega. Retentar falhas comprovadamente transitórias e sem envio, com espera progressiva e limite de tentativas. Em timeout com aceitação incerta, registrar estado indeterminado e reconciliar antes de reenviar. `track_id` pode servir para correlação e aceita duplicatas. Falha de WhatsApp preserva o aviso interno e o estado da tarefa. [Erros e confiabilidade](https://docs.uazapi.com/docs/errors-and-retries) · [Campos de rastreamento](https://docs.uazapi.com/endpoint/post/send~text)
- Para o MVP, o backend consulta `GET /instance/status` periodicamente e `POST /message/status` quando houver ID conhecido e necessidade de confirmar entrega. Sem confirmação disponível, manter o status pendente ou indeterminado conforme o retorno. O polling do PWA continua consultando só o Laravel. Se a instância cair, alertar a operação, segurar novos avisos na fila até restabelecer a conexão e reconferir o remetente após parear. O usuário pede um OTP novo quando o canal voltar. [Estado da instância](https://docs.uazapi.com/docs/instances) · [Confirmação de entrega](https://docs.uazapi.com/webhook/messages_update)

## Dados de menores e evidências

Definir antes do piloto quem é o controlador, as finalidades, a base legal por finalidade, o registro de autorização do responsável e o procedimento de acesso, correção, revogação e exclusão. Usar aviso em linguagem adequada a crianças e adultos. Avaliar o ECA Digital e a regulamentação vigente, incluindo supervisão parental e aferição de idade aplicável. A data de nascimento cadastrada pelo adulto atende ao requisito de cadastro e precisa de avaliação própria como mecanismo de aferição. Validar a elegibilidade da conta WhatsApp e as condições de tratamento pela uazapi, incluindo acesso, retenção e exclusão de números e conteúdo das mensagens. [ANPD sobre dados de crianças e adolescentes](https://www.gov.br/anpd/pt-br/assuntos/noticias/anpd-divulga-enunciado-sobre-o-tratamento-de-dados-pessoais-de-criancas-e-adolescentes) · [ECA Digital e regulamentação](https://www.gov.br/anpd/pt-br/assuntos/eca-digital/)

- Evitar diagnóstico, nome da escola, endereço, localização e imagens de rosto ou de terceiros. Orientar a fotografar o resultado da tarefa. Para atividades de higiene, foto desativada e confirmação por texto ou observação presencial. Registro de parentesco fica separado da autorização efetiva de acesso.
- Proposta para piloto: uma foto de até 10 MB e 24 megapixels, com JPEG, PNG e WebP validados no servidor. Incluir HEIC/HEIF se o runtime suportar conversão e passar no teste real de iPhone, ou orientar o uso de formato compatível. Exibir prévia e remover geolocalização e demais metadados ao recodificar. Limitar bytes e dimensões também na camada HTTP e decodificação.
- Proposta de retenção: foto até 90 dias após revisão ou encerramento final, com limpeza diária. Um pedido de ajuste ainda reaberto não inicia esse prazo. Manter o extrato e registros de pagamento conforme política validada para o piloto. Ao excluir foto, preservar o evento e exibir “Foto removida”. Definir expiração de backups, procedimento para reaplicar exclusões após restauração e trilha de acesso aos dados.
- O service worker armazena somente assets públicos versionados e uma tela genérica offline. Respostas autenticadas, fotos e dados financeiros usam rede e `no-store`. Evitar persistir rascunhos de menores em armazenamento local. Desabilitar rastreadores no MVP. Usar proteção contra XSS, CSP, codificação de textos e logs sem conteúdo familiar.

## VPS Hostinger e Easypanel

Em produção, o [serviço Compose](https://easypanel.io/docs/services/compose) sobe o container HTTP e o agendador `php artisan schedule:work`. Ambos usam um PostgreSQL provisionado separadamente e compartilham um volume de fotos privadas, fora do webroot. O EasyPanel termina o HTTPS e encaminha o tráfego HTTP ao app. Veja o [guia de publicação](06-deploy-easypanel.md).

1. Configurar banco privado, volume de fotos, DNS e TLS. Definir `UAZAPI_BASE_URL`, `UAZAPI_INSTANCE_TOKEN` e `WHATSAPP_SENDER_NUMBER` no backend via Easypanel, além de `APP_KEY`, conexão e chaves de OTP e telefone. O número esperado serve para conferir o pareamento. Usar `APP_ENV=production` e `APP_DEBUG=false`. Desabilitar exposição pública do banco e documentar rotação de segredos.
2. Em homologação, executar migrações numa etapa única, provisionar família fictícia via comando Laravel e validar o fluxo completo. Em produção, fazer backup antes da migração controlada com `php artisan migrate --force`. Usar migrações compatíveis com a imagem anterior para permitir rollback sem reverter dados de rotina.
3. O agendador gera ocorrências, reconcilia fechamentos e processa avisos pendentes a cada minuto. As rotinas diárias removem OTPs expirados e fotos que atingiram o prazo de retenção.
4. Monitorar HTTPS, conectividade do banco, execução do agendador, conexão da instância uazapi, idade da mensagem mais antiga, falhas de login e entrega e último fechamento. Registrar métricas sem identificadores de menores. Usar alertas operacionais para falhas repetidas.
5. Fazer backup diário cifrado do banco e do volume em destino externo, com chaves mantidas separadamente. Testar restauração conjunta em ambiente isolado antes do piloto e periodicamente. Validar integridade do extrato e referências de fotos, reaplicar exclusões e manter envios desativados durante restauração. Proposta inicial de recuperação: perder no máximo 24 horas e restaurar em até 4 horas, a validar com teste. [Backups do Easypanel](https://easypanel.io/docs/backups)
