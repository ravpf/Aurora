<?php
/**
 * Trending topics card.
 *
 * @package Aurora
 *
 * @var array $args sources (array|null), layout (card|page), limit (int).
 */

defined( 'ABSPATH' ) || exit;

$aurora_all      = aurora_hot_providers();
$aurora_enabled  = aurora_hot_enabled();
$aurora_request  = isset( $args['sources'] ) && is_array( $args['sources'] ) ? $args['sources'] : null;
$aurora_active   = $aurora_request
	? array_values( array_intersect( $aurora_enabled, $aurora_request ) )
	: $aurora_enabled;
$aurora_layout   = $args['layout'] ?? 'card';
$aurora_limit    = isset( $args['limit'] ) ? (int) $args['limit'] : 10;

if ( empty( $aurora_active ) ) {
	return;
}

$aurora_data = aurora_hot_topics();
$aurora_data = array_intersect_key( $aurora_data, array_flip( $aurora_active ) );

// Nothing came back at all — stay quiet rather than render an empty shell.
if ( ! array_filter( $aurora_data ) ) {
	return;
}

$aurora_first = array_key_first( $aurora_data );
$aurora_id    = 'aurora-hot-' . wp_unique_id();
?>

<section class="aurora-hot <?php echo 'page' === $aurora_layout ? 'aurora-hot--page' : ''; ?>" data-hot>
	<div class="aurora-hot__head">
		<h3 class="aurora-hot__title">
			<?php aurora_the_icon( 'fire', 18 ); ?>
			<?php esc_html_e( '全网热点', 'aurora' ); ?>
		</h3>
		<span class="aurora-hot__time">
			<?php
			printf(
				/* translators: %s: relative time */
				esc_html__( '%s前更新', 'aurora' ),
				esc_html( human_time_diff( (int) ( get_transient( AURORA_HOT_CACHE_KEY )['sources'][ $aurora_first ]['time'] ?? time() ) ) )
			);
			?>
		</span>
	</div>

	<div class="aurora-hot__tabs" role="tablist">
		<?php foreach ( $aurora_data as $aurora_key => $aurora_items ) : ?>
			<button type="button"
				class="aurora-hot__tab <?php echo $aurora_key === $aurora_first ? 'is-active' : ''; ?>"
				data-hot-tab="<?php echo esc_attr( $aurora_key ); ?>"
				style="--hot-accent:<?php echo esc_attr( $aurora_all[ $aurora_key ]['accent'] ); ?>"
				role="tab"
				aria-selected="<?php echo $aurora_key === $aurora_first ? 'true' : 'false'; ?>">
				<?php echo esc_html( $aurora_all[ $aurora_key ]['label'] ); ?>
			</button>
		<?php endforeach; ?>
	</div>

	<?php foreach ( $aurora_data as $aurora_key => $aurora_items ) : ?>
		<?php if ( empty( $aurora_items ) ) { continue; } ?>
		<ol class="aurora-hot__list <?php echo $aurora_key === $aurora_first ? 'is-active' : ''; ?>"
			data-hot-pane="<?php echo esc_attr( $aurora_key ); ?>"
			style="--hot-accent:<?php echo esc_attr( $aurora_all[ $aurora_key ]['accent'] ); ?>">
			<?php foreach ( array_slice( $aurora_items, 0, $aurora_limit ) as $aurora_index => $aurora_item ) : ?>
				<li class="aurora-hot__item">
					<a href="<?php echo esc_url( $aurora_item['url'] ? $aurora_item['url'] : '#' ); ?>"
						<?php if ( $aurora_item['url'] ) : ?>target="_blank" rel="noopener nofollow"<?php endif; ?>>
						<span class="aurora-hot__rank aurora-hot__rank--<?php echo esc_attr( min( 3, $aurora_index + 1 ) ); ?>">
							<?php echo esc_html( $aurora_index + 1 ); ?>
						</span>
						<span class="aurora-hot__text"><?php echo esc_html( $aurora_item['title'] ); ?></span>
						<?php if ( $aurora_item['tag'] ) : ?>
							<span class="aurora-hot__tag"><?php echo esc_html( $aurora_item['tag'] ); ?></span>
						<?php endif; ?>
						<?php if ( $aurora_item['hot'] ) : ?>
							<span class="aurora-hot__heat"><?php echo esc_html( aurora_hot_format( $aurora_item['hot'] ) ); ?></span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endforeach; ?>

	<div class="aurora-hot__foot">
		<a href="<?php echo esc_url( aurora_hot_page_url() ); ?>"><?php esc_html_e( '查看完整榜单', 'aurora' ); ?><?php aurora_the_icon( 'chevron', 14 ); ?></a>
	</div>
</section>
