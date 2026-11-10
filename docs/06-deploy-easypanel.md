# Publicação do DAG no EasyPanel

O [Compose de produção](../compose.production.yaml) sobe o app HTTP e o agendador. Os dois usam o mesmo PostgreSQL externo e compartilham o volume privado `evidence_data`.

## 1. Prepare o banco e o domínio

- Crie um banco PostgreSQL dedicado e um usuário da aplicação com permissão para criar tabelas no schema usado pelas migrations. Use um endpoint direto, acessível a partir dos containers da VPS.
- Se o PostgreSQL for um serviço separado do EasyPanel, consulte **Credentials** e use o host e a porta internos. Use a variante de Compose descrita abaixo para colocar o app e o agendador na rede privada do PostgreSQL. [Postgres no EasyPanel](https://easypanel.io/docs/services/postgres)
- Se o PostgreSQL estiver em outro servidor, autorize a conexão da VPS no firewall ou na rede privada do provedor. Use a URI fornecida em `DB_URL` quando precisar de parâmetros de TLS, como `sslmode=require`. A URI deve usar credenciais codificadas para URL.
- Crie um registro DNS **A** para o subdomínio do DAG apontando para o IPv4 da VPS. [DNS na Hostinger](https://www.hostinger.com/support/1583227-how-to-point-a-domain-to-your-vps-at-hostinger/)

## 2. Crie o serviço Compose

No projeto do EasyPanel, selecione **New Service → Compose → Git** e preencha os campos abaixo. [Serviço Compose no EasyPanel](https://easypanel.io/docs/services/compose)

| Campo | Valor |
| --- | --- |
| Repository URL | `https://github.com/bbrandao/dag.git` |
| Branch | `main` |
| Build Path | `/` |
| Docker Compose File | `compose.production.yaml` |

Para usar o host interno de um PostgreSQL separado no EasyPanel, selecione `compose.easypanel.yaml` no campo **Docker Compose File**. Essa variante conecta o app e o agendador à rede existente do banco e à rede padrão do Compose. No SSH da VPS, consulte `docker ps --format 'table {{.Names}}\t{{.Networks}}'` para identificar a rede privada do container PostgreSQL. Uma rede overlay precisa aceitar a conexão de containers independentes. [Redes externas no Compose](https://docs.docker.com/reference/compose-file/networks/#external)

Em **Environment**, habilite **Create .env file** e cole o [modelo de produção](../.env.production.example). Preencha `APP_URL` com o domínio HTTPS, a conexão do PostgreSQL e as credenciais da instância Uazapi. Em `WHATSAPP_SENDER_NUMBER`, informe o número pareado no formato internacional, com `+` e DDI.

`DAG_PUBLIC_URL` define o domínio dos links de WhatsApp enviados pelo app e pelo agendador. No `compose.easypanel.yaml`, o padrão é `https://dag.eclud.com.br`. Em outra instalação, defina a variável com o domínio HTTPS real, igual ao `APP_URL`.

O arquivo `compose.easypanel.yaml` usa `easypanel-promovilas-prod` por padrão para que o painel consiga validar a origem antes de carregar o ambiente. Se usar outra rede privada, defina `DAG_POSTGRES_NETWORK` com o nome exato da rede da VPS.

Para uma instalação nova, gere uma chave Laravel com `openssl rand -base64 32` e prefixe o resultado com `base64:` em `APP_KEY`. Gere `DAG_PHONE_KEY` e `DAG_OTP_KEY` com duas execuções separadas de `openssl rand -hex 32`. Guarde os valores no painel. Se estiver restaurando dados de outra instalação, preserve as chaves de origem e restaure também o volume de fotos.

## 3. Faça o deploy e prepare o banco

Clique em **Deploy**. Abra o shell do container `app` e execute os comandos abaixo. Se o EasyPanel não mostrar o shell, entre na VPS por SSH, identifique o container com `docker ps` e abra-o com `docker exec -it -u www-data NOME_DO_CONTAINER_APP sh`.

```sh
php artisan migrate --force
php artisan migrate:status
php artisan dag:provision-family
```

Execute `dag:provision-family` uma vez por família nova. O comando pede o nome da família, o nome e parentesco do responsável, o WhatsApp com DDI e o fuso horário. O agendador executa suas rotinas quando as tabelas estiverem disponíveis.

## 4. Habilite HTTPS e valide

Em **Domains**, adicione o domínio de `APP_URL`, caminho `/`, serviço `app`, protocolo interno HTTP e porta `80`. Habilite o certificado HTTPS. O app de produção confia no protocolo e no IP encaminhados pelo proxy. Mantenha o acesso externo ao container pela rota do EasyPanel. [Domínios do Compose](https://easypanel.io/docs/services/compose#domains)

1. Abra `/up` e `/login` no domínio HTTPS.
2. Faça login pelo WhatsApp com o perfil provisionado e confira os logs de `app` e `scheduler`.
3. Envie uma tarefa com foto e confira o aviso e o link no WhatsApp. Teste a instalação da PWA no celular.
4. Inclua o banco externo e o volume `evidence_data` nos backups. Em versões com novas migrations, faça backup e rode `php artisan migrate --force` no container `app` durante a janela de publicação.
