<?php

namespace NodeService\CrmAiPhp\Tests;

use NodeService\CrmAiPhp\AuthorizationException;
use NodeService\CrmAiPhp\Client;
use NodeService\CrmAiPhp\CredentialsStore;
use NodeService\CrmAiPhp\Response;
use NodeService\CrmAiPhp\Widget;
use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase
{
    private string $directory;
    private Client $client;
    private const SITE = '11111111-1111-4111-8111-111111111111';
    private const TOKEN = 'standalone-server-token-fixture';
    private const ORIGIN = 'https://editor.example.test';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/php-crm-test-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->client = new Client('https://crm.example.test', self::SITE, self::TOKEN, $this->directory.'/signing.pem');
        $this->client->installSigningKey();
    }

    protected function tearDown(): void
    {
        $resolved = realpath($this->directory);
        if ($resolved && realpath(dirname($resolved)) === realpath(sys_get_temp_dir()) && str_starts_with(basename($resolved), 'php-crm-test-')) {
            foreach (glob($resolved.'/*') as $file) {
                unlink($file);
            }
            rmdir($resolved);
        }
    }

    private function verifyProof(string $proof, string $target, string $body, string $id = '', string $origin = self::ORIGIN, string $method = 'POST', string $ifMatch = ''): int
    {
        [$created, $nonce, $signature] = explode('.', $proof);
        $message = implode("\n", ['site-request-v1', self::SITE, hash('sha256', self::TOKEN),
            $method, $target, $origin, hash('sha256', $body), $id, $ifMatch, $created, $nonce]);
        return openssl_verify($message, base64_decode(strtr($signature, '-_', '+/')), $this->client->publicSigningKey(), OPENSSL_ALGO_SHA256);
    }

    public function test_keys_are_preserved_and_proofs_bind_exact_bytes_headers_and_target(): void
    {
        $original = file_get_contents($this->directory.'/signing.pem');
        $this->client->installSigningKey();
        $this->assertSame($original, file_get_contents($this->directory.'/signing.pem'));
        $target = '/v1/schedules/one?filter=a%20b';
        $body = '{"keyword":"nội dung"}';
        $proof = $this->client->requestProof('PATCH', $target, $body, self::ORIGIN, 'id-one', '"2"');
        $this->assertSame(1, $this->verifyProof($proof, $target, $body, 'id-one', self::ORIGIN, 'PATCH', '"2"'));
        $this->assertSame(0, $this->verifyProof($proof, $target, $body.' ', 'id-one', self::ORIGIN, 'PATCH', '"2"'));
        $this->assertSame(0, $this->verifyProof($proof, $target, $body, 'id-two', self::ORIGIN, 'PATCH', '"2"'));
        $this->assertNotSame($proof, $this->client->requestProof('PATCH', $target, $body, self::ORIGIN, 'id-one', '"2"'));
        ob_start();
        var_dump($this->client);
        $this->assertStringNotContainsString(self::TOKEN, ob_get_clean());
    }

    public function test_configuration_and_cli_preserve_signing_and_encryption_keys(): void
    {
        $path = $this->directory.'/config.json';
        file_put_contents($path, json_encode(['url' => 'https://crm.example.test', 'site_id' => self::SITE, 'token' => self::TOKEN]));
        // Use the installer's default filename to keep this config independent of the test key.
        $expectedPublicKey = $this->client->publicSigningKey();
        rename($this->directory.'/signing.pem', $this->directory.'/crm-signing.pem');
        $client = Client::fromFile($path);
        $this->assertSame($expectedPublicKey, $client->publicSigningKey());
        $original = file_get_contents($this->directory.'/crm-signing.pem');
        foreach ([1, 2] as $attempt) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/crm-ai', 'install', '--config='.$path],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $output);
            $this->assertStringNotContainsString(self::TOKEN, $output);
            $this->assertSame($original, file_get_contents($this->directory.'/crm-signing.pem'));
            if ($attempt === 1) $encryptionKey = file_get_contents($this->directory.'/crm-ai-credentials.key');
            $this->assertSame($encryptionKey, file_get_contents($this->directory.'/crm-ai-credentials.key'));
        }
    }

    public function test_public_origins_require_https_and_loopback_http_is_explicit(): void
    {
        foreach (['http://public.example.com', 'https://user:pass@crm.example.com', 'https://crm.example.com/path', 'https://crm.example.com?token=x'] as $url) {
            try {
                new Client($url, self::SITE, self::TOKEN, $this->directory.'/signing.pem', true);
                $this->fail('Unsafe CRM URL must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $local = new Client('http://127.0.0.1:3210', self::SITE, self::TOKEN, $this->directory.'/signing.pem', true);
        $this->assertStringStartsWith('http://127.0.0.1:3210/widget/', $local->scriptUrl('http://localhost:8000'));
        $this->assertSame('https://editor.example.test', $this->client->origin('https://EDITOR.example.test:443/'));
    }

    public function test_widget_requires_editor_authorization_and_csrf_then_signs_only_its_preview_endpoint(): void
    {
        $session = [];
        $csrf = Widget::csrfToken($session);
        $this->assertSame($csrf, Widget::csrfToken($session));
        $body = '{"keyword":"nội dung","model":"model"}';
        $raw = json_encode(['body' => $body, 'id' => 'widget-one', 'target' => '/v1/somewhere-else']);
        $widget = new Widget($this->client, self::ORIGIN, fn () => true);
        $proof = $widget->authorize($raw, $csrf, $csrf)['proof'];
        $this->assertSame(1, $this->verifyProof($proof, '/v1/widget/'.self::SITE.'/previews', $body, 'widget-one'));
        foreach ([new Widget($this->client, self::ORIGIN, fn () => false), $widget] as $current) {
            try {
                $current->authorize($raw, $csrf, str_repeat('0', 64));
                $this->fail('Authorization and CSRF must be enforced.');
            } catch (AuthorizationException $error) {
                $this->assertSame(403, $error->status);
            }
        }
        $this->assertSame('', (new Widget($this->client, self::ORIGIN, fn () => false))->render([], $csrf));
        $this->expectException(AuthorizationException::class);
        $widget->authorize(json_encode(['body' => '[]', 'id' => 'bad']), $csrf, $csrf);
    }

    public function test_widget_html_hides_site_token_and_escapes_credentials_and_selectors(): void
    {
        $widget = new Widget($this->client, self::ORIGIN, fn () => true);
        $session = [];
        $html = $widget->render(['authorize_url' => '/authorize.php', 'form' => '#form', 'fields' => ['Title' => '[name=title]']], Widget::csrfToken($session),
            ['base_url' => 'https://ai.example.com/v1', 'api_key' => '</script><script>alert(1)</script>', 'model' => 'model']);
        $this->assertStringContainsString('data-csrf-token=', $html);
        $this->assertStringContainsString('data-authorize-url="/authorize.php"', $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);
        $this->assertStringNotContainsString('</script><script>alert', $html);
        $this->assertStringContainsString('\\u003C', $html);
        $this->assertStringContainsString('window.AIWriter.mount', $html);
    }

    public function test_encrypted_credentials_keep_blank_keys_remove_explicitly_and_detect_tampering(): void
    {
        $store = new CredentialsStore($this->directory.'/own.enc', $this->directory.'/own.key');
        $store->install();
        $fields = ['base_url' => 'https://ai.example.com/v1/', 'api_key' => 'secret-own-fixture', 'model' => 'vendor/model'];
        $store->save($fields);
        $this->assertStringNotContainsString($fields['api_key'], file_get_contents($this->directory.'/own.enc'));
        $store->save([...$fields, 'api_key' => '', 'model' => 'changed']);
        $this->assertSame('secret-own-fixture', $store->read()['api_key']);
        $this->assertSame('https://ai.example.com/v1', $store->read()['base_url']);
        $store->save([]);
        $this->assertSame([], $store->read());
        file_put_contents($this->directory.'/own.enc', 'v1.'.base64_encode(random_bytes(40)));
        $this->expectException(\RuntimeException::class);
        $store->read();
    }

    public function test_missing_encryption_key_cannot_silently_replace_existing_credentials(): void
    {
        $store = new CredentialsStore($this->directory.'/own.enc', $this->directory.'/own.key');
        $store->install();
        $store->save([]);
        unlink($this->directory.'/own.key');
        $this->expectException(\RuntimeException::class);
        $store->install();
    }

    public function test_api_request_rejects_header_overrides_and_wrong_paths_before_transport(): void
    {
        foreach ([['POST', '/v1/article-jobs', ['Authorization' => 'override']], ['POST', '//evil.example/v1/jobs', []], ['POST', '/v1/jobs', ['Idempotency-Key' => "bad\r\nvalue"]]] as [$method, $target, $headers]) {
            try {
                $this->client->request($method, $target, [], $headers);
                $this->fail('Invalid targets or caller-controlled authentication headers must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_response_supports_article_arrays_and_status_checks(): void
    {
        $response = new Response(200, '[{"Title":"Full article"}]');
        $this->assertTrue($response->successful());
        $this->assertSame('Full article', $response->json()[0]['Title']);
        $this->assertFalse((new Response(401, '{}'))->successful());
        $this->expectException(\RuntimeException::class);
        (new Response(502, '<html>Invalid response</html>'))->json();
    }
}
