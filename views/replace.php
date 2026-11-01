<?php
/**
 * Confirmation page for ReplaceOriginal.
 *
 * @var array<string, mixed>|string $plan
 * @var string $error
 * @var string $nonce
 * @var string $post_url
 * @var string $list_url
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php echo esc_html__('Original ersetzen', 'wp-starter-mcp'); ?></h1>
    <?php if ($error !== '') : ?>
        <div class="notice notice-error" role="alert"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>
    <?php if (is_array($plan)) : ?>
        <p><?php echo esc_html(sprintf(
            /* translators: %s: page title */
            __('Die Seite "%s" wird durch den Entwurf ersetzt. Die Seite ist danach sofort öffentlich in der neuen Fassung sichtbar.', 'wp-starter-mcp'),
            (string) $plan['title']
        )); ?></p>
        <p>
            <a href="<?php echo esc_url((string) $plan['preview_url']); ?>"><?php echo esc_html__('Entwurf ansehen', 'wp-starter-mcp'); ?></a>
            |
            <a href="<?php echo esc_url((string) $plan['live_url']); ?>"><?php echo esc_html__('Aktuelle Live-Seite ansehen', 'wp-starter-mcp'); ?></a>
        </p>
        <?php if ($plan['changed']) : ?>
            <div class="notice notice-warning inline" role="alert"><p><?php echo esc_html__('Achtung: Die Originalseite wurde geändert, seit der Entwurf erstellt wurde. Diese Änderungen gehen verloren, wenn Sie ersetzen. Sie bleiben im gesicherten vorherigen Stand erhalten.', 'wp-starter-mcp'); ?></p></div>
        <?php endif; ?>
        <h2><?php echo esc_html__('Entfallende Abschnitte', 'wp-starter-mcp'); ?></h2>
        <?php if ($plan['removed'] === []) : ?>
            <p><?php echo esc_html__('Es entfallen keine Abschnitte.', 'wp-starter-mcp'); ?></p>
        <?php else : ?>
            <ul><?php foreach ($plan['removed'] as $name) : ?><li><?php echo esc_html((string) $name); ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <h2><?php echo esc_html__('Neue externe Adressen', 'wp-starter-mcp'); ?></h2>
        <?php if ($plan['new_hosts'] === []) : ?>
            <p><?php echo esc_html__('Es kommen keine neuen externen Adressen hinzu.', 'wp-starter-mcp'); ?></p>
        <?php else : ?>
            <ul><?php foreach ($plan['new_hosts'] as $host) : ?><li><?php echo esc_html((string) $host); ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <p><?php echo esc_html__('Der bisherige Stand wird vorher als Version gesichert und kann wiederhergestellt werden.', 'wp-starter-mcp'); ?></p>
        <form method="post" action="<?php echo esc_url($post_url); ?>">
            <input type="hidden" name="action" value="wpsmcp_replace">
            <input type="hidden" name="copy" value="<?php echo (int) $plan['copy']; ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
            <button type="submit" class="button button-primary"><?php echo esc_html__('Original ersetzen', 'wp-starter-mcp'); ?></button>
            <a class="button" href="<?php echo esc_url($list_url); ?>"><?php echo esc_html__('Abbrechen', 'wp-starter-mcp'); ?></a>
        </form>
    <?php else : ?>
        <p><a class="button" href="<?php echo esc_url($list_url); ?>"><?php echo esc_html__('Zurück zu den Seiten', 'wp-starter-mcp'); ?></a></p>
    <?php endif; ?>
</div>
