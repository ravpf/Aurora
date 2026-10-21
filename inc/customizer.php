<?php
/**
 * Customizer options — the theme control panel.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register customizer settings.
 *
 * @param WP_Customize_Manager $wp_customize Customizer instance.
 */
function aurora_customize_register( $wp_customize ) {
	$wp_customize->add_panel( 'aurora_panel', array(
		'title'       => __( 'Aurora 主题设置', 'aurora' ),
		'description' => __( '全局外观、布局与会员/支付开关。', 'aurora' ),
		'priority'    => 20,
	) );

	/* ---------------------------------------------------------------- 外观 */
	$wp_customize->add_section( 'aurora_appearance', array(
		'title' => __( '外观', 'aurora' ),
		'panel' => 'aurora_panel',
	) );

	$wp_customize->add_setting( 'aurora_default_mode', array(
		'default'           => 'auto',
		'sanitize_callback' => 'aurora_sanitize_choice',
		'transport'         => 'refresh',
	) );
	$wp_customize->add_control( 'aurora_default_mode', array(
		'label'   => __( '默认配色模式', 'aurora' ),
		'section' => 'aurora_appearance',
		'type'    => 'select',
		'description' => __( '切换按钮包含哪些配色，在「主题设置 → 外观」里勾选。', 'aurora' ),
		'choices'     => array(
			'auto'    => __( '跟随系统', 'aurora' ),
			'light'   => __( '浅色', 'aurora' ),
			'dark'    => __( '深色', 'aurora' ),
			'spotify' => __( 'Spotify 风格（暗绿）', 'aurora' ),
		),
	) );

	$wp_customize->add_setting( 'aurora_radius', array(
		'default'           => 'large',
		'sanitize_callback' => 'aurora_sanitize_choice',
	) );
	$wp_customize->add_control( 'aurora_radius', array(
		'label'   => __( '圆角风格', 'aurora' ),
		'section' => 'aurora_appearance',
		'type'    => 'select',
		'choices' => array(
			'small' => __( '紧凑', 'aurora' ),
			'large' => __( '舒展（推荐）', 'aurora' ),
		),
	) );

	$wp_customize->add_setting( 'aurora_accent', array(
		'default'           => '#0071e3',
		'sanitize_callback' => 'sanitize_hex_color',
	) );
	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'aurora_accent', array(
		'label'   => __( '强调色', 'aurora' ),
		'section' => 'aurora_appearance',
	) ) );

	/* ---------------------------------------------------------------- 布局 */
	$wp_customize->add_section( 'aurora_layout', array(
		'title' => __( '布局', 'aurora' ),
		'panel' => 'aurora_panel',
	) );

	$wp_customize->add_setting( 'aurora_home_layout', array(
		'default'           => 'grid',
		'sanitize_callback' => 'aurora_sanitize_choice',
	) );
	$wp_customize->add_control( 'aurora_home_layout', array(
		'label'   => __( '首页列表样式', 'aurora' ),
		'section' => 'aurora_layout',
		'type'    => 'select',
		'choices' => array(
			'grid'     => __( '三栏卡片', 'aurora' ),
			'grid-two' => __( '两栏卡片', 'aurora' ),
			'list'     => __( '单栏图文', 'aurora' ),
		),
	) );

	$wp_customize->add_setting( 'aurora_pagination_style', array(
		'default'           => 'number',
		'sanitize_callback' => 'aurora_sanitize_choice',
	) );
	$wp_customize->add_control( 'aurora_pagination_style', array(
		'label'   => __( '分页方式', 'aurora' ),
		'section' => 'aurora_layout',
		'type'    => 'select',
		'choices' => array(
			'number'   => __( '数字分页', 'aurora' ),
			'loadmore' => __( '加载更多', 'aurora' ),
		),
	) );

	$wp_customize->add_setting( 'aurora_hide_sidebar_single', array(
		'default'           => true,
		'sanitize_callback' => 'aurora_sanitize_bool',
	) );
	$wp_customize->add_control( 'aurora_hide_sidebar_single', array(
		'label'   => __( '文章页隐藏侧边栏', 'aurora' ),
		'section' => 'aurora_layout',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'aurora_show_toc', array(
		'default'           => true,
		'sanitize_callback' => 'aurora_sanitize_bool',
	) );
	$wp_customize->add_control( 'aurora_show_toc', array(
		'label'   => __( '显示文章目录', 'aurora' ),
		'section' => 'aurora_layout',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'aurora_excerpt_on_card', array(
		'default'           => true,
		'sanitize_callback' => 'aurora_sanitize_bool',
	) );
	$wp_customize->add_control( 'aurora_excerpt_on_card', array(
		'label'   => __( '卡片显示摘要', 'aurora' ),
		'section' => 'aurora_layout',
		'type'    => 'checkbox',
	) );

	/* ------------------------------------------------------------ 会员体系 */
	$wp_customize->add_section( 'aurora_members', array(
		'title'       => __( '会员与付费', 'aurora' ),
		'panel'       => 'aurora_panel',
		'description' => __( 'VIP 等级、内容权限与支付网关开关。真实收款需在下方填入商户配置。', 'aurora' ),
	) );

	$wp_customize->add_setting( 'aurora_vip_enabled', array(
		'default'           => true,
		'sanitize_callback' => 'aurora_sanitize_bool',
	) );
	$wp_customize->add_control( 'aurora_vip_enabled', array(
		'label'   => __( '启用 VIP 会员等级', 'aurora' ),
		'section' => 'aurora_members',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'aurora_enable_paywall', array(
		'default'           => true,
		'sanitize_callback' => 'aurora_sanitize_bool',
	) );
	$wp_customize->add_control( 'aurora_enable_paywall', array(
		'label'   => __( '启用内容付费墙（按文章设置等级）', 'aurora' ),
		'section' => 'aurora_members',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'aurora_register_open', array(
		'default'           => true,
		'sanitize_callback' => 'aurora_sanitize_bool',
	) );
	$wp_customize->add_control( 'aurora_register_open', array(
		'label'   => __( '开放前台注册', 'aurora' ),
		'section' => 'aurora_members',
		'type'    => 'checkbox',
	) );

	foreach ( array(
		'aurora_wechat_pay' => __( '启用微信支付', 'aurora' ),
		'aurora_alipay'     => __( '启用支付宝', 'aurora' ),
		'aurora_manual_pay' => __( '启用手动/转账付款', 'aurora' ),
	) as $key => $label ) {
		$wp_customize->add_setting( $key, array(
			'default'           => 'aurora_manual_pay' === $key,
			'sanitize_callback' => 'aurora_sanitize_bool',
		) );
		$wp_customize->add_control( $key, array(
			'label'   => $label,
			'section' => 'aurora_members',
			'type'    => 'checkbox',
		) );
	}

	/* ------------------------------------------------------------ 公告与页脚 */
	$wp_customize->add_section( 'aurora_notice', array(
		'title' => __( '公告栏与页脚', 'aurora' ),
		'panel' => 'aurora_panel',
	) );

	$wp_customize->add_setting( 'aurora_notice_text', array(
		'default'           => '',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'aurora_notice_text', array(
		'label'       => __( '顶部公告文字', 'aurora' ),
		'description' => __( '留空则不显示。', 'aurora' ),
		'section'     => 'aurora_notice',
		'type'        => 'text',
	) );

	$wp_customize->add_setting( 'aurora_notice_link', array(
		'default'           => '',
		'sanitize_callback' => 'esc_url_raw',
	) );
	$wp_customize->add_control( 'aurora_notice_link', array(
		'label'   => __( '公告链接', 'aurora' ),
		'section' => 'aurora_notice',
		'type'    => 'url',
	) );

	$wp_customize->add_setting( 'aurora_footer_text', array(
		'default'           => '',
		'sanitize_callback' => 'wp_kses_post',
	) );
	$wp_customize->add_control( 'aurora_footer_text', array(
		'label'   => __( '页脚版权文字', 'aurora' ),
		'section' => 'aurora_notice',
		'type'    => 'textarea',
	) );

	$wp_customize->add_setting( 'aurora_icp', array(
		'default'           => '',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'aurora_icp', array(
		'label'       => __( 'ICP 备案号', 'aurora' ),
		'description' => __( '中国大陆服务器建议填写。', 'aurora' ),
		'section'     => 'aurora_notice',
		'type'        => 'text',
	) );
}
add_action( 'customize_register', 'aurora_customize_register' );

/**
 * Sanitize a select choice.
 *
 * @param string               $value   Raw value.
 * @param WP_Customize_Setting $setting Setting.
 * @return string
 */
function aurora_sanitize_choice( $value, $setting ) {
	$control = $setting->manager->get_control( $setting->id );
	if ( $control && isset( $control->choices[ $value ] ) ) {
		return $value;
	}

	return $setting->default;
}

/**
 * Sanitize a checkbox.
 *
 * @param mixed $value Raw value.
 * @return bool
 */
function aurora_sanitize_bool( $value ) {
	return (bool) $value;
}

/**
 * Push the customizer accent colour into inline CSS.
 */
function aurora_customizer_css() {
	$accent = get_theme_mod( 'aurora_accent', '#0071e3' );
	if ( ! $accent || '#0071e3' === strtolower( $accent ) ) {
		return;
	}
	$rgb = aurora_hex_to_rgb( $accent );
	if ( ! $rgb ) {
		return;
	}
	printf(
		'<style id="aurora-accent-css">:root{--aurora-accent:%1$s;--aurora-accent-soft:rgba(%2$d,%3$d,%4$d,.12)}html[data-theme="dark"]{--aurora-accent:%1$s}</style>' . "\n",
		esc_html( $accent ),
		(int) $rgb[0],
		(int) $rgb[1],
		(int) $rgb[2]
	);
}
add_action( 'wp_head', 'aurora_customizer_css', 20 );

/**
 * Convert #rrggbb to an RGB triplet.
 *
 * @param string $hex Hex colour.
 * @return array|false
 */
function aurora_hex_to_rgb( $hex ) {
	$hex = ltrim( (string) $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		return false;
	}

	return array(
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) ),
	);
}
