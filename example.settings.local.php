<?php

/**
 * @file
 * Configurações locais para o ambiente Lando.
 *
 * Copie para web/sites/default/settings.local.php e nunca versione esse
 * arquivo — ele já está no .gitignore por conter credenciais locais.
 */

// Banco de dados — valores lidos de variáveis de ambiente definidas no .env.
// Se for conectar de fora do container (DBeaver, TablePlus etc.), use
// 127.0.0.1 com a porta que aparece em "lando info".
$databases['default']['default'] = [
  'database'  => getenv('DB_NAME'),
  'username'  => getenv('DB_USER'),
  'password'  => getenv('DB_PASSWORD'),
  'prefix'    => '',
  'host'      => getenv('DB_HOST'),
  'port'      => getenv('DB_PORT'),
  'driver'    => getenv('DB_DRIVER'),
  // Namespace e autoload são obrigatórios no Drupal 10 com o driver mysql.
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload'  => 'core/modules/mysql/src/Driver/Database/mysql/',
];

// Hash salt — qualquer string serve em dev, mas em produção gere um valor real:
// drush php-eval "echo \Drupal\Component\Utility\Crypt::randomBytesBase64(55)"
$settings['hash_salt'] = getenv('HASH_SALT');

// Aceita apenas os hosts esperados no ambiente local.
$settings['trusted_host_patterns'] = [
  '^simplevoting\.lndo\.site$',
  '^localhost$',
  '^127\.0\.0\.1$',
];

// Desliga agregação de CSS/JS — facilita muito inspecionar assets no devtools.
$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess']  = FALSE;

// Cache nulo em dev evita ter que rodar "drush cr" toda hora.
$settings['cache']['bins']['render']             = 'cache.backend.memory';
$settings['cache']['bins']['page']               = 'cache.backend.memory';
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.memory';

// Erros visíveis no browser, em produção isso fica em 'hide'.
$config['system.logging']['error_level'] = 'verbose';
