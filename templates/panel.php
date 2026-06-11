<?php
/**
 * Standalone panel shell. Rendered by WFCP_Router outside the theme and
 * wp-admin: no admin header/footer, no Gutenberg, no widgets, no emoji.
 *
 * @var array $boot Boot configuration (see WFCP_Router::boot_config()).
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', $boot['locale'] ) ); ?>" dir="<?php echo $boot['rtl'] ? 'rtl' : 'ltr'; ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#6750a4">
<title><?php echo esc_html( $boot['siteName'] ); ?> – <?php esc_html_e( 'Store Panel', 'wfcp' ); ?></title>
<link rel="manifest" href="<?php echo esc_url( $boot['panelUrl'] . 'manifest.webmanifest' ); ?>">
<link rel="icon" href="<?php echo esc_url( WFCP_URL . 'assets/img/icon.svg' ); ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?php echo esc_url( $boot['assets']['css'] ); ?>">
<script>window.WFCP = <?php echo wp_json_encode( $boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script>
</head>
<body class="wfcp-loading">
<div id="app" aria-live="polite">
	<div class="boot-spinner" role="status" aria-label="<?php esc_attr_e( 'Loading…', 'wfcp' ); ?>"></div>
</div>
<?php foreach ( $boot['assets']['js'] as $src ) : ?>
<script src="<?php echo esc_url( $src ); ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
