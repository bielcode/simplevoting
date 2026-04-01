# Simple Voting

Módulo Drupal 10 custom para um sistema de votação simples, desenvolvido como teste técnico.

---

## Requisitos

Antes de tudo, você vai precisar de:

- Ubuntu 22.04 ou superior
- [Docker Engine](https://docs.docker.com/engine/install/ubuntu/) (instalado via script oficial)
- [Lando](https://docs.lando.dev/install/linux.html) v3.x
- Git

> Não precisa de PHP ou Composer na máquina — tudo roda dentro dos containers do Lando.

---

## Instalação

### 1. Clone o repositório

```bash
git clone https://github.com/bielcode/simplevoting.git
cd simplevoting
```

### 2. Instale o Docker

Se ainda não tiver o Docker instalado:

```bash
curl -fsSL https://get.docker.com | sudo bash
sudo usermod -aG docker $USER
```

Depois disso, faça **logout e login** no Ubuntu para o grupo `docker` ser reconhecido. Sem isso, qualquer comando Docker vai retornar "permission denied".

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

Na primeira execução o Lando vai baixar as imagens Docker (PHP 8.2, Apache, MariaDB 10.6). Pode demorar alguns minutos dependendo da sua conexão.

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

### 7. Acesse o site

Abra o navegador em **https://simplevoting.lndo.site**

Login: `admin` / Senha: `admin`

> O certificado local é autoassinado — aceite a exceção de segurança no browser na primeira vez.

---

## Comandos do lando

```bash
lando start          # sobe os containers
lando stop           # para os containers
lando drush cr       # limpa o cache do Drupal
lando drush uli      # gera link de login de um clique
lando composer <cmd> # roda qualquer comando Composer dentro do container
lando ssh            # acesso direto ao terminal do container
```

O phpMyAdmin fica disponível em **http://localhost:32771** (a porta pode variar — confirme com `lando info`).

---

## Estrutura do projeto

```
simplevoting/
├── .lando.yml                  # configuração do ambiente Lando
├── composer.json               # dependências PHP
├── drush/
│   └── drush.yml               # configurações globais do Drush
├── web/
│   ├── core/                   # Drupal core (não editar)
│   ├── modules/custom/
│   │   └── simple_voting/      # módulo principal deste projeto
│   └── sites/default/
│       └── settings.local.php  # configurações locais (não versionado)
└── example.settings.local.php  # template do settings.local.php
```
