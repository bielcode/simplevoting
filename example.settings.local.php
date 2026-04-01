<?php

/**
 * @file
 * Configurações locais para o ambiente Lando.
 *
 * Copie para web/sites/default/settings.local.php e nunca versione esse
 * arquivo — ele já está no .gitignore por conter credenciais locais.
 */

// Banco de dados — "database" é o hostname do container definido no .lando.yml.
// Se for conectar de fora do container (DBeaver, TablePlus etc.), use
// 127.0.0.1 com a porta que aparece em "lando info".
$databases['default']['default'] = [
  'database'  => 'drupal10',
  'username'  => 'drupal10',
  'password'  => 'drupal10',
  'prefix'    => '',
  'host'      => 'database',
  'port'      => '3306',
  'driver'    => 'mysql',
  // Namespace e autoload são obrigatórios no Drupal 10 com o driver mysql.
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload'  => 'core/modules/mysql/src/Driver/Database/mysql/',
];

// Hash salt — qualquer string serve em dev, mas em produção gere um valor real:
// drush php-eval "echo \Drupal\Component\Utility\Crypt::randomBytesBase64(55)"
$settings['hash_salt'] = 'lando-local-dev-TROQUE-EM-PRODUCAO';

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
$settings['cache']['bins']['render']             = 'cache.backend.null';
$settings['cache']['bins']['page']               = 'cache.backend.null';
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';

// Erros visíveis no browser, em produção isso fica em 'hide'.
$config['system.logging']['error_level'] = 'verbose';
