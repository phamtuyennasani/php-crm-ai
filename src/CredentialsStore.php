<?php

namespace NodeService\CrmAiPhp;

use InvalidArgumentException;
use RuntimeException;

final class CredentialsStore
{
    public function __construct(private readonly string $path, private readonly string $keyPath) {}

    public function install(): void
    {
        if (is_file($this->keyPath)) {
            $this->key();
            return;
        }
        if (is_file($this->path)) {
            throw new RuntimeException('Restore the original credentials encryption key before reading existing data.');
        }
        PrivateFile::create($this->keyPath, random_bytes(32));
    }

    private function key(): string
    {
        $key = @file_get_contents($this->keyPath);
        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('The CRM credentials encryption key is unavailable. Run the installer or restore the original key.');
        }
        return $key;
    }

    public static function validate(#[\SensitiveParameter] array $values): array
    {
        if ($values === []) {
            return [];
        }
        foreach (['base_url' => 500, 'api_key' => 2048, 'model' => 160] as $name => $maximum) {
            if (! isset($values[$name]) || ! is_string($values[$name]) || trim($values[$name]) === '' || strlen(trim($values[$name])) > $maximum) {
                throw new InvalidArgumentException('Own AI credentials require a valid Base URL, API key and model.');
            }
        }
        $url = rtrim(trim($values['base_url']), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || preg_match('/[\r\n\x00]/', $values['api_key'])
            || ! preg_match('/\A[^\x00-\x20\x7f]+\z/u', trim($values['model']))) {
            throw new InvalidArgumentException('Own AI credentials use an HTTPS API root and a model without whitespace.');
        }
        return ['base_url' => $url, 'api_key' => trim($values['api_key']), 'model' => trim($values['model'])];
    }

    public function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $encrypted = @file_get_contents($this->path);
        $decoded = is_string($encrypted) && str_starts_with($encrypted, 'v1.') ? base64_decode(substr($encrypted, 3), true) : false;
        if ($decoded === false || strlen($decoded) < 29) {
            throw new RuntimeException('Cannot read encrypted CRM credentials.');
        }
        $plain = openssl_decrypt(substr($decoded, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA,
            substr($decoded, 0, 12), substr($decoded, 12, 16), 'nodeservice-php-crm-ai-v1');
        if ($plain === false) {
            throw new RuntimeException('Cannot decrypt CRM credentials with the current encryption key.');
        }
        $values = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($values)) {
            throw new RuntimeException('Invalid CRM credentials storage.');
        }
        return self::validate($values);
    }

    /** Save all fields; a blank key keeps the current key. An empty array removes credentials. */
    public function save(#[\SensitiveParameter] array $values): void
    {
        PrivateFile::directory(dirname($this->path));
        $lock = fopen($this->path.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot lock CRM credentials.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock CRM credentials.');
            }
            if ($values !== [] && (! isset($values['api_key']) || (is_string($values['api_key']) && trim($values['api_key']) === ''))) {
                $values['api_key'] = $this->read()['api_key'] ?? '';
            }
            $values = self::validate($values);
            $nonce = random_bytes(12);
            $plain = json_encode($values, JSON_THROW_ON_ERROR);
            $encrypted = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag, 'nodeservice-php-crm-ai-v1');
            if ($encrypted === false) {
                throw new RuntimeException('Cannot encrypt CRM credentials.');
            }
            PrivateFile::replace($this->path, 'v1.'.base64_encode($nonce.$tag.$encrypted));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
