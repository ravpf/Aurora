<?php
/**
 * Standalone theme settings screen in wp-admin.
 *
 * Values live in theme mods (the same store the Customizer writes to), so the
 * two screens always agree. Everything funnels through one schema so sanitising
 * is declared once instead of per field.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declarative schema for every theme setting.
 *
 * @return array
 */
function aurora_setting_schema() {
	$schema = array(
		'appearance' => array(
			'label'  => __( '外观', 'aurora' ),
			'icon'   => 'admin-appearance',
			'desc'   => __( '配色、圆角与强调色。配色切换按钮在站点右上角（浅色 / 深色 / Spotify 风格 / 跟随系统）。', 'aurora' ),
			'fields' => array(
				'aurora_gradient'     => array(
					'label'   => __( '浅色模式背景渐变', 'aurora' ),
					'type'    => 'select',
					'default' => 'aurora',
					'options' => aurora_gradients(),
					'help'    => __( '只在浅色模式生效：深色与 Spotify 保持纯色底。所有渐变都控制在接近白色的范围内，不会影响正文对比度。', 'aurora' ),
				),
				'aurora_default_mode' => array(
					'label'   => __( '默认配色模式', 'aurora' ),
					'type'    => 'select',
					'default' => 'auto',
					'options' => array(
						'auto'    => __( '跟随系统', 'aurora' ),
						'light'   => __( '浅色', 'aurora' ),
						'dark'    => __( '深色', 'aurora' ),
						'spotify' => __( 'Spotify 风格（暗绿）', 'aurora' ),
					),
					'help'    => __( '访客第一次打开看到的配色。点过右上角按钮之后，就按他们自己的选择走。', 'aurora' ),
				),
				'aurora_modes_enabled' => array(
					'label'   => __( '右上角按钮包含', 'aurora' ),
					'type'    => 'checkbox_multi',
					'default' => array( 'light', 'dark', 'spotify' ),
					'options' => aurora_palettes(),
					'help'    => __( '按这里的顺序循环，最后回到「跟随系统」。一个都不勾会退回浅色 + 深色。', 'aurora' ),
				),
				'aurora_radius'       => array(
					'label'   => __( '圆角风格', 'aurora' ),
					'type'    => 'select',
					'default' => 'large',
					'options' => array(
						'small' => __( '紧凑（12px）', 'aurora' ),
						'large' => __( '舒展（22px）', 'aurora' ),
					),
				),
				'aurora_accent'       => array(
					'label'   => __( '强调色', 'aurora' ),
					'type'    => 'color',
					'default' => '#0071e3',
					'help'    => __( '苹果默认蓝 #0071e3。改这里会影响按钮、链接和选中态。', 'aurora' ),
				),
			),
		),
		'layout'     => array(
			'label'  => __( '布局', 'aurora' ),
			'icon'   => 'admin-generic',
			'fields' => array(
				'aurora_home_layout'         => array(
					'label'   => __( '首页列表样式', 'aurora' ),
					'type'    => 'select',
					'default' => 'grid',
					'options' => array(
						'grid'     => __( '三栏卡片', 'aurora' ),
						'grid-two' => __( '两栏卡片', 'aurora' ),
						'list'     => __( '单栏图文', 'aurora' ),
					),
				),
				'aurora_pagination_style'    => array(
					'label'   => __( '分页方式', 'aurora' ),
					'type'    => 'select',
					'default' => 'number',
					'options' => array(
						'number'   => __( '数字分页', 'aurora' ),
						'loadmore' => __( '加载更多', 'aurora' ),
					),
				),
				'aurora_hide_sidebar_single' => array(
					'label'   => __( '文章页隐藏侧边栏', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_show_toc'            => array(
					'label'   => __( '显示文章目录', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_mobile_bottom_nav'   => array(
					'label'   => __( '移动端底部导航栏', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
					'help'    => __( '手机上显示固定底栏（首页 / 热点 / 导航 / 我的）。', 'aurora' ),
				),
			),
		),
		'members'    => array(
			'label'  => __( '会员与付费', 'aurora' ),
			'icon'   => 'admin-users',
			'desc'   => __( '会员等级、内容权限与支付网关开关。', 'aurora' ),
			'fields' => array(
				'aurora_vip_enabled'    => array(
					'label'   => __( '启用 VIP 会员等级', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_enable_paywall' => array(
					'label'   => __( '启用内容付费墙', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_register_open'  => array(
					'label'   => __( '开放前台注册', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_rate_enable'    => array(
					'label'   => __( '启用频率限制（防刷）', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
					'help'    => __( '按账号与 IP 限制重复操作，具体额度在「防刷与验证」页配置。', 'aurora' ),
				),
				'aurora_wechat_pay'     => array(
					'label'   => __( '启用微信支付', 'aurora' ),
					'type'    => 'checkbox',
					'default' => false,
					'help'    => __( '配置好商户信息后会自动出现在下单页。', 'aurora' ),
				),
				'aurora_alipay'         => array(
					'label'   => __( '启用支付宝', 'aurora' ),
					'type'    => 'checkbox',
					'default' => false,
				),
				'aurora_manual_pay'     => array(
					'label'   => __( '启用手动 / 转账付款', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_vip_prices'     => array(
					'label'   => __( '会员价格（元 / 月）', 'aurora' ),
					'type'    => 'text',
					'default' => '19,39,99',
					'help'    => __( '对应白银 / 黄金 / 钻石三个等级，用英文逗号分隔。', 'aurora' ),
				),
				'aurora_post_vip_free'  => array(
					'label'   => __( '会员免费读单篇付费内容', 'aurora' ),
					'type'    => 'checkbox',
					'default' => false,
					'help'    => __( '开启后，达到下面等级的会员无需单独购买单篇付费文章。', 'aurora' ),
				),
				'aurora_post_free_level' => array(
					'label'   => __( '免费所需的会员等级', 'aurora' ),
					'type'    => 'select',
					'default' => '1',
					'options' => array(
						'1' => __( 'Lv1 白银及以上', 'aurora' ),
						'2' => __( 'Lv2 黄金及以上', 'aurora' ),
						'3' => __( 'Lv3 钻石', 'aurora' ),
					),
				),
				'aurora_post_access_days' => array(
					'label'   => __( '单篇购买可读天数', 'aurora' ),
					'type'    => 'number',
					'default' => 0,
					'min'     => 0,
					'max'     => 36500,
					'help'    => __( '0 = 永久可读。填 365 即购买后一年内可读。', 'aurora' ),
				),
			),
		),
		'wechatpay'  => array(
			'label'  => __( '微信支付', 'aurora' ),
			'icon'   => 'money-alt',
			'desc'   => __( '微信支付 APIv3。填完下面五项即可在收银台出现「微信扫码支付」。', 'aurora' ),
			'fields' => array(
				'aurora_wxpay_mchid'    => array(
					'label'    => __( '商户号 mchid', 'aurora' ),
					'type'     => 'text',
					'default'  => '',
					'help'     => __( '微信支付商户平台 → 账户中心 → 商户信息。10 位数字。', 'aurora' ),
				),
				'aurora_wxpay_appid'    => array(
					'label'   => __( 'AppID', 'aurora' ),
					'type'    => 'text',
					'default' => '',
					'help'    => __( '与商户号绑定的公众号 / 小程序 / 开放平台 AppID。', 'aurora' ),
				),
				'aurora_wxpay_serial'   => array(
					'label'   => __( '商户证书序列号', 'aurora' ),
					'type'    => 'text',
					'default' => '',
					'help'    => __( '商户平台 → API 安全 → 申请 API 证书，下载后用 openssl 查看序列号。', 'aurora' ),
				),
				'aurora_wxpay_apiv3key' => array(
					'label'    => __( 'APIv3 密钥', 'aurora' ),
					'type'     => 'text',
					'default'  => '',
					'secret'   => true,
					'help'     => __( '32 位字符。用于解密支付回调，设置后不要随意更换。', 'aurora' ),
				),
				'aurora_wxpay_key_pem'  => array(
					'label'   => __( '商户私钥 apiclient_key.pem', 'aurora' ),
					'type'    => 'pem',
					'default' => '',
					'help'    => __( '把 apiclient_key.pem 的全部内容（含 BEGIN/END 行）粘贴到这里。保存后会写入 uploads/aurora-wechatpay/ 并设为 600 权限。', 'aurora' ),
				),
				'aurora_wxpay_notify'   => array(
					'label'   => __( '回调通知地址（可选）', 'aurora' ),
					'type'    => 'url',
					'default' => '',
					'help'    => __( '留空则自动使用本主题的 REST 回调。必须是微信服务器能访问到的 HTTPS 地址。', 'aurora' ),
				),
			),
		),
		'hot'        => array(
			'label'  => __( '热点资讯', 'aurora' ),
			'icon'   => 'chart-line',
			'desc'   => __( '抖音 / 微博 / 百度 / B站 实时热搜，服务端抓取并缓存。', 'aurora' ),
			'fields' => array(
				'aurora_hot_sources'        => array(
					'label'   => __( '数据源', 'aurora' ),
					'type'    => 'checkbox_multi',
					'default' => array( 'douyin', 'weibo', 'baidu', 'bilibili' ),
					'options' => wp_list_pluck( aurora_hot_providers(), 'label' ),
				),
				'aurora_hot_ttl'            => array(
					'label'   => __( '缓存时长（分钟）', 'aurora' ),
					'type'    => 'number',
					'default' => 30,
					'min'     => 5,
					'max'     => 720,
					'help'    => __( '越大对上游越友好，但热点更新越慢。建议 15–60。', 'aurora' ),
				),
				'aurora_hot_on_home'        => array(
					'label'   => __( '首页显示热点卡片', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_hot_on_single'      => array(
					'label'   => __( '文章页侧栏显示热点', 'aurora' ),
					'type'    => 'checkbox',
					'default' => false,
				),
			),
		),
		'navlinks'   => array(
			'label'  => __( '网址导航', 'aurora' ),
			'icon'   => 'admin-links',
			'desc'   => __( '导航页面的展示方式。条目录入在左侧「网址导航」菜单。', 'aurora' ),
			'fields' => array(
				'aurora_nav_columns'       => array(
					'label'   => __( '每行列数', 'aurora' ),
					'type'    => 'select',
					'default' => 'auto',
					'options' => array(
						'auto' => __( '自适应', 'aurora' ),
						'2'    => '2',
						'3'    => '3',
						'4'    => '4',
					),
				),
				'aurora_nav_fetch_favicon' => array(
					'label'   => __( '自动抓取网站图标', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
					'help'    => __( '首次加载某个网址时会去抓一次 favicon 并缓存。关闭可减少外部请求。', 'aurora' ),
				),
				'aurora_nav_on_home'       => array(
					'label'   => __( '首页显示导航入口', 'aurora' ),
					'type'    => 'checkbox',
					'default' => false,
				),
			),
		),
		'mega'       => array(
			'label'  => __( '巨型菜单', 'aurora' ),
			'icon'   => 'menu',
			'desc'   => __( '子项够多的顶级菜单项会展开成两列面板并带一张精选文章卡，子项少的保持普通下拉。', 'aurora' ),
			'fields' => array(
				'aurora_mega_menu'      => array(
					'label'   => __( '启用巨型菜单', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_mega_threshold' => array(
					'label'   => __( '子项达到几项时升级', 'aurora' ),
					'type'    => 'number',
					'default' => 3,
					'min'     => 2,
					'max'     => 20,
					'help'    => __( '低于这个数量仍是普通下拉；两栏面板里放三个链接比单列更糟。', 'aurora' ),
				),
			),
		),
		'home'       => array(
			'label'  => __( '首页区块', 'aurora' ),
			'icon'   => 'grid',
			'desc'   => __( '首页主栏由哪些区块组成、按什么顺序。留空会退回「最新内容」一个区块，不会出现空白首页。', 'aurora' ),
			'fields' => array(
				'aurora_home_blocks' => array(
					'label'   => __( '区块顺序', 'aurora' ),
					'type'    => 'text',
					'default' => 'chips,hot,latest',
					'help'    => __( '逗号分隔，可用：hero（头条大图）、chips（分类快捷条）、hot（热点榜）、grid（精选三列）、cats（分类精选）、latest（最新内容）、newsletter（订阅框）。写错的名字会被忽略。', 'aurora' ),
				),
			),
		),
		'reward'     => array(
			'label'  => __( '打赏', 'aurora' ),
			'icon'   => 'gift',
			'desc'   => __( '读者用积分打赏作者，全额转给作者，双方都留流水。打赏依赖积分体系。', 'aurora' ),
			'fields' => array(
				'aurora_reward_enabled' => array(
					'label'   => __( '开启打赏', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_reward_amounts' => array(
					'label'   => __( '可选金额', 'aurora' ),
					'type'    => 'text',
					'default' => '5,10,20,50',
					'help'    => __( '逗号分隔的积分档位。服务端只接受这里的档位，浏览器改数字无效。', 'aurora' ),
				),
			),
		),
		'engage'     => array(
			'label'  => __( '评分与订阅', 'aurora' ),
			'icon'   => 'star',
			'desc'   => __( '文章评分与邮件订阅。订阅是双确认流程：填邮箱后收到确认信，点链接才真正加入名单；每封信都带退订链接。', 'aurora' ),
			'fields' => array(
				'aurora_rating_enabled' => array(
					'label'   => __( '开启文章评分', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
					'help'    => __( '每篇文章每位访客只能打一次分；登录用户按账号、游客按地址指纹去重。', 'aurora' ),
				),
				'aurora_newsletter_enabled' => array(
					'label'   => __( '开启邮件订阅', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
			),
		),
		'slider'     => array(
			'label'  => __( '首页幻灯片', 'aurora' ),
			'icon'   => 'image',
			'desc'   => __( '首页顶部的轮播图。可以按文章编号挑几篇、自动取置顶/最新文章，也可以手写一组；关掉后首页回到没有幻灯片的样子。', 'aurora' ),
			'fields' => array(
				'aurora_slider_enabled'  => array(
					'label'   => __( '启用幻灯片', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_slider_source'   => array(
					'label'   => __( '内容来源', 'aurora' ),
					'type'    => 'select',
					'default' => 'auto',
					'options' => array(
						'ids'    => __( '按文章编号', 'aurora' ),
						'auto'   => __( '自动（置顶优先，其次最新）', 'aurora' ),
						'manual' => __( '手写一组', 'aurora' ),
					),
				),
				'aurora_slider_slides'   => array(
					'label'   => __( '手写幻灯片', 'aurora' ),
					'type'    => 'textarea',
					'default' => '',
					'help'    => __( '一行一张，竖线分隔：图片地址 | 标题 | 链接 | 描述。只有图片地址必填；留空会退回自动来源，不会出现空白框。', 'aurora' ),
				),
				'aurora_slider_post_ids' => array(
					'label'   => __( '文章编号', 'aurora' ),
					'type'    => 'text',
					'default' => '',
					'help'    => __( '填文章 ID，逗号或空格分隔，顺序就是播放顺序，最多 8 篇。编号在写文章页面的地址栏里：post=1234。选中的文章用特色图，没有特色图就用正文第一张图；编号模式不看「最多几张」。', 'aurora' ),
				),
				'aurora_slider_ids_preview' => array(
					'type'   => 'html',
					'render' => 'aurora_slider_ids_preview',
				),
				'aurora_slider_count'    => array(
					'label'   => __( '最多几张', 'aurora' ),
					'type'    => 'number',
					'default' => 3,
					'help'    => __( '1–8 张。自动来源最多取这么多篇带特色图的文章。', 'aurora' ),
				),
				'aurora_slider_autoplay' => array(
					'label'   => __( '自动播放', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
					'help'    => __( '鼠标悬停、键盘聚焦或切到别的标签页时暂停；系统开启“减少动态效果”时不自动播放。', 'aurora' ),
				),
				'aurora_slider_interval' => array(
					'label'   => __( '切换间隔（毫秒）', 'aurora' ),
					'type'    => 'number',
					'default' => 5000,
					'help'    => __( '2500–15000。', 'aurora' ),
				),
			),
		),
		'pwa'        => array(
			'label'  => __( '离线与安装', 'aurora' ),
			'icon'   => 'device',
			'desc'   => __( '生成 Web App 清单与 Service Worker：加到主屏后独立窗口打开，已访问过的页面可离线看。Service Worker 只在 HTTPS（或 localhost）下生效，HTTP 访问时浏览器会直接忽略，不影响正常浏览。后台、登录页与 AJAX 请求永远不走缓存。', 'aurora' ),
			'fields' => array(
				'aurora_pwa_enabled' => array(
					'label'   => __( '开启离线缓存', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
					'help'    => __( '缓存名带主题版本号，升级主题自动换新缓存。', 'aurora' ),
				),
			),
		),
		'submit'     => array(
			'label'  => __( '前台投稿', 'aurora' ),
			'icon'   => 'edit',
			'desc'   => __( '读者在投稿页提交文章，进入审核队列。投稿受「前台投稿 / 发帖」限流桶约束，并会走验证码（若开启）。', 'aurora' ),
			'fields' => array(
				'aurora_submit_enabled'   => array(
					'label'   => __( '开放投稿', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_submit_min_level' => array(
					'label'   => __( '最低等级', 'aurora' ),
					'type'    => 'number',
					'default' => 0,
					'min'     => 0,
					'max'     => 5,
					'help'    => __( '0 表示任意已登录读者都能投稿；填 1–5 则要求达到对应等级。', 'aurora' ),
				),
				'aurora_submit_status'    => array(
					'label'   => __( '提交后的状态', 'aurora' ),
					'type'    => 'select',
					'default' => 'pending',
					'choices' => array(
						'pending' => __( '待审核（推荐）', 'aurora' ),
						'draft'   => __( '草稿（作者可继续编辑）', 'aurora' ),
					),
				),
			),
		),
		'mail'       => array(
			'label'  => __( '邮件通知', 'aurora' ),
			'icon'   => 'email-alt',
			'desc'   => __( '订单、支付、退款与到期提醒邮件。多数服务器默认没有邮件服务，建议开启 SMTP。', 'aurora' ),
			'fields' => array(
				'aurora_mail_enabled'     => array(
					'label'   => __( '启用邮件通知', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_mail_events'      => array(
					'label'   => __( '发送哪些通知', 'aurora' ),
					'type'    => 'checkbox_multi',
					'default' => array( 'order_created', 'order_paid', 'refund_settled', 'vip_expiring' ),
					'options' => array(
						'order_created'  => __( '下单成功', 'aurora' ),
						'order_paid'     => __( '支付成功', 'aurora' ),
						'refund_settled' => __( '退款成功', 'aurora' ),
						'vip_expiring'   => __( '到期提醒', 'aurora' ),
					),
				),
				'aurora_mail_from_name'   => array(
					'label'   => __( '发件人名称', 'aurora' ),
					'type'    => 'text',
					'default' => '',
					'help'    => __( '留空则用站点名称。', 'aurora' ),
				),
				'aurora_mail_from_email'  => array(
					'label'   => __( '发件人邮箱', 'aurora' ),
					'type'    => 'text',
					'default' => '',
					'help'    => __( '必须是你域名下的邮箱，否则容易被判为垃圾邮件。留空则用站点管理员邮箱。', 'aurora' ),
				),
				'aurora_mail_smtp'        => array(
					'label'   => __( '使用 SMTP 发信', 'aurora' ),
					'type'    => 'checkbox',
					'default' => false,
					'help'    => __( '强烈建议开启。关闭时用 PHP mail()，本机若没装邮件服务会静默失败。', 'aurora' ),
				),
				'aurora_mail_smtp_host'   => array(
					'label'   => __( 'SMTP 服务器', 'aurora' ),
					'type'    => 'text',
					'default' => '',
					'help'    => __( '例如 smtp.qq.com / smtp.163.com / smtp.exmail.qq.com。', 'aurora' ),
				),
				'aurora_mail_smtp_port'   => array(
					'label'   => __( 'SMTP 端口', 'aurora' ),
					'type'    => 'number',
					'default' => 465,
					'min'     => 1,
					'max'     => 65535,
					'help'    => __( 'SSL 通常 465，STARTTLS 通常 587。', 'aurora' ),
				),
				'aurora_mail_smtp_secure' => array(
					'label'   => __( '加密方式', 'aurora' ),
					'type'    => 'select',
					'default' => 'ssl',
					'options' => array(
						'ssl'  => 'SSL（465）',
						'tls'  => 'STARTTLS（587）',
						'none' => __( '不加密（仅内网 / 调试）', 'aurora' ),
					),
				),
				'aurora_mail_smtp_user'   => array(
					'label'   => __( 'SMTP 账号', 'aurora' ),
					'type'    => 'text',
					'default' => '',
					'help'    => __( '通常是完整邮箱地址。留空表示不认证。', 'aurora' ),
				),
				'aurora_mail_smtp_pass'   => array(
					'label'   => __( 'SMTP 密码 / 授权码', 'aurora' ),
					'type'    => 'text',
					'default' => '',
					'help'    => __( 'QQ / 163 邮箱需要填「授权码」而不是登录密码。', 'aurora' ),
				),
			),
		),
		'notice'     => array(
			'label'  => __( '公告与页脚', 'aurora' ),
			'icon'   => 'megaphone',
			'fields' => array(
				'aurora_notice_text' => array(
					'label'   => __( '顶部公告文字', 'aurora' ),
					'type'    => 'text',
					'default' => '',
					'help'    => __( '留空则不显示。', 'aurora' ),
				),
				'aurora_notice_link' => array(
					'label'   => __( '公告链接', 'aurora' ),
					'type'    => 'url',
					'default' => '',
				),
				'aurora_footer_text' => array(
					'label'   => __( '页脚版权文字', 'aurora' ),
					'type'    => 'textarea',
					'default' => '',
				),
				'aurora_icp'         => array(
					'label'   => __( 'ICP 备案号', 'aurora' ),
					'type'    => 'text',
					'default' => '',
				),
			),
		),
		'points'     => array(
			'label'  => __( '积分', 'aurora' ),
			'icon'   => 'star-filled',
			'desc'   => __( '签到、评论、消费都能赚积分；积分可用来兑换资源。每一次变动都会写进流水，余额永远查得到来路。', 'aurora' ),
			'fields' => array(
				'aurora_points_enabled'            => array(
					'label'   => __( '启用积分系统', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_points_register'           => array(
					'label'   => __( '注册赠送', 'aurora' ),
					'type'    => 'number',
					'default' => 50,
					'min'     => 0,
					'max'     => 100000,
				),
				'aurora_points_checkin'            => array(
					'label'   => __( '每日签到基础分', 'aurora' ),
					'type'    => 'number',
					'default' => 10,
					'min'     => 0,
					'max'     => 10000,
				),
				'aurora_points_checkin_streak'     => array(
					'label'   => __( '连续签到每日递增', 'aurora' ),
					'type'    => 'number',
					'default' => 2,
					'min'     => 0,
					'max'     => 1000,
					'help'    => __( '连续第 N 天，在基础分之上再加 (N-1)×该值。', 'aurora' ),
				),
				'aurora_points_checkin_streak_cap' => array(
					'label'   => __( '连续加成上限', 'aurora' ),
					'type'    => 'number',
					'default' => 10,
					'min'     => 0,
					'max'     => 365,
					'help'    => __( '按连续天数封顶，避免长期签到收益无限增长。', 'aurora' ),
				),
				'aurora_points_comment'            => array(
					'label'   => __( '每条评论奖励', 'aurora' ),
					'type'    => 'number',
					'default' => 5,
					'min'     => 0,
					'max'     => 1000,
				),
				'aurora_points_cap_comment'        => array(
					'label'   => __( '每日评论奖励次数上限', 'aurora' ),
					'type'    => 'number',
					'default' => 3,
					'min'     => 0,
					'max'     => 100,
					'help'    => __( '防止刷评论。0 表示不限。', 'aurora' ),
				),
				'aurora_points_per_currency'       => array(
					'label'   => __( '每消费 1 元返积分', 'aurora' ),
					'type'    => 'number',
					'default' => 1,
					'min'     => 0,
					'max'     => 1000,
					'help'    => __( '订单支付成功后按金额取整发放；订单全额退款时会扣回。', 'aurora' ),
				),
				'aurora_points_member_bonus_on'    => array(
					'label'   => __( '会员签到/评论加成', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_points_member_bonus'       => array(
					'label'   => __( '会员加成比例（%）', 'aurora' ),
					'type'    => 'number',
					'default' => 50,
					'min'     => 0,
					'max'     => 1000,
					'help'    => __( '只作用于签到与评论；卡密兑换按面值，不打折也不加成。', 'aurora' ),
				),
			),
		),
		'cards'      => array(
			'label'  => __( '卡密', 'aurora' ),
			'icon'   => 'tickets-alt',
			'desc'   => __( '卡密在「卡密 → 批量生成」里创建，可兑换会员时长或积分。每张卡只能兑换一次。', 'aurora' ),
			'fields' => array(
				'aurora_card_try_limit' => array(
					'label'   => __( '每小时兑换尝试上限', 'aurora' ),
					'type'    => 'number',
					'default' => 10,
					'min'     => 0,
					'max'     => 200,
					'help'    => __( '防止有人用脚本猜卡密。0 表示不限制。', 'aurora' ),
				),
			),
		),
		'downloads'  => array(
			'label'  => __( '下载', 'aurora' ),
			'icon'   => 'download',
			'desc'   => __( '文章编辑页可以挂载资源文件。文件存放在 uploads/aurora-downloads/，服务器禁止该目录被直接访问，只能通过主题的下载接口按权限取，所以链接无法转发给没有权限的人。', 'aurora' ),
			'fields' => array(
				'aurora_dl_show_history' => array(
					'label'   => __( '用户中心显示下载记录', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_dl_note'         => array(
					'label'   => __( '下载区提示文字', 'aurora' ),
					'type'    => 'text',
					'default' => '购买后长期可下载，若链接失效请联系站长。',
				),
			),
		),
		'notify'     => array(
			'label'  => __( '站内通知', 'aurora' ),
			'icon'   => 'bell',
			'desc'   => __( '站内消息中心。群发入口在「用户 → 站内通知」。', 'aurora' ),
			'fields' => array(
				'aurora_notify_enabled' => array(
					'label'   => __( '启用站内通知', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_notify_bell'    => array(
					'label'   => __( '顶部显示消息铃铛', 'aurora' ),
					'type'    => 'checkbox',
					'default' => true,
				),
				'aurora_notify_events'  => array(
					'label'   => __( '需要通知的事件', 'aurora' ),
					'type'    => 'checkbox_multi',
					'default' => array( 'order', 'refund', 'vip', 'points', 'card', 'reply' ),
					'options' => array(
						'order'  => __( '订单支付成功', 'aurora' ),
						'refund' => __( '退款完成', 'aurora' ),
						'vip'    => __( '会员开通/续期', 'aurora' ),
						'points' => __( '积分变动', 'aurora' ),
						'card'   => __( '卡密兑换', 'aurora' ),
						'reply'  => __( '文章收到回复', 'aurora' ),
					),
					'help'    => __( '系统公告不受此处限制，始终会送达。', 'aurora' ),
				),
				'aurora_notify_cap'     => array(
					'label'   => __( '每人最多保留条数', 'aurora' ),
					'type'    => 'number',
					'default' => 200,
					'min'     => 0,
					'max'     => 5000,
					'help'    => __( '超出后从最早的开始清理（优先清理已读）。0 表示不清理。', 'aurora' ),
				),
			),
		),
	);

	return apply_filters( 'aurora_setting_schema', $schema );
}

/**
 * Look up a single field definition.
 *
 * @param string $key Setting key.
 * @return array|null
 */
function aurora_setting_field( $key ) {
	foreach ( aurora_setting_schema() as $tab ) {
		if ( isset( $tab['fields'][ $key ] ) ) {
			return $tab['fields'][ $key ];
		}
	}

	return null;
}

/**
 * Register the top-level admin menu.
 */
function aurora_register_admin_menu() {
	add_menu_page(
		__( 'Aurora 主题设置', 'aurora' ),
		__( 'Aurora 主题', 'aurora' ),
		'manage_options',
		'aurora-settings',
		'aurora_render_admin_page',
		'dashicons-art',
		58
	);

	add_submenu_page(
		'aurora-settings',
		__( '主题设置', 'aurora' ),
		__( '主题设置', 'aurora' ),
		'manage_options',
		'aurora-settings',
		'aurora_render_admin_page'
	);

	add_submenu_page(
		'aurora-settings',
		__( '系统状态', 'aurora' ),
		__( '系统状态', 'aurora' ),
		'manage_options',
		'aurora-status',
		'aurora_render_status_page'
	);
}
add_action( 'admin_menu', 'aurora_register_admin_menu' );

/**
 * Current tab key.
 *
 * @return string
 */
function aurora_current_tab() {
	$schema = aurora_setting_schema();
	$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'appearance';

	return isset( $schema[ $tab ] ) ? $tab : array_key_first( $schema );
}

/**
 * Render the settings screen.
 */
function aurora_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$schema = aurora_setting_schema();
	$tab    = aurora_current_tab();
	$fields = $schema[ $tab ]['fields'];
	?>
	<div class="wrap aurora-admin">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Aurora 主题设置', 'aurora' ); ?></h1>
		<a href="<?php echo esc_url( admin_url( 'customize.php' ) ); ?>" class="page-title-action"><?php esc_html_e( '实时预览', 'aurora' ); ?></a>
		<hr class="wp-header-end">

		<?php if ( isset( $_GET['aurora_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '设置已保存。', 'aurora' ); ?></p></div>
		<?php endif; ?>

		<nav class="nav-tab-wrapper aurora-tabs-admin">
			<?php foreach ( $schema as $key => $group ) : ?>
				<a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( array( 'page' => 'aurora-settings', 'tab' => $key ), admin_url( 'admin.php' ) ) ); ?>">
					<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>" style="font-size:15px;width:15px;height:15px;line-height:1.65;margin-right:4px"></span>
					<?php echo esc_html( $group['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( ! empty( $schema[ $tab ]['desc'] ) ) : ?>
			<p class="description" style="max-width:760px;margin:16px 0 0"><?php echo esc_html( $schema[ $tab ]['desc'] ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:820px;margin-top:12px">
			<input type="hidden" name="action" value="aurora_save_settings">
			<input type="hidden" name="aurora_tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( 'aurora_save_settings' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
				<?php foreach ( $fields as $key => $field ) : ?>
					<?php
					$current = get_theme_mod( $key, $field['default'] ?? '' );
					$id      = esc_attr( $key );
					$label   = esc_html( $field['label'] ?? '' );
					$help    = isset( $field['help'] ) ? '<p class="description">' . esc_html( $field['help'] ) . '</p>' : '';
					?>
					<tr>
						<th scope="row"><label for="<?php echo $id; ?>"><?php echo $label; ?></label></th>
						<td>
							<?php
							switch ( $field['type'] ) {
								case 'checkbox':
									printf(
										'<label><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>',
										$id,
										$id,
										checked( (bool) $current, true, false ),
										esc_html__( '启用', 'aurora' )
									);
									break;

								case 'checkbox_multi':
									$selected = is_array( $current ) ? $current : (array) $current;
									echo '<fieldset>';
									foreach ( ( $field['options'] ?? array() ) as $option_key => $option_label ) {
										printf(
											'<label style="margin-right:18px;display:inline-block"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
											$id,
											esc_attr( $option_key ),
											checked( in_array( $option_key, $selected, true ), true, false ),
											esc_html( $option_label )
										);
									}
									echo '</fieldset>';
									break;

								case 'select':
									printf( '<select id="%s" name="%s">', $id, $id );
									foreach ( ( $field['options'] ?? array() ) as $option_key => $option_label ) {
										printf(
											'<option value="%s" %s>%s</option>',
											esc_attr( $option_key ),
											selected( (string) $current, (string) $option_key, false ),
											esc_html( $option_label )
										);
									}
									echo '</select>';
									break;

								case 'color':
									printf(
										'<input type="color" id="%s" name="%s" value="%s" style="width:64px;height:34px;padding:2px;vertical-align:middle"> <code style="margin-left:6px">%s</code>',
										$id,
										$id,
										esc_attr( $current ),
										esc_html( $current )
									);
									break;

								case 'textarea':
									printf(
										'<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>',
										$id,
										$id,
										esc_textarea( $current )
									);
									break;

								case 'html':
									if ( isset( $field['render'] ) && is_callable( $field['render'] ) ) {
										call_user_func( $field['render'], $field );
									}
									break;

								case 'secret':
									// Never echo the stored value back into the page: the field shows
									// only the last four characters, and leaving it empty keeps it.
									$stored_secret = (string) get_theme_mod( $key, '' );
									$secret_hint   = '' !== $stored_secret ? str_repeat( '•', 8 ) . substr( $stored_secret, -4 ) : '';

									printf(
										'<input type="password" id="%s" name="%s" value="" autocomplete="new-password" class="regular-text" placeholder="%s">',
										$id,
										$id,
										esc_attr(
											$secret_hint
												? sprintf( /* translators: masked key */ __( '已保存：%s（留空不改）', 'aurora' ), $secret_hint )
												: __( '粘贴 API Key', 'aurora' )
										)
									);

									if ( '' !== $secret_hint ) {
										printf(
											' <label style="margin-left:10px"><input type="checkbox" name="%s_clear" value="1"> %s</label>',
											$id,
											esc_html__( '清除', 'aurora' )
										);
									}
									break;

								case 'number':
									printf(
										'<input type="number" id="%s" name="%s" value="%s" min="%s" max="%s" step="%s" class="small-text">',
										$id,
										$id,
										esc_attr( $current ),
										esc_attr( $field['min'] ?? 0 ),
										esc_attr( $field['max'] ?? 1000 ),
										esc_attr( $field['step'] ?? ( ! empty( $field['float'] ) ? '0.1' : '1' ) )
									);
									break;

								case 'pem':
									$stored = file_exists( aurora_wxpay_dir() . '/apiclient_key.pem' );
									if ( $stored ) {
										printf(
											'<p style="margin:0 0 8px"><span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span> %s</p>',
											esc_html__( '已保存一份私钥（uploads/aurora-wechatpay/apiclient_key.pem，权限 600）。重新粘贴会覆盖。', 'aurora' )
										);
									}
									printf(
										'<textarea id="%s" name="%s" rows="7" class="large-text code" placeholder="-----BEGIN PRIVATE KEY-----&#10;…&#10;-----END PRIVATE KEY-----" autocomplete="off" spellcheck="false">%s</textarea>',
										$id,
										$id,
										esc_textarea( aurora_wxpay_pem_preview( $current ) )
									);
									break;

								case 'url':
									printf(
										'<input type="url" id="%s" name="%s" value="%s" class="regular-text" placeholder="https://">',
										$id,
										$id,
										esc_attr( $current )
									);
									break;

								default:
									printf(
										'<input type="text" id="%s" name="%s" value="%s" class="regular-text" %s>',
										$id,
										$id,
										esc_attr( $current ),
										! empty( $field['secret'] ) ? 'autocomplete="off" spellcheck="false"' : ''
									);
							}
							echo $help; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button( __( '保存设置', 'aurora' ) ); ?>
		</form>

		<?php
		/**
		 * Fires after a settings tab's form, for read-only panels and actions
		 * that are not settings fields (diagnostics, bulk jobs, test sends).
		 *
		 * @param string $tab Current tab key.
		 */
		do_action( 'aurora_after_settings_tab', $tab );
		?>
	</div>
	<?php
}

/**
 * Never round-trip a full private key back into the browser.
 *
 * @param string $current Stored value.
 * @return string
 */
function aurora_wxpay_pem_preview( $current ) {
	if ( ! $current || false === strpos( (string) $current, 'PRIVATE KEY' ) ) {
		return '';
	}

	return '';
}

/**
 * Persist every field of the submitted tab.
 */
function aurora_handle_admin_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		// 403, not the 500 that wp_die() defaults to: this is a permission
		// refusal, and a 500 would surface as a server error in logs.
		wp_die(
			esc_html__( '权限不足。', 'aurora' ),
			esc_html__( '权限不足', 'aurora' ),
			array( 'response' => 403 )
		);
	}
	check_admin_referer( 'aurora_save_settings' );

	$tab    = isset( $_POST['aurora_tab'] ) ? sanitize_key( $_POST['aurora_tab'] ) : '';
	$schema = aurora_setting_schema();

	if ( ! isset( $schema[ $tab ] ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=aurora-settings' ) );
		exit;
	}

	foreach ( $schema[ $tab ]['fields'] as $key => $field ) {
		$type = $field['type'];

		if ( 'checkbox' === $type ) {
			set_theme_mod( $key, isset( $_POST[ $key ] ) );
			continue;
		}

		if ( 'checkbox_multi' === $type ) {
			$raw     = isset( $_POST[ $key ] ) ? (array) wp_unslash( $_POST[ $key ] ) : array();
			$allowed = array_keys( $field['options'] ?? array() );
			$value   = array_values( array_intersect( $allowed, array_map( 'sanitize_key', $raw ) ) );
			set_theme_mod( $key, $value );
			continue;
		}

		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		if ( 'html' === $type ) {
			continue; // Presentational row: nothing to save.
		}

		if ( 'secret' === $type && ! empty( $_POST[ $key . '_clear' ] ) ) {
			set_theme_mod( $key, '' );
			continue;
		}

		switch ( $type ) {
			case 'secret':
				// Empty means "keep what is already stored" — the field is always
				// rendered blank so a masked key is never posted back.
				if ( '' === trim( (string) $raw ) ) {
					continue 2;
				}
				$value = sanitize_text_field( $raw );
				break;

			case 'pem':
				// Empty means "keep what is already stored".
				if ( '' === trim( (string) $raw ) ) {
					continue 2;
				}
				$stored = aurora_wxpay_store_key( (string) $raw );
				if ( is_wp_error( $stored ) ) {
					set_transient( 'aurora_admin_error_' . get_current_user_id(), $stored->get_error_message(), 60 );
					continue 2;
				}
				set_theme_mod( 'aurora_wxpay_key_pem', '' );
				continue 2;

			case 'number':
				// 温度这类字段要小数：整数化会把 0.8 存成 0，页面上看起来就是「没保存」。
				if ( ! empty( $field['float'] ) ) {
					$num   = (float) $raw;
					$value = max( (float) ( $field['min'] ?? 0 ), min( (float) ( $field['max'] ?? PHP_INT_MAX ), $num ) );
					$value = round( $value, 2 );
				} else {
					$value = (int) $raw;
					$value = max( (int) ( $field['min'] ?? 0 ), min( (int) ( $field['max'] ?? PHP_INT_MAX ), $value ) );
				}
				break;

			case 'color':
				$value = sanitize_hex_color( $raw ) ?: ( $field['default'] ?? '#0071e3' );
				break;

			case 'url':
				$value = esc_url_raw( $raw );
				break;

			case 'textarea':
				$value = wp_kses_post( $raw );
				break;

			case 'select':
				$value = isset( $field['options'][ $raw ] ) ? $raw : ( $field['default'] ?? '' );
				break;

			default:
				$value = sanitize_text_field( $raw );
		}

		set_theme_mod( $key, $value );
	}

	$extra = array();

	/*
	 * 「保存并测试连接」：同一个表单同时做两件事，省掉「先存再点测试」这一步。
	 * 测试用的是刚存下的配置，所以顺序必须是先保存、后请求。
	 */
	if ( isset( $_POST['aurora_do'] ) && 'aurora_ai_test' === sanitize_key( wp_unslash( $_POST['aurora_do'] ) )
		&& function_exists( 'aurora_ai_run_test' ) ) {
		$test            = aurora_ai_run_test();
		$extra['aurora_ai_test'] = $test['flag'];
	} else {
		$extra['aurora_saved'] = 1;
	}

	wp_safe_redirect( add_query_arg( array_merge( array(
		'page' => 'aurora-settings',
		'tab'  => $tab,
	), $extra ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_aurora_save_settings', 'aurora_handle_admin_save' );

/**
 * Show any error raised while saving.
 */
function aurora_admin_error_notice() {
	$key   = 'aurora_admin_error_' . get_current_user_id();
	$error = get_transient( $key );

	if ( ! $error ) {
		return;
	}
	delete_transient( $key );

	printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $error ) );
}
add_action( 'admin_notices', 'aurora_admin_error_notice' );

/* -------------------------------------------------------------------------
 * System status screen
 * ---------------------------------------------------------------------- */

/**
 * Render the diagnostics screen.
 */
function aurora_render_status_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$site_url = home_url( '/' );
	$is_https = ( 0 === strpos( $site_url, 'https://' ) );
	$wx       = aurora_wxpay_config();
	$wx_ok    = aurora_wxpay_configured();

	$row = static function ( $label, $ok, $detail = '' ) {
		printf(
			'<tr><td style="width:280px"><strong>%s</strong></td><td><span class="dashicons dashicons-%s" style="color:%s"></span> %s%s</td></tr>',
			esc_html( $label ),
			$ok ? 'yes-alt' : 'warning',
			$ok ? '#00a32a' : '#dba617',
			$ok ? esc_html__( '正常', 'aurora' ) : esc_html__( '需要处理', 'aurora' ),
			$detail ? '<div class="description">' . wp_kses_post( $detail ) . '</div>' : ''
		);
	};
	?>
	<div class="wrap aurora-admin">
		<h1><?php esc_html_e( 'Aurora 系统状态', 'aurora' ); ?></h1>
		<p class="description" style="max-width:760px"><?php esc_html_e( '上线前逐项检查。带警告的项目不影响主题浏览，但会影响收款或搜索收录。', 'aurora' ); ?></p>

		<?php $report = function_exists( 'aurora_env_report' ) ? aurora_env_report() : array( 'items' => array(), 'ready' => true ); ?>
		<h2 style="display:flex;align-items:center;gap:12px">
			<?php esc_html_e( '安装自检', 'aurora' ); ?>
			<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=aurora-status&aurora_recheck=1' ), 'aurora_recheck' ) ); ?>"><?php esc_html_e( '重新检测', 'aurora' ); ?></a>
			<?php if ( isset( $_GET['rechecked'] ) ) : ?>
				<span class="description"><?php esc_html_e( '刚刚重新检测过。', 'aurora' ); ?></span>
			<?php endif; ?>
		</h2>
		<p class="description" style="max-width:760px"><?php esc_html_e( '主题首次启用时会自动跑一遍这里的检查；不通过会跳到下载与安装说明页。下面的结果随时可以手动重跑。', 'aurora' ); ?></p>
		<table class="widefat striped"><tbody>
			<?php foreach ( $report['items'] as $item ) : ?>
				<tr>
					<td style="width:280px"><strong><?php echo esc_html( $item['label'] ); ?></strong>
						<?php if ( empty( $item['required'] ) ) : ?>
							<span class="description"><?php esc_html_e( '（建议）', 'aurora' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<span class="dashicons dashicons-<?php echo $item['ok'] ? 'yes-alt' : ( $item['required'] ? 'warning' : 'info' ); ?>"
							style="color:<?php echo $item['ok'] ? '#00a32a' : ( $item['required'] ? '#b32d2e' : '#dba617' ); ?>"></span>
						<?php echo esc_html( $item['ok'] ? __( '正常', 'aurora' ) : ( $item['required'] ? __( '需要处理', 'aurora' ) : __( '建议调整', 'aurora' ) ) ); ?>
						<span class="description" style="display:inline;margin-left:8px"><?php echo esc_html( $item['detail'] ); ?></span>
						<?php if ( ! $item['ok'] && ! empty( $item['hint'] ) ) : ?>
							<div class="description"><?php echo esc_html( $item['hint'] ); ?></div>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody></table>

		<?php if ( function_exists( 'aurora_onboard_status_block' ) ) : ?>
			<?php aurora_onboard_status_block(); ?>
		<?php endif; ?>

		<h2><?php esc_html_e( '运行环境', 'aurora' ); ?></h2>
		<table class="widefat striped"><tbody>
			<?php
			$row( __( 'OpenSSL 扩展', 'aurora' ), extension_loaded( 'openssl' ), __( '微信支付签名与回调解密依赖它。', 'aurora' ) );
			$row( __( 'AES-256-GCM', 'aurora' ), in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ), __( '解密支付回调必需。', 'aurora' ) );
			$row( __( 'qrencode 命令', 'aurora' ), (bool) trim( (string) shell_exec( 'command -v qrencode 2>/dev/null' ) ), __( '用来把微信支付链接渲染成二维码。缺失时前台会降级为可复制的链接。', 'aurora' ) );
			?>
		</tbody></table>

		<h2><?php esc_html_e( '站点可达性', 'aurora' ); ?></h2>
		<table class="widefat striped"><tbody>
			<?php
			$row( __( '站点被搜索引擎收录', 'aurora' ), (bool) get_option( 'blog_public' ), __( '关闭时 /wp-sitemap.xml 会返回 404。', 'aurora' ) );
			$row( __( '全站 HTTPS', 'aurora' ), $is_https, sprintf(
				/* translators: %s: current site URL */
				esc_html__( '当前地址 %s。微信支付要求回调地址为微信服务器可访问的 HTTPS 域名，IP + 端口不可用。', 'aurora' ),
				esc_html( $site_url )
			) );
			$row( __( 'REST API', 'aurora' ), true, esc_url( rest_url( 'aurora/v1/' ) ) );
			?>
		</tbody></table>

		<h2><?php esc_html_e( '微信支付', 'aurora' ); ?></h2>
		<table class="widefat striped"><tbody>
			<?php
			$row( __( '商户号 mchid', 'aurora' ), (bool) $wx['mchid'] );
			$row( __( 'AppID', 'aurora' ), (bool) $wx['appid'] );
			$row( __( '证书序列号', 'aurora' ), (bool) $wx['serial_no'] );
			$row( __( 'APIv3 密钥（32 位）', 'aurora' ), 32 === strlen( $wx['apiv3_key'] ), __( '当前长度：', 'aurora' ) . strlen( $wx['apiv3_key'] ) );
			$row( __( '商户私钥', 'aurora' ), (bool) aurora_wxpay_private_key() );
			$row( __( '回调地址', 'aurora' ), $is_https, esc_url( $wx['notify_url'] ) );

			if ( $wx_ok ) {
				$certs = aurora_wxpay_platform_certs();
				if ( is_wp_error( $certs ) ) {
					printf(
						'<tr><td><strong>%s</strong></td><td><span class="dashicons dashicons-warning" style="color:#dba617"></span> %s<div class="description">%s</div></td></tr>',
						esc_html__( '平台证书', 'aurora' ),
						esc_html__( '拉取失败', 'aurora' ),
						esc_html( $certs->get_error_message() )
					);
				} else {
					printf(
						'<tr><td><strong>%s</strong></td><td><span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span> %s</td></tr>',
						esc_html__( '平台证书', 'aurora' ),
						esc_html( sprintf( __( '已缓存 %d 张', 'aurora' ), count( $certs ) ) )
					);
				}
			}
			?>
		</tbody></table>

		<?php if ( ! $wx_ok ) : ?>
			<p class="notice notice-warning inline" style="padding:10px 14px">
				<?php esc_html_e( '微信支付尚未配置完成，收银台不会显示该方式。补齐上面标黄的项即可启用。', 'aurora' ); ?>
			</p>
		<?php endif; ?>

		<h2><?php esc_html_e( '热点数据源', 'aurora' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( '来源', 'aurora' ); ?></th>
				<th><?php esc_html_e( '状态', 'aurora' ); ?></th>
				<th><?php esc_html_e( '抓取条数', 'aurora' ); ?></th>
				<th><?php esc_html_e( '更新时间', 'aurora' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			$cache     = get_transient( AURORA_HOT_CACHE_KEY );
			$providers = aurora_hot_providers();
			$enabled   = aurora_hot_enabled();

			foreach ( $providers as $key => $provider ) :
				$on   = in_array( $key, $enabled, true );
				$info = $cache['sources'][ $key ] ?? array();
				$when = ! empty( $info['time'] ) ? human_time_diff( $info['time'] ) . ' ' . __( '前', 'aurora' ) : '—';
				?>
				<tr>
					<td><strong><?php echo esc_html( $provider['label'] ); ?></strong>
						<?php if ( ! $on ) : ?>
							<span class="description">（<?php esc_html_e( '未启用', 'aurora' ); ?>）</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! $on ) : ?>
							—
						<?php elseif ( ! empty( $info['error'] ) ) : ?>
							<span style="color:#d63638"><?php echo esc_html( $info['error'] ); ?></span>
						<?php elseif ( ! empty( $info['items'] ) ) : ?>
							<span style="color:#00a32a"><?php esc_html_e( '正常', 'aurora' ); ?></span>
						<?php else : ?>
							<?php esc_html_e( '暂无数据', 'aurora' ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( count( (array) ( $info['items'] ?? array() ) ) ); ?></td>
					<td><?php echo esc_html( $when ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin-top:14px">
			<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=aurora-status&aurora_hot_refresh=1' ), 'aurora_hot_refresh' ) ); ?>">
				<?php esc_html_e( '立即刷新热点缓存', 'aurora' ); ?>
			</a>
			<?php if ( isset( $_GET['aurora_hot_done'] ) ) : ?>
				<span style="margin-left:10px;color:#00a32a"><?php esc_html_e( '已刷新。', 'aurora' ); ?></span>
			<?php endif; ?>
		</p>

		<h2><?php esc_html_e( '内容统计', 'aurora' ); ?></h2>
		<table class="widefat striped"><tbody>
			<?php
			$counts = array(
				__( '文章', 'aurora' )   => wp_count_posts( 'post' )->publish,
				__( '公告', 'aurora' )   => wp_count_posts( 'aurora_notice' )->publish,
				__( '网址', 'aurora' )   => wp_count_posts( 'aurora_link' )->publish,
				__( '订单', 'aurora' )   => wp_count_posts( 'aurora_order' )->publish,
				__( '会员用户', 'aurora' ) => count( get_users( array(
					'meta_key'   => 'aurora_vip_level',
					'meta_value' => 0,
					'meta_compare' => '>',
					'fields'     => 'ID',
				) ) ),
			);
			foreach ( $counts as $label => $value ) {
				printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html( $label ), esc_html( number_format_i18n( (int) $value ) ) );
			}
			?>
		</tbody></table>
	</div>
	<?php
}
