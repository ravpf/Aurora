<?php
/**
 * Single product template.
 *
 * The buy form posts the same `aurora_checkout` nonce the membership checkout
 * uses, so there is one checkout path on the site rather than two that drift.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

aurora_breadcrumbs();
?>

<div class="aurora-container">
	<?php
	while ( have_posts() ) :
		the_post();

		$aurora_pid     = get_the_ID();
		$aurora_meta    = aurora_product_meta( $aurora_pid );
		$aurora_payload = aurora_product_script_data( $aurora_pid );
		$aurora_user    = get_current_user_id();
		$aurora_owned   = $aurora_user && aurora_product_owned( $aurora_user, $aurora_pid );
		// With variants the product-level stock is meaningless — the page preselects
		// the first value of each group, so start from that combination's stock, which
		// is also what the script will show on load.
		$aurora_picked = array();

		foreach ( $aurora_meta['options'] as $aurora_group ) {
			$aurora_picked[ $aurora_group['name'] ] = $aurora_group['values'][0];
		}

		$aurora_default_variant = $aurora_picked ? aurora_variant_key( $aurora_picked ) : '';
		$aurora_stock           = aurora_product_stock( $aurora_pid, $aurora_default_variant );
		$aurora_gws     = aurora_available_gateways();
		$aurora_gw      = isset( $aurora_gws['manual'] ) ? 'manual' : key( $aurora_gws );
		$aurora_msg     = isset( $_GET['aurora_msg'] ) && 'created' !== $_GET['aurora_msg']
			? urldecode( sanitize_text_field( wp_unslash( $_GET['aurora_msg'] ) ) )
			: '';
		?>

		<div class="aurora-layout">
			<main id="aurora-primary" class="aurora-primary">

				<article id="post-<?php the_ID(); ?>" <?php post_class( 'aurora-article' ); ?>>
					<header class="aurora-article__header">
						<h1 class="aurora-article__title"><?php the_title(); ?></h1>

						<?php if ( has_excerpt() ) : ?>
							<p class="aurora-article__lead"><?php echo esc_html( get_the_excerpt() ); ?></p>
						<?php endif; ?>

						<div class="aurora-article__meta">
							<span><?php echo esc_html( sprintf( __( '已售 %d 件', 'aurora' ), aurora_product_sold_qty( $aurora_pid ) ) ); ?></span>
							<?php if ( $aurora_meta['sku'] ) : ?>
								<span><?php echo esc_html( sprintf( __( 'SKU：%s', 'aurora' ), $aurora_meta['sku'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( 'physical' === $aurora_meta['kind'] ) : ?>
								<span><?php esc_html_e( '实物商品', 'aurora' ); ?></span>
							<?php endif; ?>
						</div>
					</header>

					<?php if ( has_post_thumbnail() ) : ?>
						<figure class="aurora-article__hero"><?php the_post_thumbnail( 'large' ); ?></figure>
					<?php endif; ?>

					<div class="aurora-article__body">
						<?php the_content(); ?>
					</div>
				</article>

				<?php comments_template(); ?>
			</main>

			<aside class="aurora-sidebar">
				<div class="aurora-panel aurora-buybox" data-product="<?php echo esc_attr( $aurora_pid ); ?>">
					<h3 class="aurora-panel__title"><?php esc_html_e( '购买', 'aurora' ); ?></h3>

					<?php if ( $aurora_msg ) : ?>
						<p class="aurora-buybox__msg" role="alert"><?php echo esc_html( $aurora_msg ); ?></p>
					<?php endif; ?>

					<p class="aurora-buybox__price">
						<span class="aurora-buybox__now" data-price>
							<?php echo esc_html( '¥' . number_format( aurora_product_price( $aurora_pid, $aurora_default_variant ), 2 ) ); ?>
						</span>
						<?php if ( $aurora_meta['compare_price'] > $aurora_meta['price'] ) : ?>
							<del class="aurora-buybox__was"><?php echo esc_html( '¥' . number_format( $aurora_meta['compare_price'], 2 ) ); ?></del>
						<?php endif; ?>
					</p>

					<?php if ( $aurora_meta['managed'] && null !== $aurora_stock ) : ?>
						<p class="aurora-buybox__stock" data-stock>
							<?php
							echo esc_html(
								$aurora_stock > 0
									? sprintf( __( '库存 %d 件', 'aurora' ), $aurora_stock )
									: __( '暂时缺货', 'aurora' )
							);
							?>
						</p>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( get_permalink() ); ?>" class="aurora-buybox__form" data-variants="<?php echo esc_attr( wp_json_encode( $aurora_payload ) ); ?>">
						<?php wp_nonce_field( 'aurora_checkout', 'aurora_checkout_nonce' ); ?>
						<input type="hidden" name="aurora_product_id" value="<?php echo esc_attr( $aurora_pid ); ?>">
						<input type="hidden" name="aurora_variant" value="<?php echo esc_attr( $aurora_default_variant ); ?>" data-variant>

						<?php foreach ( $aurora_meta['options'] as $aurora_index => $aurora_group ) : ?>
							<fieldset class="aurora-buybox__group">
								<legend><?php echo esc_html( $aurora_group['name'] ); ?></legend>
								<div class="aurora-buybox__values">
									<?php foreach ( $aurora_group['values'] as $aurora_value ) : ?>
										<label class="aurora-buybox__opt">
											<input type="radio"
												name="opt_<?php echo esc_attr( (string) $aurora_index ); ?>"
												value="<?php echo esc_attr( $aurora_value ); ?>"
												data-group="<?php echo esc_attr( $aurora_group['name'] ); ?>"
												<?php checked( $aurora_value, $aurora_group['values'][0] ); ?>>
											<span><?php echo esc_html( $aurora_value ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>
						<?php endforeach; ?>

						<p class="aurora-buybox__qty">
							<label for="aurora_qty"><?php esc_html_e( '数量', 'aurora' ); ?></label>
							<input type="number" id="aurora_qty" name="aurora_qty" min="1" step="1"
								value="1" <?php echo ( $aurora_meta['limit'] > 0 ) ? 'max="' . esc_attr( (string) $aurora_meta['limit'] ) . '"' : ''; ?>>
						</p>

						<?php if ( count( $aurora_gws ) > 1 ) : ?>
							<p class="aurora-buybox__gw">
								<label for="gateway"><?php esc_html_e( '支付方式', 'aurora' ); ?></label>
								<select id="gateway" name="gateway">
									<?php foreach ( $aurora_gws as $aurora_key => $aurora_label ) : ?>
										<option value="<?php echo esc_attr( $aurora_key ); ?>" <?php selected( $aurora_gw, $aurora_key ); ?>>
											<?php echo esc_html( $aurora_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</p>
						<?php else : ?>
							<input type="hidden" name="gateway" value="<?php echo esc_attr( $aurora_gw ); ?>">
						<?php endif; ?>

						<p class="aurora-buybox__coupon">
							<label for="coupon"><?php esc_html_e( '优惠码', 'aurora' ); ?></label>
							<input type="text" id="coupon" name="coupon" placeholder="<?php esc_attr_e( '选填', 'aurora' ); ?>">
						</p>

						<?php if ( $aurora_owned ) : ?>
							<p class="aurora-buybox__owned">
								<?php esc_html_e( '你已购买过该商品，仍可再次购买。', 'aurora' ); ?>
							</p>
						<?php endif; ?>

						<button type="submit" class="aurora-btn aurora-btn--primary aurora-btn--lg aurora-btn--block" data-buy>
							<?php esc_html_e( '立即购买', 'aurora' ); ?>
						</button>

						<button type="button" class="aurora-btn aurora-btn--lg aurora-btn--block aurora-buybox__cart" data-cart-add>
							<?php esc_html_e( '加入购物车', 'aurora' ); ?>
						</button>
						<p class="aurora-buybox__feedback" data-cart-msg role="status" aria-live="polite"></p>
					</form>

					<p class="aurora-buybox__note">
						<?php esc_html_e( '下单后库存立即为该订单保留，未支付超时会自动取消并释放。', 'aurora' ); ?>
					</p>
				</div>
			</aside>
		</div>
	<?php endwhile; ?>
</div>

<script>
( function () {
	var form = document.querySelector( '.aurora-buybox__form' );
	if ( ! form ) { return; }

	var data   = JSON.parse( form.getAttribute( 'data-variants' ) || '{}' );
	var hidden = form.querySelector( '[data-variant]' );
	var price  = document.querySelector( '[data-price]' );
	var stock  = document.querySelector( '[data-stock]' );
	var button = form.querySelector( '[data-buy]' );
	var cartBtn = form.querySelector( '[data-cart-add]' );
	var cartMsg = form.querySelector( '[data-cart-msg]' );

	function signature() {
		var picked = [];
		form.querySelectorAll( 'input[type="radio"]:checked' ).forEach( function ( input ) {
			picked.push( input.getAttribute( 'data-group' ) + '=' + input.value );
		} );
		picked.sort();
		return picked.join( '|' );
	}

	function money( n ) {
		return '¥' + Number( n ).toFixed( 2 );
	}

	function refresh() {
		var variant = ( data.variants || {} )[ signature() ];
		var shown   = variant ? variant.price : data.base;
		var left    = variant ? variant.stock : data.stock;
		var ok      = true;

		if ( variant ) {
			hidden.value = variant.key;
			ok = variant.enabled;
		} else if ( Object.keys( data.variants || {} ).length ) {
			// A combination nobody sells: the button must not pretend otherwise.
			ok = false;
			hidden.value = '';
		}

		if ( price ) { price.textContent = money( shown ); }

		if ( stock && data.managed ) {
			if ( ! ok ) {
				stock.textContent = '此规格暂不可购买';
			} else if ( null === left ) {
				stock.textContent = '';
			} else {
				stock.textContent = left > 0 ? '库存 ' + left + ' 件' : '暂时缺货';
			}
		}

		if ( button ) {
			button.disabled = ! ok || ( data.managed && ! variant && ! data.variants ) ;
			button.style.opacity = button.disabled ? '0.5' : '';
		}

		// Adding to the cart has exactly the same availability as buying now.
		if ( cartBtn ) {
			cartBtn.disabled = button ? button.disabled : false;
			cartBtn.style.opacity = cartBtn.disabled ? '0.5' : '';
		}
	}

	if ( cartBtn ) {
		cartBtn.addEventListener( 'click', function () {
			if ( cartBtn.disabled ) { return; }

			var body = new URLSearchParams();
			body.set( 'action', 'aurora_cart_add' );
			body.set( 'nonce', Aurora.nonce );
			body.set( 'product_id', data.id );
			body.set( 'variant', hidden ? hidden.value : '' );
			body.set( 'qty', ( qty ? qty.value : 1 ) );

			cartBtn.disabled = true;
			if ( cartMsg ) { cartMsg.textContent = '正在加入…'; }

			fetch( Aurora.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) {
					var text = ( d && d.data && d.data.message ) || '加入失败，请重试。';
					if ( cartMsg ) {
						cartMsg.textContent = text;
						cartMsg.classList.toggle( 'is-error', ! ( d && d.success ) );
					}
					if ( d && d.success && d.data.totals ) {
						var badge = document.querySelector( '[data-cart-badge]' );
						if ( badge ) {
							badge.textContent = d.data.totals.count;
							badge.hidden = ! d.data.totals.count;
						}
					}
				} )
				.catch( function () { if ( cartMsg ) { cartMsg.textContent = '网络错误，请重试。'; } } )
				.then( function () { cartBtn.disabled = false; } );
		} );
	}

	form.addEventListener( 'change', refresh );
	refresh();
}() );
</script>

<?php get_footer(); ?>
