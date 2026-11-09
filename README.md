# Do And Gain · DAG

PWA familiar para organizar tarefas recorrentes e transformar entregas aprovadas em pontos de mesada. O piloto atende uma família, com suporte a vários filhos e responsáveis autorizados.

| Documento | Conteúdo |
| --- | --- |
| [Produto e regras do MVP](docs/01-produto.md) | Escopo, papéis, jornadas, recorrência, revisão e fechamento |
| [Experiência e identidade](docs/02-experiencia.md) | Navegação, telas, textos, paleta e acessibilidade |
| [Arquitetura e operação](docs/03-arquitetura.md) | Stack, dados, rotas, WhatsApp, privacidade, Docker e Easypanel |
| [Backlog e critérios de aceite](docs/04-entrega.md) | Ordem de implementação, testes e preparação do piloto |
| [Planilhas e benchmark](docs/05-referencias.md) | Orientações para histórico, exemplos de cadastro e fontes |

## Decisões confirmadas para o piloto

- A criança recebe o código de acesso no próprio WhatsApp apto.
- Passado o prazo, o envio fica bloqueado até o responsável reabrir a tarefa.
- Gerar a mesada manualmente move os pontos disponíveis para uma conta pendente de pagamento. O fechamento automático segue a mesma regra.
- A uazapi é o gateway de WhatsApp. Um único número dedicado ao DAG envia códigos de acesso e avisos para todas as famílias.

## Stack proposta

Laravel, React com Inertia e TypeScript, PostgreSQL e uazapi. O plano do MVP prevê publicação da aplicação Docker no Easypanel em um domínio HTTPS, instalação como PWA e atualização periódica dos avisos enquanto o app estiver aberto.

## Rodar o DAG localmente

A versão atual cobre provisionamento da família, cadastro infantil, login por código, cadastro de tarefas recorrentes e lista de missões. Use dados fictícios para desenvolvimento e números autorizados para validar a uazapi.

1. Copie `.env.example` para `.env` e defina `DB_PASSWORD`. Gere duas chaves diferentes para `DAG_PHONE_KEY` e `DAG_OTP_KEY`, executando `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` uma vez para cada chave. Mantenha as chaves da mesma instalação ao recriar containers.
2. Configure `UAZAPI_BASE_URL` com a Server URL HTTPS da instância, `UAZAPI_INSTANCE_TOKEN` com o token da instância e `WHATSAPP_SENDER_NUMBER` com o número pareado em formato internacional, incluindo `+`. Guarde o token no `.env` local ou nos segredos do Easypanel.
3. Com Docker ativo, execute os comandos abaixo. Copie a chave retornada pelo segundo comando para `APP_KEY` no `.env` antes de executar o terceiro.

```sh
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --show
docker compose up -d app
docker compose exec app php artisan migrate --force
docker compose up -d scheduler
docker compose exec app php artisan dag:provision-family
```

Abra [localhost:8000](http://localhost:8000), entre com o WhatsApp do responsável provisionado, cadastre um perfil infantil com o número próprio e saia. Entre com o número da criança para conferir a separação dos perfis. O serviço `scheduler` limpa os desafios expirados.

Em **Filhos vinculados → Editar**, o responsável pode atualizar nome, nascimento e WhatsApp. Se trocar o número, a criança entra novamente pelo novo WhatsApp e pode reativar os avisos no painel.

Na área do responsável, abra **Gerenciar tarefas** e cadastre uma tarefa atribuída à criança. Escolha a recorrência, os dias, os pontos e os horários de semana e fim de semana. No perfil da criança, **Ver minhas missões** mostra as ocorrências abertas ordenadas pelo prazo, com filtros de periodicidade. O mesmo agendador prepara as ocorrências em segundo plano. A lista também prepara as missões elegíveis ao abrir.

Em **Tarefas cadastradas**, use **Editar** para atualizar a configuração ou **Excluir** para parar de gerar novas missões. A exclusão é lógica. As missões já abertas preservam as condições originais.

Em **Ver minhas missões**, a criança abre uma missão, confirma a conclusão e pode enviar comentário e foto conforme a configuração. Em **Revisar missões**, o responsável aprova, pede ajuste ou reabre uma missão vencida. A aprovação credita pontos no perfil da criança.

Cada perfil pode habilitar os avisos do WhatsApp no painel. Use uma `APP_URL` acessível a quem vai abrir os links das mensagens. O volume `evidence_data` guarda as fotos privadas no Compose. O serviço `scheduler` faz novas tentativas de aviso e limpa fotos revisadas há 90 dias. No Easypanel, configure o processo `php artisan schedule:work` com acesso ao mesmo banco e volume privado da aplicação.

Em **Filhos vinculados → Mesadas**, o responsável define a taxa da família em centavos por ponto. A primeira taxa dá valor aos créditos existentes. As taxas seguintes passam a valer para missões abertas depois da mudança. Use **Gerar conta** para fechar manualmente e **Marcar como paga** depois do pagamento. O serviço `scheduler` processa o fechamento automático com o corte do primeiro dia do mês no fuso da família. A criança acompanha saldos, extrato e histórico em **Ver meus ganhos**.

Use **Instalar DAG** em um navegador compatível. No iPhone, abra no Safari e use **Compartilhar → Adicionar à Tela de Início**. O PWA requer HTTPS fora de `localhost`. **Avisos** mostra entregas e revisões e atualiza a contagem enquanto o app estiver visível. A tela sem internet é genérica, e os dados autenticados são buscados na rede.

### Build e testes fora do Docker

Com PHP 8.3 ou superior, Composer e Node 22.12 ou 24, execute:

```sh
composer install
npm install
npm run build
composer test
```

Os testes de autenticação usam SQLite em memória e respostas simuladas da uazapi. Para rodar só o PostgreSQL em Docker, execute `docker compose up -d database`. De fora do Docker, use `127.0.0.1:5433` com o banco, usuário e senha do `.env`. Se o Laravel também rodar fora do Docker, ajuste `DB_HOST=127.0.0.1` e `DB_PORT=5433`, gere `APP_KEY` com `php artisan key:generate`, rode as migrações e inicie `php artisan serve`. Para voltar a rodar o Laravel no Compose, restaure `DB_HOST=database` e `DB_PORT=5432`.

Em HTTPS no Easypanel, configure `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` do domínio e `SESSION_SECURE_COOKIE=true`.
