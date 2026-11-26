<?php

namespace NodeService\CrmAiPhp;

class SigningKey
{
    public function __construct(private readonly string $path) {}

    public function install(): void
    {
        $path = (string) $this->path;
        if (is_file($path)) {
            $this->publicKey();
            return;
        }
        // Some Windows PHP distributions do not ship a default openssl.cnf.
        $opensslConfig = tempnam(sys_get_temp_dir(), 'crm-openssl-');
        if ($opensslConfig === false) {
            throw new \RuntimeException('Cannot prepare OpenSSL configuration.');
        }
        try {
            if (file_put_contents($opensslConfig, "[req]\ndistinguished_name=dn\n[dn]\n") === false) {
                throw new \RuntimeException('Cannot prepare OpenSSL configuration.');
            }
            $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 2048, 'curve_name' => 'prime256v1', 'config' => $opensslConfig];
            $key = openssl_pkey_new($options);
            if ($key === false || ! openssl_pkey_export($key, $pem, null, $options)) {
                throw new \RuntimeException('Cannot generate the CRM signing key.');
            }
        } finally {
            unlink($opensslConfig);
        }
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            throw new \RuntimeException('Cannot create the private CRM signing directory.');
        }
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new \RuntimeException('The CRM signing key already exists or cannot be created.');
        }
        try {
            chmod($path, 0600);
            if (fwrite($file, $pem) !== strlen($pem) || ! fflush($file)) {
                throw new \RuntimeException('Cannot persist the CRM signing key.');
            }
        } finally {
            fclose($file);
        }
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $path = (string) $this->path;
        $pem = is_file($path) ? file_get_contents($path) : false;
        $key = is_string($pem) ? @openssl_pkey_get_private($pem) : false;
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($key === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
            throw new \RuntimeException('CRM signing key is unavailable. Run php vendor/bin/crm-ai install and register its public key.');
        }
        return $key;
    }

    public function publicKey(): string
    {
        return openssl_pkey_get_details($this->privateKey())['key'];
    }

    public function sign(#[\SensitiveParameter] string $message): string
    {
        if (! openssl_sign($message, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Cannot sign the CRM request.');
        }
        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
