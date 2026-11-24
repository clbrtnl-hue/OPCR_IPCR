<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use phpseclib4\Crypt\RSA;
use RuntimeException;
use Throwable;

class PassportSetup
{
    /**
     * Passport refuses to sign a login token unless this pair exists.
     * The files stay out of git, so a new server has to create them itself.
     */
    public static function ensure(): void
    {
        self::preferUsableKeys();
        self::ensureKeyFiles();

        try {
            self::ensurePersonalAccessClient();
        } catch (QueryException) {
            // The database is not up yet. The next request tries again.
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function preferUsableKeys(): void
    {
        $private = self::configuredKey('private');
        $public = self::configuredKey('public');

        if ($private !== null && $public !== null && self::contentsAreKey($private) && self::contentsAreKey($public)) {
            config([
                'passport.private_key' => $private,
                'passport.public_key' => $public,
            ]);

            return;
        }

        config([
            'passport.private_key' => null,
            'passport.public_key' => null,
        ]);
    }

    private static function configuredKey(string $type): ?string
    {
        $key = config("passport.{$type}_key");

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return str_replace('\\n', "\n", $key);
    }

    private static function ensureKeyFiles(): void
    {
        $private = Passport::keyPath('oauth-private.key');
        $public = Passport::keyPath('oauth-public.key');

        if (self::fileIsKey($private) && self::fileIsKey($public)) {
            return;
        }

        $directory = storage_path('framework');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $lock = fopen($directory.DIRECTORY_SEPARATOR.'passport-keys.lock', 'c');
        if ($lock === false) {
            self::writeKeyPair($public, $private);

            return;
        }

        try {
            flock($lock, LOCK_EX);
            clearstatcache();

            if (self::fileIsKey($private) && self::fileIsKey($public)) {
                return;
            }

            self::writeKeyPair($public, $private);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function writeKeyPair(string $public, string $private): void
    {
        $directory = dirname($private);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $key = RSA::createKey(4096);
        self::putKey($public.'.tmp', (string) $key->getPublicKey(), 0660);
        self::putKey($private.'.tmp', (string) $key, 0600);
        rename($public.'.tmp', $public);
        rename($private.'.tmp', $private);
    }

    private static function putKey(string $path, string $contents, int $mode): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Could not write the Passport key file.');
        }

        if (! windows_os()) {
            chmod($path, $mode);
        }
    }

    private static function ensurePersonalAccessClient(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('oauth_clients')) {
            return;
        }

        $provider = (string) config('auth.guards.api.provider');
        $clients = app(ClientRepository::class);

        try {
            $clients->personalAccessClient($provider);
        } catch (RuntimeException) {
            // A wiped database leaves the old marker behind, so the client
            // is checked every boot and created again when it is missing.
            $clients->createPersonalAccessGrantClient('OCC PMS', $provider);
        }
    }

    private static function fileIsKey(string $path): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $contents = file_get_contents($path);

        return is_string($contents) && str_contains($contents, '-----BEGIN ');
    }

    private static function contentsAreKey(string $contents): bool
    {
        if (! str_contains($contents, '-----BEGIN ')) {
            return false;
        }

        $private = openssl_pkey_get_private($contents);

        if ($private instanceof \OpenSSLAsymmetricKey) {
            return true;
        }

        return openssl_pkey_get_public($contents) instanceof \OpenSSLAsymmetricKey;
    }
}
