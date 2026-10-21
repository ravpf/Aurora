<?php
/**
 * Announcement strip shown above a listing.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

$aurora_notices = aurora_get_notices( 3 );

if ( ! $aurora_notices->have_posts() ) {
	return;
}
?>
<div class="aurora-section" style="margin-bottom:26px">
	<?php
	while ( $aurora_notices->have_posts() ) :
		$aurora_notices->the_post();
		?>
		<div class="aurora-notice" style="margin-bottom:10px">
			<span class="aurora-notice__icon"><?php aurora_the_icon( 'fire', 17 ); ?></span>
			<a href="<?php echo esc_url( get_permalink() ); ?>" style="color:inherit;font-weight:500"><?php the_title(); ?></a>
			<span class="aurora-notice__link" style="color:var(--aurora-text-tertiary);font-weight:400">
				<?php echo esc_html( get_the_date( 'm-d' ) ); ?>
			</span>
		</div>
		<?php
	endwhile;
	wp_reset_postdata();
	?>
</div>
