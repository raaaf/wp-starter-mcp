<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script, terminal output only.
// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI script without WordPress.

// CLI only. The scripts folder is not part of the theme zip, the guard is
// for checkouts that sit in a web root.
if (\PHP_SAPI !== 'cli') {
    exit(1);
}

/**
 * Offline signing of release zips (Ed25519, ext-sodium).
 *
 * Run on the maintainer's own machine, never in CI: the private key must not
 * reach GitHub, otherwise a compromised account could sign its own update.
 *
 *   php scripts/release-signing.php keygen ~/.config/wp-starter/release.key
 *       Writes a new private key (mode 600) and prints the public key for
 *       ThemeUpdateProvider::RELEASE_PUBLIC_KEYS.
 *
 *   php scripts/release-signing.php sign wp-starter-1.2.3.zip ~/.config/wp-starter/release.key
 *       Writes wp-starter-1.2.3.zip.sig next to the zip. Upload it to the
 *       release: gh release upload v1.2.3 wp-starter-1.2.3.zip.sig
 *
 *   php scripts/release-signing.php verify wp-starter-1.2.3.zip <public key>
 *       Checks a zip and its .sig like the theme does before an update.
 */

/** @param array<int, string> $argv */
function releaseSigningMain(array $argv): int
{
    if (!function_exists('sodium_crypto_sign_detached')) {
        fwrite(STDERR, "ext-sodium fehlt.\n");

        return 1;
    }

    [$command, $first, $second] = array_pad(array_slice($argv, 1), 3, null);

    return match ($command) {
        'keygen' => $first !== null ? keygen($first) : usage(),
        'sign' => $first !== null && $second !== null ? sign($first, $second) : usage(),
        'verify' => $first !== null && $second !== null ? verify($first, $second) : usage(),
        default => usage(),
    };
}

function usage(): int
{
    fwrite(STDERR, "Aufruf:\n  release-signing.php keygen <private-key-datei>\n  release-signing.php sign <zip> <private-key-datei>\n  release-signing.php verify <zip> <public-key>\n");

    return 1;
}

function keygen(string $keyFile): int
{
    if (file_exists($keyFile)) {
        fwrite(STDERR, "{$keyFile} existiert schon, wird nicht ueberschrieben.\n");

        return 1;
    }

    $dir = dirname($keyFile);
    if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
        fwrite(STDERR, "Ordner {$dir} konnte nicht angelegt werden.\n");

        return 1;
    }

    $pair = sodium_crypto_sign_keypair();
    $old = umask(0o077);
    $written = file_put_contents($keyFile, base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
    umask($old);
    if ($written === false) {
        fwrite(STDERR, "{$keyFile} konnte nicht geschrieben werden.\n");

        return 1;
    }
    chmod($keyFile, 0o600);

    echo "Privater Schluessel: {$keyFile} (sichern, nie committen, nie in CI)\n";
    echo 'Oeffentlicher Schluessel fuer RELEASE_PUBLIC_KEYS: ' . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n";

    return 0;
}

function sign(string $zip, string $keyFile): int
{
    $secret = base64_decode(trim( (string) @file_get_contents($keyFile)), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "{$keyFile} enthaelt keinen gueltigen privaten Schluessel.\n");

        return 1;
    }

    $contents = @file_get_contents($zip);
    if ($contents === false) {
        fwrite(STDERR, "{$zip} ist nicht lesbar.\n");

        return 1;
    }

    $signature = base64_encode(sodium_crypto_sign_detached($contents, $secret));
    if (file_put_contents($zip . '.sig', $signature . "\n") === false) {
        fwrite(STDERR, "{$zip}.sig konnte nicht geschrieben werden.\n");

        return 1;
    }

    echo "Signatur: {$zip}.sig\n";
    echo 'Oeffentlicher Schluessel: ' . base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)) . "\n";

    return 0;
}

function verify(string $zip, string $publicKeyBase64): int
{
    $publicKey = base64_decode($publicKeyBase64, true);
    $signature = base64_decode(trim( (string) @file_get_contents($zip . '.sig')), true);
    $contents = @file_get_contents($zip);

    if (
        $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        || $contents === false
    ) {
        fwrite(STDERR, "Schluessel, Signatur oder Zip fehlen oder sind ungueltig.\n");

        return 1;
    }

    if (!sodium_crypto_sign_verify_detached($signature, $contents, $publicKey)) {
        fwrite(STDERR, "Signatur UNGUELTIG.\n");

        return 1;
    }

    echo "Signatur gueltig.\n";

    return 0;
}

exit(releaseSigningMain($argv));
