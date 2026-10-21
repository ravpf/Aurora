<?php
/**
 * Paid downloads.
 *
 * A post can carry downloadable files behind three gates: membership level,
 * a one-off money purchase (the existing single-article price), or a points
 * unlock.
 *
 * The security property that matters: files live in
 * uploads/aurora-downloads/, which nginx refuses to serve directly. The only
 * way to the bytes is aurora_serve_download(), which checks entitlement first.
 * Putting them in the ordinary media library would leave the gate cosmetic —
 * anyone who guessed or was shown the URL would bypass it entirely.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

const AURORA_DL_DIR      = 'aurora-downloads';
const AURORA_DL_UNLOCKED = 'aurora_dl_unlocked';

/* -------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------- */

/**
 * Where protected files live.
 *
 * @return array {basedir, baseurl}
 */
function aurora_dl_dir() {
	$up = wp_upload_dir();

	return array(
		'basedir' => trailingslashit( $up['basedir'] ) . AURORA_DL_DIR,
		'baseurl' => trailingslashit( $up['baseurl'] ) . AURORA_DL_DIR,
	);
}

/**
 * Create the protected directory, with a web-server guard inside it.
 *
 * @return string|WP_Error Directory path.
 */
function aurora_dl_ensure_dir() {
	$dir = aurora_dl_dir();

	if ( ! wp_mkdir_p( $dir['basedir'] ) ) {
		return new WP_Error( 'aurora_dl_mkdir', __( '无法创建下载目录，请检查上传目录权限。', 'aurora' ) );
	}

	// Belt and braces: the nginx rule is the real gate, but if this site is ever
	// served by Apache, .htaccess keeps the files unreachable too.
	$htaccess = $dir['basedir'] . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
	}

	$index = $dir['basedir'] . '/index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php\n// Silence is golden.\n" );
	}

	return $dir['basedir'];
}

/**
 * The files attached to a post.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function aurora_dl_files( $post_id ) {
	$files = get_post_meta( $post_id, '_aurora_files', true );

	if ( ! is_array( $files ) ) {
		return array();
	}

	$dir = aurora_dl_dir();

	foreach ( $files as $i => $file ) {
		if ( ! isset( $file['path'] ) ) {
			unset( $files[ $i ] );
			continue;
		}

		$abs = $dir['basedir'] . '/' . $file['path'];

		$files[ $i ] = wp_parse_args( $file, array(
			'name'  => basename( $file['path'] ),
			'size'  => file_exists( $abs ) ? filesize( $abs ) : 0,
			'hits'  => 0,
			'added' => '',
		) );

		$files[ $i ]['exists'] = file_exists( $abs );
	}

	return array_values( $files );
}

/**
 * Does this post sell downloads at all?
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function aurora_dl_enabled( $post_id ) {
	return (bool) aurora_dl_files( $post_id );
}

/**
 * Points needed to unlock this post's downloads (0 = not sold for points).
 *
 * @param int $post_id Post ID.
 * @return int
 */
function aurora_dl_points_price( $post_id ) {
	return max( 0, (int) get_post_meta( $post_id, '_aurora_files_points', true ) );
}

/**
 * Membership level that includes these downloads (0 = none).
 *
 * @param int $post_id Post ID.
 * @return int
 */
function aurora_dl_vip_level( $post_id ) {
	return max( 0, (int) get_post_meta( $post_id, '_aurora_files_vip', true ) );
}

/**
 * Human file size.
 *
 * @param int $bytes Bytes.
 * @return string
 */
function aurora_dl_size( $bytes ) {
	$bytes = (int) $bytes;

	if ( $bytes < 1024 ) {
		return $bytes . ' B';
	}

	$units = array( 'KB', 'MB', 'GB' );
	$i     = -1;

	do {
		$bytes /= 1024;
		$i++;
	} while ( $bytes >= 1024 && $i < count( $units ) - 1 );

	return round( $bytes, $bytes >= 10 ? 0 : 1 ) . ' ' . $units[ $i ];
}

/* -------------------------------------------------------------------------
 * Entitlement
 * ---------------------------------------------------------------------- */

/**
 * Has this user already unlocked the downloads with points?
 *
 * @param int $user_id User ID.
 * @param int $post_id Post ID.
 * @return bool
 */
function aurora_dl_unlocked_with_points( $user_id, $post_id ) {
	$map = get_user_meta( (int) $user_id, AURORA_DL_UNLOCKED, true );

	return is_array( $map ) && isset( $map[ (int) $post_id ] );
}

/**
 * May this user download the files on this post?
 *
 * The order is deliberate: privileges first, then membership, then purchases,
 * then points. Every branch is a positive grant; anything else is denied.
 *
 * @param int $post_id Post ID.
 * @param int $user_id User ID, 0 for current.
 * @return bool
 */
function aurora_user_can_download( $post_id, $user_id = 0 ) {
	$post_id = (int) $post_id;
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $post_id || ! aurora_dl_enabled( $post_id ) ) {
		return false;
	}

	// Staff and the author are not locked out of their own material.
	if ( $user_id ) {
		if ( user_can( $user_id, 'edit_others_posts' ) ) {
			return true;
		}
		if ( (int) get_post_field( 'post_author', $post_id ) === $user_id ) {
			return true;
		}
	}

	$vip = aurora_dl_vip_level( $post_id );
	if ( $vip > 0 && aurora_get_user_level( $user_id ) >= $vip ) {
		return true;
	}

	// A money purchase of the article covers its files.
	if ( aurora_post_is_paid( $post_id ) && aurora_user_owns_post( $user_id, $post_id ) ) {
		return true;
	}

	if ( $user_id && aurora_dl_unlocked_with_points( $user_id, $post_id ) ) {
		return true;
	}

	return (bool) apply_filters( 'aurora_user_can_download', false, $post_id, $user_id );
}

/**
 * Has the user earned this file by something other than their daily allowance?
 *
 * The allowance exists to stop anonymous-ish scraping of free resources, not to
 * ration things someone paid for, spent points on, or has by membership. Those
 * paths stay unlimited.
 *
 * @param int $post_id Post ID.
 * @param int $user_id User ID.
 * @return bool
 */
function aurora_dl_is_entitled( $post_id, $user_id = 0 ) {
	$post_id = (int) $post_id;
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( $user_id && user_can( $user_id, 'edit_others_posts' ) ) {
		return true;
	}
	if ( $user_id && (int) get_post_field( 'post_author', $post_id ) === $user_id ) {
		return true;
	}

	$vip = aurora_dl_vip_level( $post_id );
	if ( $vip > 0 && aurora_get_user_level( $user_id ) >= $vip ) {
		return true;
	}
	if ( aurora_post_is_paid( $post_id ) && aurora_user_owns_post( $user_id, $post_id ) ) {
		return true;
	}
	if ( $user_id && aurora_dl_unlocked_with_points( $user_id, $post_id ) ) {
		return true;
	}

	return false;
}

/**
 * How many free downloads a user has already taken today.
 *
 * Counts the log rows the handler writes, so the number cannot drift from the
 * audit trail the administrator sees in the backend.
 *
 * @param int $user_id User ID.
 * @return int
 */
function aurora_dl_count_today( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return 0;
	}

	$rows = get_posts( array(
		'post_type'      => 'aurora_download',
		'post_status'    => 'any',
		'author'         => $user_id,
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'date_query'     => array(
			array(
				'after'     => current_time( 'Y-m-d 00:00:00' ),
				'inclusive' => true,
			),
		),
	) );

	return count( $rows );
}

/**
 * Spend points to unlock a post's downloads.
 *
 * @param int $post_id Post ID.
 * @param int $user_id User ID.
 * @return true|WP_Error
 */
function aurora_dl_unlock_with_points( $post_id, $user_id = 0 ) {
	$post_id = (int) $post_id;
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return new WP_Error( 'aurora_dl_no_user', __( '请先登录。', 'aurora' ) );
	}

	if ( aurora_user_can_download( $post_id, $user_id ) ) {
		return true;   // already has it, do not charge twice
	}

	$price = aurora_dl_points_price( $post_id );

	if ( $price <= 0 ) {
		return new WP_Error( 'aurora_dl_not_for_points', __( '该资源不支持积分兑换。', 'aurora' ) );
	}

	$spent = aurora_points_spend(
		$user_id,
		$price,
		'download',
		array( 'ref' => $post_id, 'note' => sprintf( __( '兑换资源：%s', 'aurora' ), get_the_title( $post_id ) ) )
	);

	if ( is_wp_error( $spent ) ) {
		return $spent;
	}

	$map              = get_user_meta( $user_id, AURORA_DL_UNLOCKED, true );
	$map              = is_array( $map ) ? $map : array();
	$map[ $post_id ]  = current_time( 'mysql' );

	update_user_meta( $user_id, AURORA_DL_UNLOCKED, $map );

	do_action( 'aurora_download_unlocked', $post_id, $user_id, $price );

	return true;
}

/* -------------------------------------------------------------------------
 * Serving
 * ---------------------------------------------------------------------- */

/**
 * Resolve a file entry to an absolute path inside the protected directory.
 *
 * Returns an error rather than a path for anything that escapes the directory,
 * so the caller never has to decide what to do about a suspicious entry. The
 * comparison appends the separator deliberately: a bare prefix test would accept
 * a sibling directory such as "aurora-downloads-backup/".
 *
 * @param array $file File entry from post meta.
 * @return string|WP_Error Absolute path, or an error.
 */
function aurora_dl_resolve( $file ) {
	$dir = aurora_dl_dir();

	if ( empty( $file['path'] ) ) {
		return new WP_Error( 'aurora_dl_missing', __( '文件不存在。', 'aurora' ) );
	}

	// realpath() collapses any ".." the entry may contain, and also fails
	// outright for a path that does not exist.
	$real = realpath( $dir['basedir'] . '/' . $file['path'] );
	$base = realpath( $dir['basedir'] );

	if ( ! $real || ! $base ) {
		return new WP_Error( 'aurora_dl_missing', __( '文件不存在。', 'aurora' ) );
	}

	if ( 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
		return new WP_Error( 'aurora_dl_outside', __( '文件路径无效。', 'aurora' ) );
	}

	if ( ! is_readable( $real ) ) {
		return new WP_Error( 'aurora_dl_unreadable', __( '文件不可读，请联系管理员。', 'aurora' ) );
	}

	return $real;
}

/**
 * Stream a file to an entitled user.
 *
 * Reached through admin-post.php so logged-out visitors can be redirected to
 * the login screen rather than shown an empty response.
 */
function aurora_serve_download() {
	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	$index   = isset( $_GET['file'] ) ? (int) $_GET['file'] : 0;

	if ( ! $post_id ) {
		wp_die( esc_html__( '下载参数无效。', 'aurora' ), '', array( 'response' => 400 ) );
	}

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( get_permalink( $post_id ) ) );
		exit;
	}

	if ( ! aurora_user_can_download( $post_id ) ) {
		wp_die( esc_html__( '你没有权限下载该资源。', 'aurora' ), '', array( 'response' => 403 ) );
	}

	// The permission matrix sits on top of that: `download` can close the door
	// for a role, and `download_daily` is the free allowance.
	$gate = aurora_require_cap( 'download' );
	if ( is_wp_error( $gate ) ) {
		wp_die( esc_html( $gate->get_error_message() ), '', array( 'response' => 403 ) );
	}

	$allowance = aurora_download_allowance();
	if ( $allowance > 0 && ! aurora_dl_is_entitled( $post_id, get_current_user_id() ) ) {
		$used = aurora_dl_count_today( get_current_user_id() );
		if ( $used >= $allowance ) {
			wp_die(
				sprintf(
					/* translators: %d: daily allowance */
					esc_html__( '今天的免费下载次数已用完（%d 次），明天再来；也可以购买或使用积分解锁该资源。', 'aurora' ),
					(int) $allowance
				),
				esc_html__( '下载次数已达上限', 'aurora' ),
				array( 'response' => 429 )
			);
		}
	}

	$files = aurora_dl_files( $post_id );

	if ( ! isset( $files[ $index ] ) ) {
		wp_die( esc_html__( '文件不存在。', 'aurora' ), '', array( 'response' => 404 ) );
	}

	$file = $files[ $index ];

	// Meta lives in the database and could have been edited by hand, so the
	// target is re-derived and confined to the protected directory here.
	$real = aurora_dl_resolve( $file );

	if ( is_wp_error( $real ) ) {
		wp_die( esc_html( $real->get_error_message() ), '', array( 'response' => 404 ) );
	}

	aurora_dl_log( $post_id, $index, $file );

	// Count the hit on the file entry itself.
	$stored = get_post_meta( $post_id, '_aurora_files', true );
	if ( is_array( $stored ) && isset( $stored[ $index ] ) ) {
		$stored[ $index ]['hits'] = (int) ( isset( $stored[ $index ]['hits'] ) ? $stored[ $index ]['hits'] : 0 ) + 1;
		update_post_meta( $post_id, '_aurora_files', $stored );
	}

	while ( ob_get_level() ) {
		ob_end_clean();
	}

	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Length: ' . filesize( $real ) );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file['name'] ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	// Large files: do not let PHP or the web server cut the transfer short.
	set_time_limit( 0 );

	readfile( $real );
	exit;
}
add_action( 'admin_post_aurora_download', 'aurora_serve_download' );
add_action( 'admin_post_nopriv_aurora_download', 'aurora_serve_download' );

/**
 * The download URL for a file.
 *
 * @param int $post_id Post ID.
 * @param int $index   File index.
 * @return string
 */
function aurora_dl_url( $post_id, $index ) {
	return admin_url( 'admin-post.php?action=aurora_download&post=' . (int) $post_id . '&file=' . (int) $index );
}

/* -------------------------------------------------------------------------
 * Download log
 * ---------------------------------------------------------------------- */

/**
 * Register the log type, so "my downloads" is just a query.
 */
function aurora_register_download_log() {
	register_post_type( 'aurora_download', array(
		'labels'          => array(
			'name'          => __( '下载记录', 'aurora' ),
			'singular_name' => __( '下载记录', 'aurora' ),
			'all_items'     => __( '下载记录', 'aurora' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => 'aurora-points',
		'capability_type' => 'post',
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'    => true,
		'supports'        => array( 'title' ),
		'show_in_rest'    => false,
	) );
}
add_action( 'init', 'aurora_register_download_log' );

/**
 * Record a download.
 *
 * @param int   $post_id Post ID.
 * @param int   $index   File index.
 * @param array $file    File entry.
 */
function aurora_dl_log( $post_id, $index, $file ) {
	$user_id = get_current_user_id();

	wp_insert_post( array(
		'post_type'   => 'aurora_download',
		'post_status' => 'publish',
		'post_title'  => $file['name'],
		'post_author' => $user_id ? $user_id : 0,
		'meta_input'  => array(
			'_aurora_dl_post'  => (int) $post_id,
			'_aurora_dl_index' => (int) $index,
			'_aurora_dl_size'  => (int) $file['size'],
			'_aurora_dl_ip'    => isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '',
		),
	) );

	do_action( 'aurora_download_served', $post_id, $index, $user_id );
}

/**
 * A user's download history, newest first.
 *
 * @param int $user_id User ID.
 * @param int $limit   Rows.
 * @return array
 */
function aurora_dl_history( $user_id = 0, $limit = 20 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return array();
	}

	$rows = array();

	foreach ( get_posts( array(
		'post_type'      => 'aurora_download',
		'post_status'    => 'publish',
		'author'         => $user_id,
		'posts_per_page' => (int) $limit,
		'orderby'        => 'ID',
		'order'          => 'DESC',
	) ) as $p ) {
		$post_id = (int) get_post_meta( $p->ID, '_aurora_dl_post', true );

		$rows[] = array(
			'file'   => $p->post_title,
			'post'   => $post_id,
			'title'  => $post_id ? get_the_title( $post_id ) : '',
			'link'   => $post_id ? get_permalink( $post_id ) : '',
			'size'   => (int) get_post_meta( $p->ID, '_aurora_dl_size', true ),
			'date'   => get_the_date( 'Y-m-d H:i', $p ),
		);
	}

	return $rows;
}

/* -------------------------------------------------------------------------
 * AJAX unlock
 * ---------------------------------------------------------------------- */

/**
 * Unlock downloads with points from the front end.
 */
function aurora_ajax_download_unlock() {
	aurora_check_nonce();
	aurora_rate_json_guard( 'download' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 200 );
	}

	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

	if ( ! $post_id ) {
		wp_send_json_error( array( 'message' => __( '参数无效。', 'aurora' ) ), 400 );
	}

	$out = aurora_dl_unlock_with_points( $post_id );

	if ( is_wp_error( $out ) ) {
		wp_send_json_error( array( 'message' => $out->get_error_message() ), 200 );
	}

	$files = aurora_dl_files( $post_id );

	wp_send_json_success( array(
		'message' => __( '兑换成功，可以下载了。', 'aurora' ),
		'points'  => aurora_points_balance(),
		'files'   => array_map( function ( $f, $i ) use ( $post_id ) {
			return array(
				'name' => $f['name'],
				'size' => aurora_dl_size( $f['size'] ),
				'url'  => aurora_dl_url( $post_id, $i ),
			);
		}, $files, array_keys( $files ) ),
	) );
}
add_action( 'wp_ajax_aurora_download_unlock', 'aurora_ajax_download_unlock' );

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

/**
 * Metabox for the download settings.
 */
function aurora_dl_metabox() {
	add_meta_box(
		'aurora-downloads',
		__( 'Aurora 资源下载', 'aurora' ),
		'aurora_dl_metabox_html',
		array( 'post', 'page' ),
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'aurora_dl_metabox' );

/**
 * Metabox body: gates, file list, upload form.
 *
 * @param WP_Post $post Post.
 */
function aurora_dl_metabox_html( $post ) {
	wp_nonce_field( 'aurora_dl_meta', 'aurora_dl_nonce' );

	$files   = aurora_dl_files( $post->ID );
	$points  = aurora_dl_points_price( $post->ID );
	$vip     = aurora_dl_vip_level( $post->ID );
	$levels  = function_exists( 'aurora_vip_levels' ) ? aurora_vip_levels() : array();
	$max_up  = size_format( wp_max_upload_size() );
	$action  = admin_url( 'admin-post.php' );
	?>
	<p>
		<label for="aurora_dl_points"><strong><?php esc_html_e( '积分兑换价', 'aurora' ); ?></strong></label>
		<input type="number" min="0" step="1" id="aurora_dl_points" name="aurora_dl_points"
			value="<?php echo esc_attr( $points ); ?>" class="small-text">
		<?php esc_html_e( '分（0 = 不能用积分兑换）', 'aurora' ); ?>
	</p>
	<p>
		<label for="aurora_dl_vip"><strong><?php esc_html_e( '会员免费等级', 'aurora' ); ?></strong></label>
		<select id="aurora_dl_vip" name="aurora_dl_vip">
			<option value="0"><?php esc_html_e( '不限制（仅购买/积分）', 'aurora' ); ?></option>
			<?php foreach ( $levels as $index => $info ) : ?>
				<option value="<?php echo esc_attr( $index ); ?>" <?php selected( $vip, $index ); ?>>
					<?php echo esc_html( sprintf( __( '%s 及以上免费', 'aurora' ), $info['name'] ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>
	<p class="description">
		<?php esc_html_e( '文件存放在 uploads/aurora-downloads/，该目录被服务器禁止直接访问，只能通过主题的下载接口取，因此下载链接无法被绕过或转发给无权限的人。', 'aurora' ); ?>
	</p>

	<?php if ( $files ) : ?>
		<table class="widefat striped" style="margin:10px 0">
			<thead>
				<tr>
					<th><?php esc_html_e( '文件名', 'aurora' ); ?></th>
					<th style="width:90px"><?php esc_html_e( '大小', 'aurora' ); ?></th>
					<th style="width:70px"><?php esc_html_e( '下载', 'aurora' ); ?></th>
					<th style="width:80px"><?php esc_html_e( '操作', 'aurora' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $files as $i => $file ) : ?>
				<tr>
					<td>
						<?php echo esc_html( $file['name'] ); ?>
						<?php if ( ! $file['exists'] ) : ?>
							<em style="color:#b32d2e"><?php esc_html_e( '（磁盘上找不到）', 'aurora' ); ?></em>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( aurora_dl_size( $file['size'] ) ); ?></td>
					<td><?php echo (int) $file['hits']; ?></td>
					<td>
						<button type="submit" name="aurora_dl_remove" value="<?php echo esc_attr( $i ); ?>"
							class="button button-small"
							onclick="return confirm('<?php echo esc_js( __( '确定删除这个文件？', 'aurora' ) ); ?>')">
							<?php esc_html_e( '删除', 'aurora' ); ?>
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p><em><?php esc_html_e( '还没有文件。', 'aurora' ); ?></em></p>
	<?php endif; ?>

	<p>
		<input type="file" name="aurora_files[]" multiple>
		<button type="submit" class="button" formaction="<?php echo esc_url( $action ); ?>"
			name="aurora_dl_upload" value="1" formmethod="post" formenctype="multipart/form-data">
			<?php esc_html_e( '上传文件', 'aurora' ); ?>
		</button>
	</p>
	<input type="hidden" name="action" value="aurora_dl_upload_file">
	<input type="hidden" name="post_id" value="<?php echo esc_attr( $post->ID ); ?>">
	<p class="description">
		<?php
		printf(
			/* translators: %s: max upload size */
			esc_html__( '单文件上限 %s。', 'aurora' ),
			esc_html( $max_up )
		);
		?>
	</p>
	<?php
}

/**
 * Save the gates.
 *
 * @param int $post_id Post ID.
 */
function aurora_dl_save_meta( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['aurora_dl_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_key( $_POST['aurora_dl_nonce'] ), 'aurora_dl_meta' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['aurora_dl_points'] ) ) {
		$points = max( 0, (int) $_POST['aurora_dl_points'] );
		if ( $points > 0 ) {
			update_post_meta( $post_id, '_aurora_files_points', $points );
		} else {
			delete_post_meta( $post_id, '_aurora_files_points' );
		}
	}

	if ( isset( $_POST['aurora_dl_vip'] ) ) {
		$vip = max( 0, (int) $_POST['aurora_dl_vip'] );
		if ( $vip > 0 ) {
			update_post_meta( $post_id, '_aurora_files_vip', $vip );
		} else {
			delete_post_meta( $post_id, '_aurora_files_vip' );
		}
	}
}
add_action( 'save_post', 'aurora_dl_save_meta' );

/**
 * Handle an upload from the metabox.
 */
function aurora_handle_dl_upload() {
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'aurora_dl_meta', 'aurora_dl_nonce' );

	$dir = aurora_dl_ensure_dir();
	if ( is_wp_error( $dir ) ) {
		wp_die( esc_html( $dir->get_error_message() ), '', array( 'response' => 500 ) );
	}

	$files  = aurora_dl_files( $post_id );
	$errors = array();

	if ( ! empty( $_FILES['aurora_files']['name'][0] ) ) {
		$count = count( $_FILES['aurora_files']['name'] );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( UPLOAD_ERR_OK !== (int) $_FILES['aurora_files']['error'][ $i ] ) {
				$errors[] = sanitize_file_name( $_FILES['aurora_files']['name'][ $i ] );
				continue;
			}

			$single = array(
				'name'     => $_FILES['aurora_files']['name'][ $i ],
				'type'     => $_FILES['aurora_files']['type'][ $i ],
				'tmp_name' => $_FILES['aurora_files']['tmp_name'][ $i ],
				'error'    => $_FILES['aurora_files']['error'][ $i ],
				'size'     => $_FILES['aurora_files']['size'][ $i ],
			);

			$moved = aurora_dl_place_file( $single, $dir );

			if ( is_wp_error( $moved ) ) {
				$errors[] = $single['name'];
				continue;
			}

			$files[] = $moved;
		}
	}

	update_post_meta( $post_id, '_aurora_files', $files );

	wp_safe_redirect( add_query_arg(
		array(
			'post'         => $post_id,
			'action'       => 'edit',
			'aurora_files' => empty( $errors ) ? 'ok' : 'partial',
		),
		admin_url( 'post.php' )
	) );
	exit;
}
add_action( 'admin_post_aurora_dl_upload_file', 'aurora_handle_dl_upload' );

/**
 * Move one uploaded file into the protected directory under a safe name.
 *
 * @param array  $single $_FILES entry.
 * @param string $dir    Target directory.
 * @return array|WP_Error File entry, or an error.
 */
function aurora_dl_place_file( $single, $dir ) {
	// Reject anything PHP might later execute from inside the upload tree.
	$check = wp_check_filetype_and_ext( $single['tmp_name'], $single['name'] );

	$ext = strtolower( pathinfo( $single['name'], PATHINFO_EXTENSION ) );
	$ban = array( 'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pht', 'shtml', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'sh', 'htaccess' );

	if ( in_array( $ext, $ban, true ) ) {
		return new WP_Error( 'aurora_dl_banned_ext', __( '不允许上传该类型文件。', 'aurora' ) );
	}

	if ( '' === $ext ) {
		return new WP_Error( 'aurora_dl_no_ext', __( '文件必须带扩展名。', 'aurora' ) );
	}

	$name = sanitize_file_name( wp_basename( $single['name'] ) );

	// Never overwrite: two uploads of "package.zip" must both survive.
	$target = $dir . '/' . $name;
	$n      = 2;
	while ( file_exists( $target ) ) {
		$target = $dir . '/' . pathinfo( $name, PATHINFO_FILENAME ) . '-' . $n . '.' . $ext;
		$n++;
	}

	if ( ! @move_uploaded_file( $single['tmp_name'], $target ) ) {
		return new WP_Error( 'aurora_dl_move', __( '文件写入失败。', 'aurora' ) );
	}

	@chmod( $target, 0640 );

	if ( $check['ext'] ) {
		// Consumed for its side effect of validating the extension; keep the
		// call so a mislabelled binary is still flagged by WordPress.
		unset( $check );
	}

	return array(
		'name'  => wp_basename( $target ),
		'path'  => wp_basename( $target ),
		'size'  => filesize( $target ),
		'hits'  => 0,
		'added' => current_time( 'mysql' ),
	);
}

/**
 * Delete a file when the metabox asks.
 *
 * @param int $post_id Post ID.
 */
function aurora_dl_maybe_remove_file( $post_id ) {
	if ( ! isset( $_POST['aurora_dl_remove'] ) || ! isset( $_POST['aurora_dl_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_key( $_POST['aurora_dl_nonce'] ), 'aurora_dl_meta' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$index = (int) $_POST['aurora_dl_remove'];
	$files = aurora_dl_files( $post_id );

	if ( ! isset( $files[ $index ] ) ) {
		return;
	}

	$dir = aurora_dl_dir();
	$abs = $dir['basedir'] . '/' . $files[ $index ]['path'];

	if ( file_exists( $abs ) ) {
		@unlink( $abs );
	}

	unset( $files[ $index ] );
	update_post_meta( $post_id, '_aurora_files', array_values( $files ) );
}
add_action( 'save_post', 'aurora_dl_maybe_remove_file', 20 );

/**
 * Tell the editor whether the last upload landed.
 *
 * @param WP_Post $post Post.
 */
function aurora_dl_upload_notice( $post ) {
	if ( ! isset( $_GET['aurora_files'] ) ) {
		return;
	}

	$state = sanitize_key( $_GET['aurora_files'] );

	printf(
		'<div class="notice %s is-dismissible"><p>%s</p></div>',
		'ok' === $state ? 'notice-success' : 'notice-warning',
		'ok' === $state
			? esc_html__( '文件已添加到受保护目录。', 'aurora' )
			: esc_html__( '部分文件上传失败，请检查文件类型与大小限制。', 'aurora' )
	);
}
add_action( 'admin_notices', 'aurora_dl_upload_notice' );

/**
 * Show the file count in the posts list.
 *
 * @param array $columns Columns.
 * @return array
 */
function aurora_dl_column( $columns ) {
	$columns['aurora_files'] = __( '资源', 'aurora' );

	return $columns;
}
add_filter( 'manage_posts_columns', 'aurora_dl_column' );

/**
 * Column value.
 *
 * @param string $column  Column.
 * @param int    $post_id Post.
 */
function aurora_dl_column_content( $column, $post_id ) {
	if ( 'aurora_files' !== $column ) {
		return;
	}

	$files = aurora_dl_files( $post_id );

	if ( ! $files ) {
		echo '—';
		return;
	}

	$points = aurora_dl_points_price( $post_id );
	$vip    = aurora_dl_vip_level( $post_id );

	printf(
		'<strong>%d</strong>',
		count( $files )
	);

	if ( $points > 0 ) {
		printf( '<br><span class="description">%s</span>', esc_html( sprintf( __( '%d 积分', 'aurora' ), $points ) ) );
	}
	if ( $vip > 0 ) {
		printf( '<br><span class="description">%s</span>', esc_html( sprintf( __( 'Lv%d 免费', 'aurora' ), $vip ) ) );
	}
	if ( aurora_post_is_paid( $post_id ) ) {
		printf( '<br><span class="description">%s</span>', esc_html( sprintf( __( '¥%s', 'aurora' ), aurora_post_price( $post_id ) ) ) );
	}
}
add_action( 'manage_posts_custom_column', 'aurora_dl_column_content', 10, 2 );

/**
 * Download-log columns.
 *
 * @param array $columns Columns.
 * @return array
 */
function aurora_dl_log_columns( $columns ) {
	return array(
		'cb'        => $columns['cb'],
		'title'     => __( '文件', 'aurora' ),
		'aurora_u'  => __( '用户', 'aurora' ),
		'aurora_p'  => __( '所属文章', 'aurora' ),
		'aurora_ip' => __( 'IP', 'aurora' ),
		'date'      => __( '时间', 'aurora' ),
	);
}
add_filter( 'manage_aurora_download_posts_columns', 'aurora_dl_log_columns' );

/**
 * Download-log column values.
 *
 * @param string $column  Column.
 * @param int    $post_id Log row.
 */
function aurora_dl_log_column( $column, $post_id ) {
	switch ( $column ) {
		case 'aurora_u':
			$u = get_userdata( (int) get_post_field( 'post_author', $post_id ) );
			echo $u ? esc_html( $u->user_login ) : esc_html__( '游客', 'aurora' );
			break;

		case 'aurora_p':
			$pid = (int) get_post_meta( $post_id, '_aurora_dl_post', true );
			if ( $pid ) {
				printf(
					'<a href="%s">%s</a>',
					esc_url( get_edit_post_link( $pid ) ),
					esc_html( get_the_title( $pid ) )
				);
			} else {
				echo '—';
			}
			break;

		case 'aurora_ip':
			echo esc_html( (string) get_post_meta( $post_id, '_aurora_dl_ip', true ) );
			break;
	}
}
add_action( 'manage_aurora_download_posts_custom_column', 'aurora_dl_log_column', 10, 2 );
