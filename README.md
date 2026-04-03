# Simple Voting

Módulo Drupal 10 custom para um sistema de votação simples, desenvolvido como teste técnico para a NTT Data.

A API expõe enquetes e registra votos via endpoints REST próprios — sem o módulo JSON:API, sem entidades Node. Toda a lógica reside no módulo `simple_voting` usando hooks, serviços injetados pelo container e entities customizadas, seguindo os padrões do core do Drupal 10.

---

## Requisitos

| Dependência | Versão mínima | Observação |
|---|---|---|
| PHP | 8.2 | Apenas dentro do Lando |
| [Lando](https://docs.lando.dev/install/linux.html) | v3.x | Orquestra Docker localmente |
| [Docker Engine](https://docs.docker.com/engine/install/ubuntu/) | 24.x | Instalado via script oficial |
| Git | qualquer | — |

> Não é necessário PHP, Composer ou Drush instalados globalmente — tudo roda dentro dos containers gerenciados pelo Lando.

---

## Instalação

### 1. Clone o repositório

```bash
git clone https://github.com/bielcode/simplevoting.git
cd simplevoting
```

### 2. Instale o Docker (se ainda não tiver)

```bash
curl -fsSL https://get.docker.com | sudo bash
sudo usermod -aG docker $USER
# Faça logout e login novamente para o grupo docker ser reconhecido.
# Sem isso qualquer comando Docker retornará "permission denied".
```

### 3. Instale o Lando

```bash
/bin/bash -c "$(curl -fsSL https://get.lando.dev/setup-lando.sh)" -- --yes
echo 'export PATH="$HOME/.lando/bin:$PATH"' >> ~/.bashrc
source ~/.bashrc
```

### 4. Suba o ambiente

```bash
lando start
```

Na primeira execução o Lando baixa as imagens Docker (PHP 8.2, Apache 2.4, MariaDB 10.6). Pode levar alguns minutos dependendo da conexão.

### 5. Instale as dependências PHP

```bash
lando composer install
```

### 6. Instale o Drupal

```bash
lando drush site:install standard \
  --db-url=mysql://drupal10:drupal10@database/drupal10 \
  --account-name=admin \
  --account-pass=admin \
  --account-mail=admin@example.com \
  --site-name="Simple Voting" \
  --locale=en \
  -y
```

### 7. Habilite o módulo

```bash
lando drush en simple_voting -y
lando drush cr
```

O `drush en` irá executar `simple_voting.install`, criando as tabelas `simple_voting_option` e `simple_voting_vote`, além de importar a configuração inicial com algumas enquetes de exemplo.

### 8. Acesse o site

Abra o navegador em **https://simplevoting.lndo.site**

Login: `admin` / Senha: `admin`

> O certificado é autoassinado — aceite a exceção de segurança no browser na primeira vez.

---

## API REST

Base URL: `https://simplevoting.lndo.site`

Todos os endpoints exigem autenticação. Em chamadas programáticas use **Basic Auth** com as credenciais do Drupal. O endpoint de voto adicionalmente requer o header `X-CSRF-Token`, obtido em `/session/token`.

| Método | Rota | Descrição |
|---|---|---|
| GET | `/api/voting/v1/questions` | Lista todas as enquetes abertas |
| GET | `/api/voting/v1/questions/{id}` | Detalhes de uma enquete com suas opções |
| POST | `/api/voting/v1/votes` | Registra um voto |
| GET | `/api/voting/v1/questions/{id}/results` | Resultados consolidados da enquete |

O body do POST de voto deve ser `application/json`:

```json
{
  "question_id": "enquete-exemplo",
  "option_id": 1
}
```

A collection Postman está disponível em `postman/simple_voting.postman_collection.json`. Basta importar e configurar a variável de ambiente `base_url`.

---

## Comandos úteis do Lando

```bash
lando start               # sobe os containers
lando stop                # para os containers
lando drush cr            # limpa o cache do Drupal
lando drush uli           # gera link de login de um clique
lando drush en <modulo>   # habilita um módulo
lando composer <cmd>      # qualquer comando Composer dentro do container
lando ssh                 # acesso ao terminal do appserver
```

O phpMyAdmin fica em **http://localhost:32771** (a porta pode variar — confirme com `lando info`).

---

## Dump e restauração do banco

### Exportar

```bash
# Gera um arquivo compactado com timestamp no diretório atual
lando drush sql:dump --gzip --result-file=./dump/simplevoting_$(date +%Y%m%d_%H%M%S).sql
```

Ou diretamente via `mysqldump` se preferir o dump nativo:

```bash
lando ssh -c "mysqldump -u drupal10 -pdrupal10 -h database drupal10 | gzip > /app/dump/simplevoting_$(date +%Y%m%d_%H%M%S).sql.gz"
```

### Importar

```bash
# Substitua pelo nome do arquivo gerado
lando drush sql:drop -y
lando drush sql:cli < dump/simplevoting_YYYYMMDD_HHMMSS.sql
```

> O diretório `dump/` está no `.gitignore` — nunca comite arquivos de banco no repositório.

---

## Estrutura do projeto

```
simplevoting/
├── .lando.yml                        # configuração do ambiente Lando
├── composer.json                     # dependências PHP
├── drush/
│   └── drush.yml                     # URI padrão e outras configs do Drush
├── postman/
│   └── simple_voting.postman_collection.json
├── web/
│   ├── core/                         # Drupal core (não editar)
│   ├── modules/custom/
│   │   └── simple_voting/            # módulo principal deste projeto
│   │       ├── src/
│   │       │   ├── Controller/       # VotingApiController, VotingResultsController
│   │       │   ├── Entity/           # VotingQuestion (ConfigEntity)
│   │       │   ├── Form/             # VotingSettingsForm
│   │       │   ├── Plugin/           # Block plugin de resultados
│   │       │   └── Services/         # VotingService (lógica de negócio)
│   │       ├── config/install/       # configuração inicial (enquetes de exemplo)
│   │       ├── simple_voting.routing.yml
│   │       ├── simple_voting.services.yml
│   │       └── simple_voting.install
│   └── sites/default/
│       └── settings.local.php        # credenciais locais (não versionado)
└── example.settings.local.php        # template do settings.local.php
```
