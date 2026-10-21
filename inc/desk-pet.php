<?php
/**
 * The footer desk pet.
 *
 * A mascot standing at the right edge of the footer — the owner's illustration
 * of a girl with long dark hair, a blue plaid bow, white sailor collar under a
 * cream blazer and a blue plaid pleated skirt, cut out of her photo (transparent
 * WebP, 23 KB at 260px / 38 KB at 390px) and faded out below the thigh, so she
 * reads as a character standing in the page rather than a picture in a frame.
 * She floats, leans towards the pointer and says something when poked.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is the pet switched on?
 *
 * @return bool
 */
function aurora_desk_pet_enabled() {
	return (bool) get_theme_mod( 'aurora_desk_pet', true );
}

/**
 * Add the switch to the appearance tab.
 *
 * @param array $schema Tabs.
 * @return array
 */
function aurora_desk_pet_schema( $schema ) {
	if ( ! isset( $schema['appearance']['fields'] ) ) {
		return $schema;
	}

	$schema['appearance']['fields']['aurora_desk_pet'] = array(
		'label'   => __( '页脚桌宠', 'aurora' ),
		'type'    => 'checkbox',
		'default' => true,
		'help'    => __( '页脚右侧的看板娘：会轻轻浮动、跟着鼠标偏头，点一下会说话。不想要就取消勾选。', 'aurora' ),
	);

	return $schema;
}
add_filter( 'aurora_setting_schema', 'aurora_desk_pet_schema' );

/**
 * The portrait.
 *
 * Everything is decorative except the button: the SVG carries aria-hidden, the
 * wrapper is a button so it can be reached with the keyboard, and the speech
 * bubble is announced through aria-live.
 */
function aurora_desk_pet() {
	if ( ! aurora_desk_pet_enabled() ) {
		return;
	}
	?>
	<div class="aurora-pet" data-aurora-pet>
		<span class="aurora-pet__shadow" aria-hidden="true"></span>
		<button type="button" class="aurora-pet__btn" data-aurora-pet-btn aria-label="<?php esc_attr_e( '和页脚的看板娘打个招呼', 'aurora' ); ?>">
			<img class="aurora-pet__art"
			     src="<?php echo esc_url( AURORA_URI . '/assets/img/desk-pet-mascot.webp?v=' . AURORA_VERSION ); ?>"
			     srcset="<?php echo esc_url( AURORA_URI . '/assets/img/desk-pet-mascot.webp?v=' . AURORA_VERSION ); ?> 260w,
			             <?php echo esc_url( AURORA_URI . '/assets/img/desk-pet-mascot@2x.webp?v=' . AURORA_VERSION ); ?> 390w"
			     sizes="(max-width: 640px) 112px, 132px"
			     width="260" height="466"
			     alt="" decoding="async" loading="lazy" fetchpriority="low">
		</button>

		<span class="aurora-pet__bubble" data-aurora-pet-bubble role="status" aria-live="polite" hidden></span>
	</div>
	<?php
}

/**
 * Script, only on pages that print the pet.
 */
function aurora_desk_pet_assets() {
	if ( ! aurora_desk_pet_enabled() ) {
		return;
	}

	wp_enqueue_script(
		'aurora-pet',
		AURORA_URI . '/assets/js/desk-pet.js',
		array(),
		AURORA_VERSION,
		true
	);

	wp_localize_script(
		'aurora-pet',
		'AURORA_PET',
		array(
			'lines' => array(
				__( '今天也要写点什么呀～', 'aurora' ),
				__( '点我干嘛，快去更新文章！', 'aurora' ),
				__( '站这儿半天了，你倒是理我一下。', 'aurora' ),
				__( '这个页脚比上次好看多了。', 'aurora' ),
				__( '别光点我，右下角还有回到顶部。', 'aurora' ),
				__( '写完这篇就休息一下吧。', 'aurora' ),
				__( '摸鱼一分钟，写作一小时。', 'aurora' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'aurora_desk_pet_assets', 21 );
