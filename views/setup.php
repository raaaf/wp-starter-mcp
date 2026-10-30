<?php
/**
 * Tools > Claude.
 *
 * @var bool $is_admin
 * @var bool $enabled
 * @var string $notice
 * @var bool $notice_ok
 * @var bool $below_minimum
 * @var string $contract_message
 * @var list<array<string, mixed>> $checks
 * @var list<array<string, mixed>> $connections
 * @var list<array<string, mixed>> $audit
 * @var bool $intent_fresh
 * @var string $mcp_url
 * @var bool $delete_data
 * @var string $version
 * @var string $probe_url
 * @var string $discovery_url
 * @var string $action_url
 * @var string $date_format
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$when = static fn (int $time): string => $time > 0 ? wp_date($date_format, $time) : '';
$form = static function (string $action, string $label, string $class = 'button', string $extra = '', string $aria = '') use ($action_url): void {
    echo '<form method="post" action="' . esc_url($action_url) . '" style="display:inline">';
    echo '<input type="hidden" name="action" value="wpsmcp_' . esc_attr($action) . '">';
    wp_nonce_field('wpsmcp_' . $action);
    echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts below.
    echo '<button type="submit" class="' . esc_attr($class) . '"' . ($aria !== '' ? ' aria-label="' . esc_attr($aria) . '"' : '') . '>' . esc_html($label) . '</button></form>';
};
?>
<div class="wrap">
    <h1><?php echo esc_html__('Claude', 'wp-starter-mcp'); ?></h1>

    <?php if ($notice !== '') : ?>
        <div class="notice <?php echo $notice_ok ? 'notice-success' : 'notice-error'; ?>" role="<?php echo $notice_ok ? 'status' : 'alert'; ?>"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <?php if ($below_minimum) : ?>
        <div class="notice notice-error" role="alert"><p><?php echo esc_html__('Diese Plugin-Version ist veraltet und aus Sicherheitsgründen abgeschaltet. Bitte aktualisieren Sie das Plugin unter Plugins.', 'wp-starter-mcp'); ?></p></div>
    <?php endif; ?>

    <?php if ($is_admin) : ?>
        <h2><?php echo esc_html__('Prüfung dieser Website', 'wp-starter-mcp'); ?></h2>
        <table class="widefat striped" id="wpsmcp-checks">
            <tbody>
            <?php foreach ($checks as $check) : ?>
                <tr<?php echo isset($check['probe']) ? ' data-probe="' . esc_attr((string) $check['probe']) . '"' : ''; ?>>
                    <td><?php echo esc_html((string) $check['label']); ?></td>
                    <td role="status" data-state>
                        <?php if ($check['ok'] === true) : ?>
                            <?php echo esc_html__('In Ordnung', 'wp-starter-mcp'); ?>
                        <?php elseif ($check['ok'] === false) : ?>
                            <strong><?php echo esc_html__('Nicht in Ordnung', 'wp-starter-mcp'); ?></strong>
                        <?php elseif (($check['probe'] ?? '') === 'off') : ?>
                            <?php echo esc_html__('Wird nach dem Einschalten geprüft', 'wp-starter-mcp'); ?>
                        <?php else : ?>
                            <?php echo esc_html__('Wird geprüft', 'wp-starter-mcp'); ?>
                        <?php endif; ?>
                    </td>
                    <td data-fix<?php echo $check['ok'] === true || $check['ok'] === null ? ' hidden' : ''; ?>><?php echo esc_html((string) $check['fix']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php echo esc_html__('Anbindung ein- oder ausschalten', 'wp-starter-mcp'); ?></h2>
        <p role="status" id="wpsmcp-switch"><?php echo $enabled ? esc_html__('Die Claude-Anbindung ist eingeschaltet.', 'wp-starter-mcp') : esc_html__('Die Claude-Anbindung ist ausgeschaltet.', 'wp-starter-mcp'); ?></p>
        <?php if ($enabled) : ?>
            <?php $form('disable', __('Ausschalten und alle Verbindungen trennen', 'wp-starter-mcp')); ?>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="action" value="wpsmcp_enable">
                <?php wp_nonce_field('wpsmcp_enable'); ?>
                <p><label><input type="checkbox" name="ack_transfer" value="1">
                    <?php echo esc_html__('Ich weiß, dass Inhalte dieser Website, die Claude liest, an Anthropic übertragen werden. Eine Auftragsverarbeitung muss geklärt sein.', 'wp-starter-mcp'); ?></label></p>
                <p><label><input type="checkbox" name="ack_updates" value="1">
                    <?php echo esc_html__('Ich weiß, dass sich dieses Plugin bei eingeschalteter Anbindung automatisch aktualisiert.', 'wp-starter-mcp'); ?></label></p>
                <button type="submit" class="button button-primary"><?php echo esc_html__('Einschalten', 'wp-starter-mcp'); ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <h2><?php echo esc_html__('Mit Claude verbinden', 'wp-starter-mcp'); ?></h2>
    <?php if (!$enabled) : ?>
        <p><?php echo esc_html__('Die Claude-Anbindung ist noch nicht eingeschaltet. Bitte fragen Sie Ihre Administratorin oder Ihren Administrator.', 'wp-starter-mcp'); ?></p>
    <?php else : ?>
        <?php if ($contract_message !== '') : ?>
            <div class="notice notice-warning inline" role="alert"><p><?php echo esc_html($contract_message); ?></p></div>
        <?php endif; ?>
        <?php $form('start', __('Verbindung starten', 'wp-starter-mcp'), 'button button-primary'); ?>
        <?php if ($intent_fresh) : ?>
            <p><?php echo esc_html__('Diese Adresse in Claude einfügen:', 'wp-starter-mcp'); ?></p>
            <p><input type="text" id="wpsmcp-url" class="large-text code" readonly value="<?php echo esc_attr($mcp_url); ?>" aria-label="<?php echo esc_attr__('Adresse für Claude', 'wp-starter-mcp'); ?>">
                <button type="button" class="button" id="wpsmcp-copy"><?php echo esc_html__('Adresse kopieren', 'wp-starter-mcp'); ?></button>
                <span role="status" id="wpsmcp-copied"></span></p>
        <?php endif; ?>
        <ol>
            <li><?php echo esc_html__('Klicken Sie auf "Verbindung starten" und kopieren Sie die Adresse.', 'wp-starter-mcp'); ?></li>
            <li><?php echo esc_html__('Öffnen Sie in Claude die Einstellungen und dort "Konnektoren". Mit dem kostenlosen Tarif können Sie einen eigenen Konnektor hinzufügen. In Team und Enterprise fügt die Inhaberin oder der Inhaber des Kontos ihn hinzu.', 'wp-starter-mcp'); ?></li>
            <li><?php echo esc_html__('Wählen Sie "Eigenen Konnektor hinzufügen", fügen Sie die Adresse ein und bestätigen Sie.', 'wp-starter-mcp'); ?></li>
            <li><?php echo esc_html__('Melden Sie sich bei WordPress an und klicken Sie auf "Erlauben".', 'wp-starter-mcp'); ?></li>
        </ol>
        <h3><?php echo esc_html__('Beispiele für Anfragen', 'wp-starter-mcp'); ?></h3>
        <ul>
            <li><?php echo esc_html__('Lege eine neue Seite "Über uns" als Entwurf an, mit Einleitung, Team und Kontakt.', 'wp-starter-mcp'); ?></li>
            <li><?php echo esc_html__('Erstelle eine Kopie der Seite "Leistungen" und kürze die Texte um die Hälfte.', 'wp-starter-mcp'); ?></li>
            <li><?php echo esc_html__('Welche Module gibt es, und welches passt für eine Preisübersicht?', 'wp-starter-mcp'); ?></li>
        </ul>
    <?php endif; ?>

    <h2><?php echo esc_html($is_admin ? __('Alle Verbindungen', 'wp-starter-mcp') : __('Ihre Verbindungen', 'wp-starter-mcp')); ?></h2>
    <?php if ($connections === []) : ?>
        <p><?php echo esc_html__('Es gibt keine Verbindung.', 'wp-starter-mcp'); ?></p>
    <?php else : ?>
        <ul>
            <?php foreach ($connections as $c) : ?>
                <?php
                $label = sprintf(
                    /* translators: 1: connect date, 2: last use */
                    __('Verbunden am %1$s, zuletzt genutzt: %2$s', 'wp-starter-mcp'),
                    $when((int) $c['created_at']),
                    (int) $c['last_used_at'] > 0 ? $when((int) $c['last_used_at']) : __('noch nie', 'wp-starter-mcp')
                );
                if ($is_admin && $c['user'] !== '') {
                    $label = $c['user'] . ': ' . $label;
                }
                ?>
                <li><?php echo esc_html($label); ?>
                    <?php $form('revoke', __('Trennen', 'wp-starter-mcp'), 'button', '<input type="hidden" name="family" value="' . esc_attr((string) $c['family_id']) . '">', sprintf(__('Verbindung vom %s trennen', 'wp-starter-mcp'), $when((int) $c['created_at']))); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($is_admin) : ?>
        <h2><?php echo esc_html__('Protokoll', 'wp-starter-mcp'); ?></h2>
        <?php if ($audit === []) : ?>
            <p><?php echo esc_html__('Noch keine Einträge. Das Protokoll hält 90 Tage.', 'wp-starter-mcp'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__('Zeit', 'wp-starter-mcp'); ?></th><th><?php echo esc_html__('Benutzer', 'wp-starter-mcp'); ?></th>
                    <th><?php echo esc_html__('Ereignis', 'wp-starter-mcp'); ?></th><th><?php echo esc_html__('Seite', 'wp-starter-mcp'); ?></th><th><?php echo esc_html__('Client', 'wp-starter-mcp'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($audit as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($when((int) $row['created_at'])); ?></td><td><?php echo esc_html((string) $row['user']); ?></td>
                        <td><?php echo esc_html((string) $row['event']); ?></td><td><?php echo (int) $row['post_id'] > 0 ? (int) $row['post_id'] : ''; ?></td>
                        <td><?php echo esc_html((string) $row['client_host']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?php echo esc_html__('Daten', 'wp-starter-mcp'); ?></h2>
        <form method="post" action="<?php echo esc_url($action_url); ?>">
            <input type="hidden" name="action" value="wpsmcp_delete_data">
            <?php wp_nonce_field('wpsmcp_delete_data'); ?>
            <p><label><input type="checkbox" name="delete_data" value="1" <?php checked($delete_data); ?>>
                <?php echo esc_html__('Beim Löschen des Plugins alle Daten entfernen (Verbindungen, Protokoll, Einstellungen).', 'wp-starter-mcp'); ?></label></p>
            <button type="submit" class="button"><?php echo esc_html__('Speichern', 'wp-starter-mcp'); ?></button>
        </form>
        <p><small><?php echo esc_html(sprintf(__('Version %s', 'wp-starter-mcp'), $version)); ?></small></p>
    <?php endif; ?>
</div>
<script>
(function () {
    var copy = document.getElementById('wpsmcp-copy');
    if (copy) {
        copy.addEventListener('click', function () {
            var field = document.getElementById('wpsmcp-url');
            var done = function () { document.getElementById('wpsmcp-copied').textContent = <?php echo wp_json_encode(__('Kopiert', 'wp-starter-mcp')); ?>; };
            field.select();
            if (navigator.clipboard) { navigator.clipboard.writeText(field.value).then(done); } else { document.execCommand('copy'); done(); }
        });
    }
    var verdict = function (row, ok) {
        row.querySelector('[data-state]').textContent = ok ? <?php echo wp_json_encode(__('In Ordnung', 'wp-starter-mcp')); ?> : <?php echo wp_json_encode(__('Nicht in Ordnung', 'wp-starter-mcp')); ?>;
        row.querySelector('[data-fix]').hidden = ok;
    };
    var probes = {
        authorization: function (row) {
            fetch(<?php echo wp_json_encode($probe_url); ?>, { headers: { Authorization: 'Bearer wpsmcp-probe' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); }).then(function (d) { verdict(row, d.authorization === true); })
                .catch(function () { verdict(row, false); });
        },
        wellknown: function (row) {
            fetch(<?php echo wp_json_encode($discovery_url); ?>)
                .then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { verdict(row, !!d && d.client_id_metadata_document_supported === true); })
                .catch(function () { verdict(row, false); });
        }
    };
    document.querySelectorAll('[data-probe]').forEach(function (row) {
        var run = probes[row.getAttribute('data-probe')];
        if (run) { run(row); }
    });
})();
</script>
