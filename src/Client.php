<?php

namespace NodeService\CrmAiPhp;

use InvalidArgumentException;
use RuntimeException;

final class Client
{
    private readonly string $url;
    private readonly SigningKey $key;

    public function __construct(
        string $url,
        public readonly string $siteId,
        #[\SensitiveParameter] private readonly string $token,
        string $signingKeyPath,
        private readonly bool $allowLocalhost = false,
    ) {
        $this->url = $this->origin($url);
        if (! preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/i', $siteId)) {
            throw new InvalidArgumentException('Configure a valid CRM website UUID.');
        }
        if (! preg_match('/\A[A-Za-z0-9_-]{16,256}\z/', $token)) {
            throw new InvalidArgumentException('Configure a valid CRM site token.');
        }
        if ($signingKeyPath === '') {
            throw new InvalidArgumentException('Configure the private signing key path.');
        }
        $this->key = new SigningKey($signingKeyPath);
    }

    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read the private CRM configuration file.');
        }
        $config = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($config)) {
            throw new InvalidArgumentException('CRM configuration must be a JSON object.');
        }
        $keyPath = $config['signing_key_path'] ?? 'crm-signing.pem';
        if (! is_string($keyPath)) {
            throw new InvalidArgumentException('Configure the private signing key path.');
        }
        if (! preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $keyPath)) {
            $keyPath = dirname(realpath($path)).DIRECTORY_SEPARATOR.$keyPath;
        }
        return new self((string) ($config['url'] ?? ''), (string) ($config['site_id'] ?? ''),
            (string) ($config['token'] ?? ''), $keyPath, ($config['allow_localhost'] ?? false) === true);
    }

    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'site_id' => $this->siteId];
    }

    public function origin(string $value): string
    {
        $parts = parse_url($value);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new InvalidArgumentException('Use a root HTTPS origin without credentials, query or fragment.');
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host']);
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if ($scheme !== 'https' && ! ($this->allowLocalhost && $local && $scheme === 'http')) {
            throw new InvalidArgumentException('CRM and editor origins require HTTPS. HTTP loopback requires allow_localhost.');
        }
        $port = $parts['port'] ?? null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }

    public function installSigningKey(): void
    {
        $this->key->install();
    }

    public function publicSigningKey(): string
    {
        return $this->key->publicKey();
    }

    private function validateTarget(string $method, string $target): void
    {
        if (! in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || ! str_starts_with($target, '/v1/') || strlen($target) > 2048 || preg_match('/[\x00-\x20\x7f#\\\\]/', $target)) {
            throw new InvalidArgumentException('Use an uppercase HTTP method and an encoded /v1/ API path.');
        }
    }

    public function requestProof(string $method, string $target, #[\SensitiveParameter] string $body = '', string $origin = '', string $idempotency = '', string $ifMatch = ''): string
    {
        $this->validateTarget($method, $target);
        if (($idempotency !== '' && ! preg_match('/\A[\x21-\x7e]{1,160}\z/', $idempotency))
            || preg_match('/[\r\n\x00]/', $ifMatch) || strlen($ifMatch) > 100) {
            throw new InvalidArgumentException('Invalid idempotency key or If-Match value.');
        }
        if ($origin !== '') {
            $origin = $this->origin($origin);
        }
        $created = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $message = implode("\n", ['site-request-v1', $this->siteId, hash('sha256', $this->token),
            $method, $target, $origin, hash('sha256', $body), $idempotency, $ifMatch, $created, $nonce]);
        return $created.'.'.$nonce.'.'.$this->key->sign($message);
    }

    public function scriptUrl(string $editorOrigin): string
    {
        $origin = $this->origin($editorOrigin);
        $expires = (string) (time() + 3600);
        $signature = hash_hmac('sha256', "widget\n{$this->siteId}\n{$origin}\n{$expires}", hash('sha256', $this->token));
        return $this->url.'/widget/v1/ai-writer.js?'.http_build_query([
            'site' => $this->siteId, 'expires' => $expires, 'signature' => $signature,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function request(string $method, string $target, #[\SensitiveParameter] ?array $payload = null, array $headers = []): Response
    {
        $this->validateTarget($method, $target);
        if ($payload !== null && (in_array($method, ['GET', 'HEAD'], true) || ($payload !== [] && array_is_list($payload)))) {
            throw new InvalidArgumentException('Use a JSON object for API writes and put GET query parameters in the target path.');
        }
        $body = $payload === null ? '' : json_encode($payload === [] ? (object) [] : $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $allowed = ['idempotency-key' => 'Idempotency-Key', 'if-match' => 'If-Match', 'origin' => 'Origin'];
        $values = [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            if (! isset($allowed[$name]) || isset($values[$name]) || ! is_string($value) || preg_match('/[\r\n\x00]/', $value)) {
                throw new InvalidArgumentException('Only Idempotency-Key, If-Match and Origin may be supplied as API headers.');
            }
            $values[$name] = $value;
        }
        if (isset($values['origin'])) {
            $values['origin'] = $this->origin($values['origin']);
        }
        $proof = $this->requestProof($method, $target, $body, $values['origin'] ?? '', $values['idempotency-key'] ?? '', $values['if-match'] ?? '');
        $outgoing = ['Accept: application/json', 'Authorization: Bearer '.$this->token, 'X-Site-Proof: '.$proof];
        foreach ($values as $name => $value) {
            $outgoing[] = $allowed[$name].': '.$value;
        }
        if ($payload !== null) {
            $outgoing[] = 'Content-Type: application/json';
        }
        $curl = curl_init($this->url.$target);
        $responseBody = '';
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $outgoing,
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => $this->allowLocalhost ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > 8 * 1024 * 1024) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        if ($method === 'HEAD') {
            curl_setopt($curl, CURLOPT_NOBODY, true);
        }
        try {
            if (curl_exec($curl) === false) {
                throw new RuntimeException('CRM transport failed (cURL '.curl_errno($curl).').');
            }
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status >= 300 && $status < 400) {
                throw new RuntimeException('CRM redirects are not accepted. Configure its final HTTPS origin.');
            }
            return new Response($status, $responseBody);
        } finally {
            curl_close($curl);
        }
    }
}
