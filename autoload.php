<?php

spl_autoload_register(static function (string $class): void {
    $prefix = 'NodeService\\CrmAiPhp\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name)) {
        return;
    }
    $file = __DIR__.'/src/'.$name.'.php';
    if (is_file($file)) {
        require $file;
    }
});
