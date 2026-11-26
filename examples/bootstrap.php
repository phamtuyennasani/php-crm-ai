<?php

use NodeService\CrmAiPhp\Client;
use NodeService\CrmAiPhp\CredentialsStore;
use NodeService\CrmAiPhp\Widget;

$autoload = getenv('CRM_AUTOLOAD_PATH') ?: dirname(__DIR__).'/vendor/autoload.php';
require is_file($autoload) ? $autoload : dirname(__DIR__).'/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$configPath = getenv('CRM_CONFIG_PATH') ?: dirname(__DIR__).'/private/crm-ai.json';
$config = json_decode(file_get_contents($configPath), true, 32, JSON_THROW_ON_ERROR);
$client = Client::fromFile($configPath);
$directory = dirname(realpath($configPath));
$credentials = new CredentialsStore($directory.'/crm-ai-credentials.enc', $directory.'/crm-ai-credentials.key');

// Replace this callback with the website's existing login and editor authorization.
$canWrite = static fn (): bool => isset($_SESSION['user_id']) && ($_SESSION['can_edit_articles'] ?? false) === true;
$widget = new Widget($client, $config['editor_origin'], $canWrite);
$csrf = Widget::csrfToken($_SESSION);
