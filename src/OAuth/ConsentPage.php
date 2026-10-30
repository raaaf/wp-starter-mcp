<?php

declare(strict_types=1);

namespace WpStarterMcp\OAuth;

use WpStarterMcp\Config;

/**
 * German consent, intent and error pages. Never names the client's self-declared name,
 * only the hosts of client ID and redirect.
 */
final class ConsentPage
{
    /** @param array<string, mixed> $result */
    public static function send(array $result, int $userId): void
    {
        $view = (string) ($result['view'] ?? 'error');
        $status = isset($result['status']) && is_int($result['status']) ? $result['status'] : 200;

        status_header($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");

        $body = match ($view) {
            'consent' => self::consent($result, $userId),
            'intent' => self::message(
                __('Verbindung zuerst in WordPress starten', 'wp-starter-mcp'),
                sprintf(
                    '%s <a href="%s">%s</a>',
                    esc_html__('Starten Sie die Verbindung bitte zuerst hier:', 'wp-starter-mcp'),
                    esc_url(admin_url(Config::SETUP_PAGE)),
                    esc_html__('Werkzeuge > Claude', 'wp-starter-mcp')
                )
            ),
            default => self::message(
                __('Anfrage nicht möglich', 'wp-starter-mcp'),
                esc_html(self::errorText((string) ($result['message'] ?? '')))
            ),
        };

        echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . esc_html(get_bloginfo('name')) . '</title>'
            . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:34rem;margin:3rem auto;padding:0 1rem;color:#1d2327}'
            . 'button{font:inherit;padding:.6rem 1.2rem;margin-right:.5rem;cursor:pointer}</style></head><body>'
            . $body . '</body></html>';
    }

    private static function errorText(string $key): string
    {
        return match ($key) {
            'forbidden' => __('Ihr Konto darf keine Seiten bearbeiten. Bitte wenden Sie sich an Ihre Administratorin oder Ihren Administrator.', 'wp-starter-mcp'),
            'expired' => __('Diese Seite ist abgelaufen. Bitte starten Sie die Verbindung in Claude erneut.', 'wp-starter-mcp'),
            default => __('Diese Anfrage ist ungültig. Bitte starten Sie die Verbindung in Claude erneut.', 'wp-starter-mcp'),
        };
    }

    private static function message(string $title, string $html): string
    {
        return '<main><h1>' . esc_html($title) . '</h1><p>' . $html . '</p></main>';
    }

    /** @param array<string, mixed> $result */
    private static function consent(array $result, int $userId): string
    {
        /** @var array<string, string> $request */
        $request = $result['request'];
        $user = get_userdata($userId);
        $client = ClientMap::host($request['client_id']);
        $redirect = ClientMap::host($request['redirect_uri']);

        $fields = '';
        foreach (['client_id', 'redirect_uri', 'code_challenge', 'resource', 'scope', 'state'] as $name) {
            $fields .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($request[$name]) . '">';
        }
        $fields .= '<input type="hidden" name="response_type" value="code"><input type="hidden" name="code_challenge_method" value="S256">';
        $fields .= wp_nonce_field((string) $result['nonce_action'], '_wpnonce', true, false);

        $can = [
            __('Seiten und Module dieser Website lesen', 'wp-starter-mcp'),
            __('Entwürfe von Seiten anlegen und Entwürfe ändern', 'wp-starter-mcp'),
            __('Die Mediathek durchsuchen und vorhandene Bilder einfügen', 'wp-starter-mcp'),
        ];
        $cannot = [
            __('Seiten veröffentlichen, löschen oder in den Papierkorb legen', 'wp-starter-mcp'),
            __('Einstellungen, Benutzer, Plugins oder Zugangsdaten ändern', 'wp-starter-mcp'),
        ];
        $list = static fn (array $items): string => '<ul><li>' . implode('</li><li>', array_map('esc_html', $items)) . '</li></ul>';

        return '<main><h1>' . esc_html__('Claude mit dieser Website verbinden', 'wp-starter-mcp') . '</h1>'
            . '<p>' . esc_html(sprintf(
                /* translators: 1: client host, 2: redirect host, 3: user name */
                __('%1$s (Rückkehr zu %2$s) möchte sich mit Ihrem Konto verbinden. Angemeldet als %3$s.', 'wp-starter-mcp'),
                $client,
                $redirect,
                $user === false ? '' : $user->display_name
            )) . '</p>'
            . '<h2>' . esc_html__('Claude darf', 'wp-starter-mcp') . '</h2>' . $list($can)
            . '<h2>' . esc_html__('Claude darf nicht', 'wp-starter-mcp') . '</h2>' . $list($cannot)
            . '<p>' . esc_html__('Sie können die Verbindung jederzeit unter Werkzeuge > Claude trennen.', 'wp-starter-mcp') . '</p>'
            . '<form method="post" action="' . esc_url(Config::authorizeUrl()) . '">' . $fields
            . '<button type="submit" name="decision" value="allow">' . esc_html__('Erlauben', 'wp-starter-mcp') . '</button>'
            . '<button type="submit" name="decision" value="deny">' . esc_html__('Ablehnen', 'wp-starter-mcp') . '</button>'
            . '</form></main>';
    }
}
