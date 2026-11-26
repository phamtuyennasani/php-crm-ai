<?php

namespace NodeService\CrmAiPhp;

use RuntimeException;

final class PrivateFile
{
    public static function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create the private CRM directory.');
        }
    }

    public static function replace(string $path, #[\SensitiveParameter] string $contents): void
    {
        self::directory(dirname($path));
        $temporary = tempnam(dirname($path), '.crm-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot prepare private CRM storage.');
        }
        try {
            chmod($temporary, 0600);
            if (file_put_contents($temporary, $contents) !== strlen($contents) || ! rename($temporary, $path)) {
                throw new RuntimeException('Cannot save private CRM storage.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function create(string $path, #[\SensitiveParameter] string $contents): void
    {
        self::directory(dirname($path));
        $file = @fopen($path, 'x');
        if ($file === false) {
            throw new RuntimeException('Private CRM storage already exists or cannot be created.');
        }
        try {
            chmod($path, 0600);
            if (fwrite($file, $contents) !== strlen($contents) || ! fflush($file)) {
                throw new RuntimeException('Cannot persist private CRM storage.');
            }
        } finally {
            fclose($file);
        }
    }
}
