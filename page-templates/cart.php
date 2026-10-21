<?php
/**
 * Template Name: 购物车
 *
 * Cart page. Quantity changes are AJAX so the totals move without a reload; the
 * checkout itself is a plain form post, so it still works if the script does not.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();
aurora_breadcrumbs();

$aurora_cart_user = get_current_user_id();
$aurora_items     = $aurora_cart_user ? aurora_cart_items( $aurora_cart_user ) : array();
$aurora_totals    = $aurora_cart_user ? aurora_cart_totals( $aurora_cart_user ) : array( 'count' => 0, 'lines' => 0, 'sellable' => 0, 'subtotal' => 0, 'problems' => 0, 'checkout' => false );
$aurora_gateways  = aurora_available_gateways();
$aurora_gw        = isset( $aurora_gateways['manual'] ) ? 'manual' : key( $aurora_gateways );
$aurora_msg       = isset( $_GET['aurora_msg'] ) ? urldecode( sanitize_text_field( wp_unslash( $_GET['aurora_msg'] ) ) ) : '';
?>

<div class="aurora-container">
	<div class="aurora-layout">
		<main id="aurora-primary" class="aurora-primary">

			<header class="aurora-page-header">
				<h1 class="aurora-article__title"><?php esc_html_e( '购物车', 'aurora' ); ?></h1>
				<p class="aurora-article__lead">
					<?php
					if ( $aurora_totals['lines'] ) {
						printf(
							/* translators: 1: distinct lines, 2: total units */
							esc_html__( '%1$d 种商品，共 %2$d 件。', 'aurora' ),
							(int) $aurora_totals['lines'],
							(int) $aurora_totals['count']
						);
					} else {
						esc_html_e( '还没有放入任何商品。', 'aurora' );
					}
					?>
				</p>
			</header>

			<?php if ( $aurora_msg ) : ?>
				<div class="aurora-panel aurora-cart__msg" role="alert"><?php echo esc_html( $aurora_msg ); ?></div>
			<?php endif; ?>

			<?php if ( ! $aurora_cart_user ) : ?>
				<div class="aurora-panel">
					<p><?php esc_html_e( '登录后才能查看购物车。', 'aurora' ); ?></p>
					<p>
						<a class="aurora-btn aurora-btn--primary" href="<?php echo esc_url( wp_login_url( aurora_cart_page_url() ) ); ?>">
							<?php esc_html_e( '登录', 'aurora' ); ?>
						</a>
					</p>
				</div>

			<?php elseif ( ! $aurora_items ) : ?>
				<div class="aurora-panel aurora-cart__empty">
					<p><?php esc_html_e( '购物车是空的。', 'aurora' ); ?></p>
					<p>
						<a class="aurora-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( '回首页逛逛', 'aurora' ); ?></a>
					</p>
				</div>

			<?php else : ?>
				<div class="aurora-panel">
					<ul class="aurora-cart" data-cart>
						<?php foreach ( $aurora_items as $aurora_key => $aurora_item ) : ?>
							<li class="aurora-cart__row<?php echo $aurora_item['problem'] ? ' is-blocked' : ''; ?>" data-key="<?php echo esc_attr( $aurora_key ); ?>">
								<div class="aurora-cart__thumb">
									<?php if ( $aurora_item['thumb'] ) : ?>
										<img src="<?php echo esc_url( $aurora_item['thumb'] ); ?>" alt="" loading="lazy">
									<?php endif; ?>
								</div>

								<div class="aurora-cart__info">
									<a class="aurora-cart__name" href="<?php echo esc_url( $aurora_item['url'] ); ?>"><?php echo esc_html( $aurora_item['name'] ); ?></a>

									<?php if ( $aurora_item['label'] ) : ?>
										<span class="aurora-cart__variant"><?php echo esc_html( $aurora_item['label'] ); ?></span>
									<?php endif; ?>

									<?php if ( $aurora_item['problem'] ) : ?>
										<span class="aurora-cart__problem"><?php echo esc_html( $aurora_item['problem'] ); ?></span>
									<?php endif; ?>
								</div>

								<div class="aurora-cart__price">
									<?php echo esc_html( '¥' . number_format( (float) $aurora_item['price'], 2 ) ); ?>
								</div>

								<div class="aurora-cart__qty">
									<button type="button" class="aurora-cart__step" data-step="-1" aria-label="<?php esc_attr_e( '减少', 'aurora' ); ?>">−</button>
									<input type="number" min="0" step="1" value="<?php echo esc_attr( (string) $aurora_item['qty'] ); ?>" data-qty>
									<button type="button" class="aurora-cart__step" data-step="1" aria-label="<?php esc_attr_e( '增加', 'aurora' ); ?>">+</button>
								</div>

								<div class="aurora-cart__sub" data-subtotal>
									<?php echo esc_html( '¥' . number_format( (float) $aurora_item['subtotal'], 2 ) ); ?>
								</div>

								<button type="button" class="aurora-cart__remove" data-remove aria-label="<?php esc_attr_e( '移出购物车', 'aurora' ); ?>">×</button>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="aurora-cart__note" data-cart-msg role="status" aria-live="polite"></p>
				</div>

				<div class="aurora-panel aurora-cart__summary">
					<h3 class="aurora-panel__title"><?php esc_html_e( '结算', 'aurora' ); ?></h3>

					<form method="post" action="<?php echo esc_url( aurora_cart_page_url() ); ?>">
						<?php wp_nonce_field( 'aurora_cart_checkout', 'aurora_cart_checkout_nonce' ); ?>

						<p class="aurora-cart__total">
							<span><?php esc_html_e( '商品合计', 'aurora' ); ?></span>
							<strong data-total><?php echo esc_html( '¥' . number_format( (float) $aurora_totals['subtotal'], 2 ) ); ?></strong>
						</p>

						<p class="aurora-cart__coupon">
							<label for="coupon"><?php esc_html_e( '优惠码', 'aurora' ); ?></label>
							<input type="text" id="coupon" name="coupon" placeholder="<?php esc_attr_e( '选填', 'aurora' ); ?>">
						</p>

						<?php if ( count( $aurora_gateways ) > 1 ) : ?>
							<p>
								<label for="gateway"><?php esc_html_e( '支付方式', 'aurora' ); ?></label>
								<select id="gateway" name="gateway">
									<?php foreach ( $aurora_gateways as $aurora_gk => $aurora_gl ) : ?>
										<option value="<?php echo esc_attr( $aurora_gk ); ?>" <?php selected( $aurora_gw, $aurora_gk ); ?>>
											<?php echo esc_html( is_array( $aurora_gl ) ? ( $aurora_gl['title'] ?? $aurora_gk ) : $aurora_gl ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</p>
						<?php else : ?>
							<input type="hidden" name="gateway" value="<?php echo esc_attr( $aurora_gw ); ?>">
						<?php endif; ?>

						<?php if ( ! $aurora_totals['checkout'] ) : ?>
							<p class="aurora-cart__blocked">
								<?php esc_html_e( '先处理上面标出的问题才能结算。', 'aurora' ); ?>
							</p>
						<?php endif; ?>

						<button type="submit" class="aurora-btn aurora-btn--primary aurora-btn--lg aurora-btn--block" <?php disabled( ! $aurora_totals['checkout'] ); ?>>
							<?php esc_html_e( '提交订单', 'aurora' ); ?>
						</button>
					</form>

					<p class="aurora-buybox__note">
						<?php esc_html_e( '提交后库存立即为这笔订单保留；未支付超时会自动取消并释放。', 'aurora' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php while ( have_posts() ) : the_post(); ?>
				<?php if ( trim( get_the_content() ) ) : ?>
					<div class="aurora-panel"><?php the_content(); ?></div>
				<?php endif; ?>
			<?php endwhile; ?>
		</main>

		<aside class="aurora-sidebar">
			<div class="aurora-panel">
				<h3 class="aurora-panel__title"><?php aurora_the_icon( 'cart', 17 ); ?><?php esc_html_e( '结算说明', 'aurora' ); ?></h3>
				<ul class="aurora-cart__help">
					<li><?php esc_html_e( '价格以下单时的商品价格为准，购物车里显示的价格会随商品变动。', 'aurora' ); ?></li>
					<li><?php esc_html_e( '同一规格再加一次数量会累加，不同规格各占一行。', 'aurora' ); ?></li>
					<li><?php esc_html_e( '限购按已支付订单加购物车数量合计。', 'aurora' ); ?></li>
				</ul>
			</div>
		</aside>
	</div>
</div>

<script>
( function () {
	var list = document.querySelector( '[data-cart]' );
	if ( ! list ) { return; }

	var msg = document.querySelector( '[data-cart-msg]' );
	var total = document.querySelector( '[data-total]' );

	function money( n ) { return '¥' + Number( n ).toFixed( 2 ); }

	function post( action, key, qty ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', Aurora.nonce );
		body.set( 'key', key );
		if ( undefined !== qty ) { body.set( 'qty', qty ); }

		return fetch( Aurora.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } );
	}

	function apply( data ) {
		if ( ! data || ! data.data || ! data.data.totals ) { return; }
		var totals = data.data.totals;

		if ( total ) { total.textContent = money( totals.subtotal ); }
		if ( msg ) { msg.textContent = data.data.message || ''; }

		// Keep the header badge honest without a reload.
		var badge = document.querySelector( '[data-cart-badge]' );
		if ( badge ) {
			badge.textContent = totals.count;
			badge.hidden = ! totals.count;
		}

		if ( ! totals.lines ) { window.location.reload(); }
		else if ( ! totals.checkout ) { window.location.reload(); }
	}

	function syncRow( row, qty ) {
		var sub  = row.querySelector( '[data-subtotal]' );
		var unit = parseFloat( row.querySelector( '.aurora-cart__price' ).textContent.replace( /[^0-9.]/g, '' ) );
		if ( sub ) { sub.textContent = money( unit * qty ); }
	}

	function fail( data, row ) {
		if ( msg ) { msg.textContent = ( data && data.data && data.data.message ) || '操作失败'; }

		// Put the field back to what the server says it holds.
		if ( row && data && data.data && undefined !== data.data.qty ) {
			row.querySelector( '[data-qty]' ).value = data.data.qty;
			syncRow( row, data.data.qty );
		}
	}

	list.addEventListener( 'click', function ( event ) {
		var row = event.target.closest( '.aurora-cart__row' );
		if ( ! row ) { return; }
		var key = row.getAttribute( 'data-key' );

		if ( event.target.closest( '[data-remove]' ) ) {
			post( 'aurora_cart_remove', key ).then( function ( d ) {
				if ( d && d.success ) { row.remove(); apply( d ); } else { fail( d ); }
			} );
			return;
		}

		var step = event.target.closest( '[data-step]' );
		if ( step ) {
			var input = row.querySelector( '[data-qty]' );
			var next = Math.max( 0, parseInt( input.value, 10 ) + parseInt( step.getAttribute( 'data-step' ), 10 ) );
			input.value = next;
			post( 'aurora_cart_qty', key, next ).then( function ( d ) {
				if ( d && d.success ) {
					apply( d );
					syncRow( row, next );
				} else { fail( d, row ); }
			} );
		}
	} );

	list.addEventListener( 'change', function ( event ) {
		var input = event.target.closest( '[data-qty]' );
		if ( ! input ) { return; }
		var row = input.closest( '.aurora-cart__row' );
		post( 'aurora_cart_qty', row.getAttribute( 'data-key' ), Math.max( 0, parseInt( input.value, 10 ) ) ).then( function ( d ) {
			if ( d && d.success ) { apply( d ); } else { fail( d, row ); }
		} );
	} );
}() );
</script>

<?php get_footer(); ?>
