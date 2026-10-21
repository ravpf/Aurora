<?php
/**
 * First-run onboarding: environment check and plugin check.
 *
 * Two questions matter the moment the theme is switched on:
 *
 *   1. can this server run it (PHP, extensions, uploads, permalinks, database),
 *   2. is the business plugin (allin-pro-core) actually installed.
 *
 * When either answer is no, the admin is sent to the product page instead of a
 * half-working site — that page carries the download and the matching version.
 * The verdict is stored so the notice can keep explaining what failed after the
 * redirect, and so a later visit can re-check without switching the theme again.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where to send a site that is not ready.
 */
const AURORA_SETUP_URL = 'https://github.com/ravpf/Aurora/';

/**
 * Extensions the theme and the plugin actually use.
 *
 * @return array<string,string> extension => why
 */
function aurora_env_extensions() {
	return array(
		'mbstring' => __( '中文截断与标题处理', 'aurora' ),
		'curl'     => __( '调用 AI、短信与支付接口', 'aurora' ),
		'openssl'  => __( '微信支付签名与回调解密', 'aurora' ),
		'json'     => __( 'REST 接口与设置存取', 'aurora' ),
		'mysqli'   => __( '连接数据库', 'aurora' ),
		'zip'      => __( '上传主题 / 插件压缩包', 'aurora' ),
	);
}

/**
 * Run every check.
 *
 * Each item: key, label, ok, detail, hint, required.
 * Only items flagged required decide whether the site is usable.
 *
 * @return array<int,array{key:string,label:string,ok:bool,detail:string,hint:string,required:bool}>
 */
function aurora_env_checks() {
	global $wpdb;

	$upload = wp_upload_dir( null, false );
	$checks = array();

	$checks[] = array(
		'key'      => 'php',
		'label'    => __( 'PHP 版本', 'aurora' ),
		'ok'       => version_compare( PHP_VERSION, '8.1', '>=' ),
		'detail'   => PHP_VERSION,
		'hint'     => __( '需要 PHP 8.1 及以上（推荐 8.3）。', 'aurora' ),
		'required' => true,
	);

	$checks[] = array(
		'key'      => 'wordpress',
		'label'    => __( 'WordPress 版本', 'aurora' ),
		'ok'       => version_compare( get_bloginfo( 'version' ), '6.4', '>=' ),
		'detail'   => get_bloginfo( 'version' ),
		'hint'     => __( '需要 WordPress 6.4 及以上。', 'aurora' ),
		'required' => true,
	);

	$missing = array();
	foreach ( aurora_env_extensions() as $ext => $why ) {
		if ( ! extension_loaded( $ext ) ) {
			$missing[] = $ext;
			unset( $why );
		}
	}

	$checks[] = array(
		'key'      => 'extensions',
		'label'    => __( 'PHP 扩展', 'aurora' ),
		'ok'       => empty( $missing ),
		'detail'   => $missing
			? sprintf( /* translators: list of extensions */ __( '缺少：%s', 'aurora' ), implode( '、', $missing ) )
			: __( 'mbstring / curl / openssl / json / mysqli / zip 均已安装', 'aurora' ),
		'hint'     => __( 'Ubuntu：sudo apt install php8.3-mbstring php8.3-curl php8.3-zip', 'aurora' ),
		'required' => true,
	);

	$checks[] = array(
		'key'      => 'uploads',
		'label'    => __( '上传目录可写', 'aurora' ),
		'ok'       => ! empty( $upload['basedir'] ) && wp_is_writable( $upload['basedir'] ),
		'detail'   => $upload['basedir'] ?? '',
		'hint'     => __( '目录属主应为 Web 服务用户（如 www-data），权限 755。', 'aurora' ),
		'required' => true,
	);

	$checks[] = array(
		'key'      => 'database',
		'label'    => __( '数据库连接', 'aurora' ),
		'ok'       => (bool) $wpdb->check_connection( false ),
		'detail'   => defined( 'DB_NAME' ) ? DB_NAME : '',
		'hint'     => __( '检查 wp-config.php 里的数据库账号与主机。', 'aurora' ),
		'required' => true,
	);

	$permalinks = (string) get_option( 'permalink_structure', '' );

	$checks[] = array(
		'key'      => 'permalinks',
		'label'    => __( '固定链接', 'aurora' ),
		'ok'       => '' !== $permalinks,
		'detail'   => '' !== $permalinks ? $permalinks : __( '仍是默认的 ?p=123', 'aurora' ),
		'hint'     => __( '设置 → 固定链接，选「文章名」或自定义后保存一次。', 'aurora' ),
		'required' => true,
	);

	$checks[] = array(
		'key'      => 'memory',
		'label'    => __( 'PHP 内存上限', 'aurora' ),
		'ok'       => wp_convert_hr_to_bytes( WP_MEMORY_LIMIT ) >= 128 * MB_IN_BYTES,
		'detail'   => WP_MEMORY_LIMIT,
		'hint'     => __( '建议 256M：图片处理与大列表查询会用到。', 'aurora' ),
		'required' => false,
	);

	$checks[] = array(
		'key'      => 'https',
		'label'    => __( 'HTTPS', 'aurora' ),
		'ok'       => is_ssl() || 0 === strpos( home_url(), 'https://' ),
		'detail'   => is_ssl() ? __( '已启用', 'aurora' ) : __( '当前是 HTTP', 'aurora' ),
		'hint'     => __( 'PWA 离线缓存与支付回调都要求 HTTPS，上线前务必配好证书。', 'aurora' ),
		'required' => false,
	);

	/**
	 * Filter the onboarding checks, so a host-specific requirement can be added.
	 *
	 * @param array $checks Checks.
	 */
	return apply_filters( 'aurora_env_checks', $checks );
}

/**
 * Is the business plugin present?
 *
 * @return bool
 */
function aurora_plugin_ready() {
	if ( class_exists( 'AllinPro_Settings' ) || class_exists( 'AllinPro_Modules' ) ) {
		return true;
	}

	if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'allin-pro-core/allin-pro-core.php' ) ) {
		return true;
	}

	$plugins = (array) get_option( 'active_plugins', array() );

	foreach ( $plugins as $plugin ) {
		if ( false !== strpos( (string) $plugin, 'allin-pro-core' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Plugin basename when the plugin is on disk, whether or not it is active.
 *
 * "Not installed" and "installed but switched off" need different answers: the
 * first sends someone to the download page, the second only needs a click.
 *
 * @return string Empty when the plugin is not on disk.
 */
function aurora_plugin_file() {
	$candidates = array(
		'allin-pro-core/allin-pro-core.php',
	);

	foreach ( $candidates as $candidate ) {
		if ( file_exists( WP_PLUGIN_DIR . '/' . $candidate ) ) {
			return $candidate;
		}
	}

	// A hand-unzipped copy may sit under a different folder name.
	$dirs = glob( WP_PLUGIN_DIR . '/*/allin-pro-core.php' );

	if ( $dirs ) {
		return ltrim( str_replace( WP_PLUGIN_DIR . '/', '', $dirs[0] ), '/' );
	}

	return '';
}

/**
 * Full report.
 *
 * @return array{ok:bool,items:array,failed:array,plugin:bool,ready:bool}
 */
function aurora_env_report() {
	$items  = aurora_env_checks();
	$failed = array();

	foreach ( $items as $item ) {
		if ( $item['required'] && ! $item['ok'] ) {
			$failed[] = $item;
		}
	}

	$plugin = aurora_plugin_ready();

	$file = aurora_plugin_file();

	return array(
		'ok'        => empty( $failed ),
		'items'     => $items,
		'failed'    => $failed,
		'plugin'    => $plugin,
		'plugin_file' => $plugin ? '' : $file,
		'ready'     => empty( $failed ) && $plugin,
	);
}

/**
 * Stored verdict from the last activation.
 *
 * @return array
 */
function aurora_onboard_state() {
	$state = get_option( 'aurora_onboard', array() );

	return is_array( $state ) ? $state : array();
}

/**
 * Remember the verdict and, when something is missing, send the admin to the
 * product page. Runs once per site: the flag keeps a later theme switch from
 * bouncing someone who already knows what they are doing.
 */
function aurora_onboard_should_redirect( $report ) {
	if ( ! empty( $report['ready'] ) ) {
		return false;
	}

	// One hop per site: someone who came back and switched the theme on purpose
	// gets the admin, not the same page again.
	return ! get_option( 'aurora_onboard_followed' );
}

/**
 * Store the verdict, then bounce a first-time install to the product page when
 * the site cannot run yet (or the plugin is missing).
 */
function aurora_onboard_activate() {
	$report = aurora_env_report();

	update_option(
		'aurora_onboard',
		array(
			'time'   => time(),
			'ok'     => $report['ok'],
			'plugin' => $report['plugin'],
			'ready'  => $report['ready'],
			'failed' => wp_list_pluck( $report['failed'], 'label' ),
		),
		false
	);

	/*
	 * Activation does not always happen where this callback can redirect: WordPress
	 * raises the hook inside switch_theme(), and the request that runs it may have
	 * no theme functions loaded at all. So leave a marker and let the next admin
	 * request do the hop — that request is guaranteed to be a full page with
	 * headers still unsent.
	 */
	if ( ! $report['ready'] ) {
		update_option( 'aurora_onboard_pending', 1, false );
	} else {
		delete_option( 'aurora_onboard_pending' );
	}

	// A CLI activation only records the verdict; there is no browser to send.
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	if ( aurora_onboard_should_redirect( $report ) ) {
		update_option( 'aurora_onboard_followed', time(), false );
		delete_option( 'aurora_onboard_pending' );
		wp_redirect( AURORA_SETUP_URL ); // phpcs:ignore WordPress.Security.SafeRedirect -- external product page by design.
		exit;
	}
}
add_action( 'after_switch_theme', 'aurora_onboard_activate' );

/**
 * The hop itself, on the first admin page load after activation.
 *
 * Runs at admin_init: no output yet, so a redirect is always legal here — unlike
 * inside the theme switch, where the request may already be past that point.
 */
function aurora_onboard_maybe_redirect() {
	if ( ! get_option( 'aurora_onboard_pending' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$report = aurora_env_report();

	if ( $report['ready'] ) {
		delete_option( 'aurora_onboard_pending' );
		return;
	}

	if ( ! aurora_onboard_should_redirect( $report ) ) {
		delete_option( 'aurora_onboard_pending' );
		return;
	}

	update_option( 'aurora_onboard_followed', time(), false );
	delete_option( 'aurora_onboard_pending' );

	wp_redirect( AURORA_SETUP_URL ); // phpcs:ignore WordPress.Security.SafeRedirect -- external product page by design.
	exit;
}
add_action( 'admin_init', 'aurora_onboard_maybe_redirect', 5 );

/**
 * Tell the admin what failed, once, with a link back to the checks.
 */
function aurora_onboard_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$state = aurora_onboard_state();

	if ( ! $state || ! empty( $state['ready'] ) || empty( $state['time'] ) ) {
		return;
	}

	// After three days the site is either fixed or the owner knows about it.
	if ( time() - (int) $state['time'] > 3 * DAY_IN_SECONDS ) {
		return;
	}

	$missing = array();

	if ( empty( $state['ok'] ) ) {
		$missing = (array) ( $state['failed'] ?? array() );
	}

	$lines = array();

	if ( $missing ) {
		$lines[] = sprintf(
			/* translators: list of failed checks */
			__( '环境未通过：%s', 'aurora' ),
			implode( '、', $missing )
		);
	}

	if ( empty( $state['plugin'] ) ) {
		$lines[] = function_exists( 'aurora_plugin_file' ) && aurora_plugin_file()
			? __( '业务插件 allin-pro-core 已安装但未启用（登录、支付、余额等能力由它提供）', 'aurora' )
			: __( '未检测到业务插件 allin-pro-core（登录、支付、余额等能力由它提供）', 'aurora' );
	}
	?>
	<div class="notice notice-warning">
		<p><strong><?php esc_html_e( 'Aurora 安装检查', 'aurora' ); ?></strong>
			<?php echo esc_html( implode( '；', $lines ) ); ?></p>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=aurora-status' ) ); ?>"><?php esc_html_e( '查看详细自检', 'aurora' ); ?></a>
			<a class="button button-primary" href="<?php echo esc_url( AURORA_SETUP_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '打开下载与安装说明', 'aurora' ); ?></a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'aurora_onboard_notice' );

/**
 * A slim banner inside the status screen: plugin state and a re-check button.
 */
function aurora_onboard_status_block() {
	$report = aurora_env_report();
	$state  = aurora_onboard_state();
	?>
	<h2><?php esc_html_e( '业务插件', 'aurora' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<tr>
				<td style="width:280px"><strong><?php esc_html_e( 'allin-pro-core', 'aurora' ); ?></strong></td>
				<td>
					<?php if ( $report['plugin'] ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span>
						<?php esc_html_e( '已安装并启用', 'aurora' ); ?>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=allin-pro&tab=modules' ) ); ?>"><?php esc_html_e( '去开关功能模块', 'aurora' ); ?></a>
					<?php elseif ( ! empty( $report['plugin_file'] ) ) : ?>
						<span class="dashicons dashicons-warning" style="color:#dba617"></span>
						<?php esc_html_e( '已安装，但还没有启用', 'aurora' ); ?>
						<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $report['plugin_file'] ) ), 'activate-plugin_' . $report['plugin_file'] ) ); ?>"><?php esc_html_e( '立即启用', 'aurora' ); ?></a>
					<?php else : ?>
						<span class="dashicons dashicons-warning" style="color:#dba617"></span>
						<?php esc_html_e( '未安装', 'aurora' ); ?>
						<a href="<?php echo esc_url( AURORA_SETUP_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '下载与安装说明', 'aurora' ); ?></a>
					<?php endif; ?>
					<?php if ( $state ) : ?>
						<div class="description">
							<?php
							printf(
								/* translators: 1: date, 2: verdict */
								esc_html__( '上次激活检查：%1$s · %2$s', 'aurora' ),
								esc_html( wp_date( 'Y-m-d H:i', (int) $state['time'] ) ),
								esc_html( ! empty( $state['ready'] ) ? __( '通过', 'aurora' ) : __( '未通过', 'aurora' ) )
							);
							?>
						</div>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
	<?php
}

/**
 * Re-run the checks from the status screen (?aurora_recheck=1) and refresh the
 * stored verdict so the notice reflects reality.
 */
function aurora_onboard_maybe_recheck() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( empty( $_GET['aurora_recheck'] ) || ! isset( $_GET['_wpnonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'aurora_recheck' ) ) {
		return;
	}

	$report = aurora_env_report();

	update_option(
		'aurora_onboard',
		array(
			'time'   => time(),
			'ok'     => $report['ok'],
			'plugin' => $report['plugin'],
			'ready'  => $report['ready'],
			'failed' => wp_list_pluck( $report['failed'], 'label' ),
		),
		false
	);

	wp_safe_redirect( admin_url( 'admin.php?page=aurora-status&rechecked=1' ) );
	exit;
}
add_action( 'admin_init', 'aurora_onboard_maybe_recheck' );
