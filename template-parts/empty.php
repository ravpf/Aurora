<?php
/**
 * Empty-state block.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="aurora-empty">
	<h3><?php esc_html_e( '这里还是空的', 'aurora' ); ?></h3>
	<p><?php esc_html_e( '还没有发布内容。发布第一篇文章，或者用搜索找找看。', 'aurora' ); ?></p>
	<div style="display:flex;gap:10px;justify-content:center;margin-top:18px">
		<a class="aurora-btn aurora-btn--primary" href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>"><?php esc_html_e( '写第一篇文章', 'aurora' ); ?></a>
		<a class="aurora-btn aurora-btn--ghost" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( '返回首页', 'aurora' ); ?></a>
	</div>
</div>
