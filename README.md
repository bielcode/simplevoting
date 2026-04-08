# Simple Voting

Sistema de votação desenvolvido como teste técnico para a NTT Data. Permite que administradores cadastrem enquetes com opções de resposta e que usuários autenticados votem — tanto pela interface do próprio Drupal quanto por uma API REST construída manualmente.

Requisitos técnicos atendidos: entidades construídas sem `node`, API implementada sem JSON:API, ambiente via Lando, dump de banco incluído, collection Postman disponível.

---

## Requisitos

| Dependência | Versão | Observação |
|---|---|---|
| [Lando](https://docs.lando.dev/install/linux.html) | v3.x | Orquestra os containers Docker |
| [Docker Engine](https://docs.docker.com/engine/install/) | 24.x+ | Backend do Lando |
| Git | qualquer | — |

Não é necessário PHP, Composer ou Drush instalados globalmente — tudo roda dentro dos containers gerenciados pelo Lando.

---

## Instalação e configuração

### 1. Clone o repositório

```bash
git clone https://github.com/bielcode/simplevoting.git
cd simplevoting
```

### 2. Instale o Docker (se necessário)

```bash
curl -fsSL https://get.docker.com | sudo bash
sudo usermod -aG docker $USER
# Faça logout e login novamente para o grupo docker ser reconhecido
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

Na primeira execução o Lando baixa as imagens Docker (PHP 8.2, Apache 2.4, MariaDB 10.6). A partir da segunda execução o ambiente inicia em segundos.

### 5. Instale as dependências PHP

```bash
lando composer install
```

### 6. Restaure o banco de dados

O repositório inclui o dump completo em `dump/simplevoting.sql`, com enquetes e votos de exemplo prontos para demonstração.

```bash
lando drush sql:drop -y
lando drush sql:cli < dump/simplevoting.sql
lando drush cr
```

**Login padrão:** `admin` / `admin`

> **Dica (opcional):** Para desligar o cache e habilitar erros verbosos em desenvolvimento, copie `cp example.settings.local.php web/sites/default/settings.local.php`. Não é necessário para usar o sistema.

<details>
<summary>Instalação do zero (sem dump)</summary>

```bash
lando drush site:install standard \
  --db-url=mysql://drupal10:drupal10@database/drupal10 \
  --account-name=admin \
  --account-pass=admin \
  --account-mail=admin@example.com \
  --site-name="Simple Voting" \
  -y

lando drush en simple_voting -y
lando drush cr
```
</details>

### 7. Acesse o site

**https://simplevoting.lndo.site**

> O certificado é autoassinado — aceite a exceção de segurança no browser na primeira vez.

---

## Interface administrativa

Acesse `/admin/config/simple-voting/questions` (menu: Configurações → Simple Voting → Voting Questions).

**Gerenciamento de enquetes:**
- Criar, editar e excluir enquetes via formulário intuitivo
- Cada enquete tem um identificador único (machine name) imutável após criação
- Opções de resposta com título (obrigatório), descrição e imagem — quantidade ilimitada, adicionadas via AJAX sem recarregar a página
- Por enquete: configurar se o total de votos é exibido ou ocultado após a votação
- Status individual: aberta ou encerrada

**Configurações globais** (`/admin/config/simple-voting/settings`):
- Habilitar ou desabilitar a votação de forma geral — quando desabilitado, nenhuma enquete aceita votos, independente do status individual, e o bloqueio se aplica tanto à interface CMS quanto à API

**Permissões** (`/admin/people/permissions`):

| Permissão | Descrição |
|---|---|
| `administer simple voting` | Criar/editar enquetes e acessar configurações |
| `vote in polls` | Registrar votos (concedida ao `authenticated user` na instalação) |
| `view voting results` | Visualizar resultados mesmo quando ocultos na enquete |

---

## Interface de votação (CMS)

Acesse `/voting` para ver a lista de enquetes disponíveis.

- Cada enquete tem URL própria: `/voting/{id}`
- O usuário seleciona uma opção e clica em "Registrar voto"
- Um usuário não pode votar mais de uma vez na mesma enquete — tentativas duplicadas exibem aviso sem gerar erro
- Após votar, o usuário é redirecionado para a página de resultados (`/simple-voting/results/{id}`)
- Os resultados são exibidos ou ocultados conforme a configuração individual de cada enquete
- O formulário também pode ser incorporado em qualquer região do site via bloco "Simple Voting: Formulário de Votação"

---

## API REST

**Base URL:** `https://simplevoting.lndo.site`

Todos os endpoints exigem autenticação de usuário Drupal. Use **HTTP Basic Auth** em chamadas externas (Postman, aplicações mobile etc.). O endpoint de voto exige adicionalmente o header `X-CSRF-Token` para clientes de sessão — obtido via `GET /session/token`.

### Endpoints

#### `GET /api/voting/v1/questions`

Lista todas as enquetes com status aberto.

```bash
curl -u admin:admin https://simplevoting.lndo.site/api/voting/v1/questions
```

```json
{
  "data": [
    { "id": "enquete-exemplo", "title": "Qual sua linguagem favorita?", "show_results": true }
  ]
}
```

---

#### `GET /api/voting/v1/questions/{id}`

Retorna os detalhes de uma enquete com suas opções de resposta.

```bash
curl -u admin:admin https://simplevoting.lndo.site/api/voting/v1/questions/enquete-exemplo
```

```json
{
  "data": {
    "id": "enquete-exemplo",
    "title": "Qual sua linguagem favorita?",
    "status": "open",
    "show_results": true,
    "options": [
      { "id": 1, "title": "PHP", "description": "A linguagem da web" },
      { "id": 2, "title": "Python" }
    ]
  }
}
```

---

#### `POST /api/voting/v1/votes`

Registra um voto. Um usuário pode votar apenas uma vez por enquete.

```bash
curl -u admin:admin \
  -H "Content-Type: application/json" \
  -d '{"question_id": "enquete-exemplo", "option_id": 1}' \
  https://simplevoting.lndo.site/api/voting/v1/votes
```

```json
{ "message": "Voto registrado com sucesso." }
```

**Códigos de resposta relevantes:**

| Código | Situação |
|---|---|
| 201 | Voto registrado |
| 400 | Body inválido ou campos ausentes |
| 401 | Sem autenticação |
| 409 | Usuário já votou nesta enquete |
| 422 | Enquete encontrada, mas fechada para votos |
| 503 | Votação globalmente desabilitada ou sobrecarga momentânea |

---

#### `GET /api/voting/v1/questions/{id}/results`

Retorna a contagem de votos por opção com percentual. Respeita a configuração `show_results` da enquete — se estiver desabilitada, apenas usuários com a permissão `view voting results` recebem os dados; demais recebem 403.

```bash
curl -u admin:admin https://simplevoting.lndo.site/api/voting/v1/questions/enquete-exemplo/results
```

```json
{
  "data": {
    "question_id": "enquete-exemplo",
    "question_title": "Qual sua linguagem favorita?",
    "total_votes": 3,
    "options": [
      { "id": 1, "title": "PHP", "votes": 2, "percentage": 66.7 },
      { "id": 2, "title": "Python", "votes": 1, "percentage": 33.3 }
    ]
  }
}
```

### Collection Postman

Importe o arquivo `postman/simple_voting.postman_collection.json` no Postman. A collection já inclui todos os endpoints com exemplos de request/response e variável `base_url` configurável para o ambiente local ou qualquer outro.

---

## Testes

Os testes unitários cobrem a lógica central de registro de votos, focando nos cenários de concorrência e proteção de duplicidade que não são viáveis de reproduzir manualmente.

```bash
lando test-unit
```

```
Voting Service (Drupal\Tests\simple_voting\Unit\Service\VotingService)
 ✔ Registra um voto novo com os campos corretos quando não há duplicata
 ✔ Lança DuplicateVoteException quando o SELECT detecta voto anterior (camada 1)
 ✔ Trata violação de unique constraint como voto duplicado, não como erro fatal (camada 2)
 ✔ Lança VoteLockUnavailableException sem tocar no banco quando o lock falha duas vezes
 ✔ Prossegue normalmente quando o lock é adquirido na segunda tentativa
 ✔ Libera o lock mesmo quando o INSERT lança uma exceção inesperada (finally garantido)
 ✔ Persiste IP vazio sem erro quando executado fora de contexto HTTP (CLI/Drush)

OK (7 tests, 18 assertions)
```

---

## Comandos úteis

```bash
lando start                    # sobe o ambiente
lando stop                     # para o ambiente
lando drush cr                 # limpa o cache do Drupal
lando drush uli                # gera link de login de um clique (sem precisar da senha)
lando drush updb -y            # aplica atualizações de schema pendentes
lando drush sql:dump --result-file=/app/dump/simplevoting.sql  # gera dump
lando composer install         # instala dependências PHP
lando test-unit                # roda os testes unitários do módulo
lando phpcs                    # audita o código contra o padrão Drupal/DrupalPractice
lando phpcbf                   # aplica correções de coding standard automaticamente
lando ssh                      # acesso ao terminal do container
```

phpMyAdmin disponível em `http://localhost:32770` (confirme a porta com `lando info`).

---

## Estrutura do módulo

```
web/modules/custom/simple_voting/
├── config/
│   ├── install/
│   │   └── simple_voting.settings.yml   # configuração padrão (votação habilitada)
│   └── schema/
│       └── simple_voting.schema.yml     # schema de validação da config
├── src/
│   ├── Breadcrumb/
│   │   └── VotingBreadcrumbBuilder.php  # hierarquia Home > Enquetes > Questão > Resultado
│   ├── Controller/
│   │   ├── VotingApiController.php      # endpoints da API REST v1
│   │   ├── VotingPageController.php     # páginas públicas de listagem e votação
│   │   └── VotingResultsController.php  # página de resultados pós-voto
│   ├── Entity/
│   │   ├── VotingQuestion.php           # ConfigEntity (sem node)
│   │   ├── VotingQuestionInterface.php  # contrato da entidade
│   │   └── VotingQuestionListBuilder.php
│   ├── EventSubscriber/
│   │   └── VotingAccessDeniedSubscriber.php  # redireciona anônimos ao login
│   ├── Exception/
│   │   ├── DuplicateVoteException.php
│   │   └── VoteLockUnavailableException.php
│   ├── Form/
│   │   ├── QuestionForm.php             # CRUD de enquetes + opções via AJAX
│   │   ├── VoteForm.php                 # formulário de votação do usuário
│   │   └── VotingSettingsForm.php       # habilitar/desabilitar votação global
│   ├── Plugin/Block/
│   │   └── VotingBlock.php              # bloco configurável para regiões do tema
│   └── Services/
│       └── VotingService.php            # lógica de voto com lock + unique constraint
├── tests/
│   └── src/Unit/Service/
│       └── VotingServiceTest.php        # 7 testes unitários com mocks
├── phpunit.xml
├── simple_voting.info.yml
├── simple_voting.install                # hook_schema, hook_install, update hooks
├── simple_voting.links.action.yml
├── simple_voting.links.menu.yml
├── simple_voting.module
├── simple_voting.permissions.yml
├── simple_voting.routing.yml
```

