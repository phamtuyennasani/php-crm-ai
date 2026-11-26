<?php

namespace NodeService\CrmAiPhp;

use Closure;
use InvalidArgumentException;

final class Widget
{
    private readonly Closure $canWrite;
    private readonly string $origin;

    public function __construct(private readonly Client $client, string $editorOrigin, callable $canWrite)
    {
        $this->origin = $client->origin($editorOrigin);
        $this->canWrite = Closure::fromCallable($canWrite);
    }

    public static function csrfToken(array &$session): string
    {
        $key = 'nodeservice_crm_ai_csrf';
        if (! isset($session[$key]) || ! is_string($session[$key]) || ! preg_match('/\A[a-f0-9]{64}\z/', $session[$key])) {
            $session[$key] = bin2hex(random_bytes(32));
        }
        return $session[$key];
    }

    public function authorize(#[\SensitiveParameter] string $rawBody, #[\SensitiveParameter] string $expectedCsrf, #[\SensitiveParameter] string $providedCsrf): array
    {
        if (($this->canWrite)() !== true) {
            throw new AuthorizationException(403, 'Bạn cần đăng nhập và có quyền biên tập bài.');
        }
        if (strlen($expectedCsrf) < 32 || strlen($expectedCsrf) > 512 || ! hash_equals($expectedCsrf, $providedCsrf)) {
            throw new AuthorizationException(403, 'Phiên xác thực không hợp lệ. Tải lại trang để tiếp tục.');
        }
        if (strlen($rawBody) > 100000) {
            throw new AuthorizationException(413, 'Yêu cầu cấp chữ ký quá lớn.');
        }
        try {
            $input = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($input) || ! isset($input['body'], $input['id']) || ! is_string($input['body']) || ! is_string($input['id'])
                || strlen($input['body']) > 16000 || ! preg_match('/\A[\x21-\x7e]{1,160}\z/', $input['id'])) {
                throw new \JsonException();
            }
            $payload = json_decode($input['body'], true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || array_is_list($payload) || ! isset($payload['keyword'], $payload['model'])) {
                throw new \JsonException();
            }
        } catch (\JsonException) {
            throw new AuthorizationException(422, 'Yêu cầu cấp chữ ký không hợp lệ.');
        }
        return ['proof' => $this->client->requestProof('POST', '/v1/widget/'.$this->client->siteId.'/previews',
            $input['body'], $this->origin, $input['id'])];
    }

    public function render(array $options, #[\SensitiveParameter] string $csrfToken, #[\SensitiveParameter] array $ownCredentials = []): string
    {
        if (($this->canWrite)() !== true) {
            return '';
        }
        $authorizeUrl = $options['authorize_url'] ?? '';
        if (! is_string($authorizeUrl) || ! str_starts_with($authorizeUrl, '/') || str_starts_with($authorizeUrl, '//')
            || preg_match('/[\x00-\x20\x7f#\\\\]/', $authorizeUrl) || strlen($csrfToken) < 32 || strlen($csrfToken) > 512) {
            throw new InvalidArgumentException('Configure a same-origin authorization path and a session CSRF token.');
        }
        $fields = $options['fields'] ?? [];
        $allowed = ['Title', 'Content', 'Slug', 'Desc', 'Description', 'Keywords', 'FocusKeyword'];
        if (! is_array($fields) || $fields === [] || array_diff(array_keys($fields), $allowed) !== []
            || ! is_string($options['form'] ?? null) || $options['form'] === '') {
            throw new InvalidArgumentException('Configure the article form selector and at least one supported field.');
        }
        foreach ($fields as $selector) {
            if (! is_string($selector) || $selector === '' || strlen($selector) > 1000) {
                throw new InvalidArgumentException('Widget fields must contain CSS selector strings.');
            }
        }
        $own = CredentialsStore::validate($ownCredentials);
        $id = 'crm-ai-'.bin2hex(random_bytes(12));
        $config = [
            'mount' => '#'.$id, 'form' => $options['form'], 'fields' => $fields,
            'base_url' => $own['base_url'] ?? null, 'api_key' => $own['api_key'] ?? null, 'model' => $own['model'] ?? null,
        ];
        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $script = htmlspecialchars($this->client->scriptUrl($this->origin), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $authorize = htmlspecialchars($authorizeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrf = htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nonce = isset($options['nonce']) ? ' nonce="'.htmlspecialchars((string) $options['nonce'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"' : '';
        $mountId = json_encode($id);
        return <<<HTML
        <span id="{$id}"></span>
        <script src="{$script}" data-authorize-url="{$authorize}" data-csrf-token="{$csrf}"{$nonce}></script>
        <script{$nonce}>
        (() => {
            const start = () => {
                const mount = document.getElementById({$mountId});
                try {
                    if (!window.AIWriter) throw new Error('Widget unavailable');
                    window.AIWriter.mount({$json});
                } catch (_) {
                    mount.textContent = 'Chưa tải được công cụ viết AI. Kiểm tra cấu hình form hoặc tải lại trang.';
                }
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true});
            else start();
        })();
        </script>
        HTML;
    }
}
