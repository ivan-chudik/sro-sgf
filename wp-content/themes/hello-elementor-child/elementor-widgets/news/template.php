<?php
// $settings dostupný z render() scope
$args = [
    'post_type'           => 'post',
    'post_status'         => 'publish',
    'posts_per_page'      => max( 1, (int) ( $settings['posts_count'] ?? 9 ) ),
    'ignore_sticky_posts' => true,
    'no_found_rows'       => true,
];
$cats = array_filter( array_map( 'intval', (array) ( $settings['categories'] ?? [] ) ) );
if ( $cats ) $args['category__in'] = $cats;
if ( function_exists( 'pll_get_post_language' ) ) $args['lang'] = xn_lang();

$news_query = new WP_Query( $args );
$is_editor  = \Elementor\Plugin::$instance->editor->is_edit_mode();

if ( ! $news_query->have_posts() && xn_on( $settings, 'empty_hide' ) && ! $is_editor ) return;

$words  = max( 5, (int) ( $settings['excerpt_words'] ?? 22 ) );
$arrows = xn_on( $settings, 'arrows_show' );
$arrow  = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'novinky' ); ?>" class="custom-news xn-block xn-section xn-light" data-widget="news">
    <div class="xn-wrap">
        <div class="news__head">
            <div class="news__intro"><?php $this->xn_render_intro( $settings ); ?></div>
            <?php if ( $arrows && $news_query->have_posts() ) : ?>
                <div class="news__nav">
                    <button type="button" class="news__arrow news__arrow--prev" aria-label="<?php echo esc_attr( $settings['prev_label'] ?? '' ); ?>" disabled><?php echo $arrow; ?></button>
                    <button type="button" class="news__arrow news__arrow--next" aria-label="<?php echo esc_attr( $settings['next_label'] ?? '' ); ?>"><?php echo $arrow; ?></button>
                </div>
            <?php endif; ?>
        </div>

        <?php if ( $news_query->have_posts() ) : ?>
            <div class="news__track" tabindex="-1">
                <?php while ( $news_query->have_posts() ) : $news_query->the_post();
                    $pid   = get_the_ID();
                    $url   = xn_post_link( $pid );
                    $cat   = get_the_category( $pid );
                    $thumb = get_the_post_thumbnail( $pid, 'medium_large', [ 'class' => 'news__img', 'loading' => 'lazy', 'alt' => '' ] );
                    $text  = has_excerpt( $pid ) ? get_the_excerpt( $pid ) : wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', $pid ) ) ); ?>
                    <article class="news__card">
                        <a class="news__link"<?php echo xn_link_attrs( $url ); ?>>
                            <?php if ( xn_on( $settings, 'image_show' ) ) : ?>
                                <div class="news__media<?php echo $thumb ? '' : ' news__media--empty'; ?>">
                                    <?php echo $thumb; ?>
                                    <?php if ( xn_on( $settings, 'cat_show' ) && $cat ) : ?>
                                        <span class="news__cat"><?php echo esc_html( $cat[0]->name ); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="news__body">
                                <?php if ( xn_on( $settings, 'date_show' ) ) : ?>
                                    <time class="news__date" datetime="<?php echo esc_attr( get_the_date( 'c', $pid ) ); ?>"><?php echo esc_html( get_the_date( ( $settings['date_format'] ?? '' ) ?: 'j. n. Y', $pid ) ); ?></time>
                                <?php endif; ?>
                                <h3 class="news__title"><?php echo esc_html( get_the_title( $pid ) ); ?></h3>
                                <?php if ( xn_on( $settings, 'excerpt_show' ) && trim( $text ) !== '' ) : ?>
                                    <p class="news__excerpt"><?php echo esc_html( wp_trim_words( $text, $words, '…' ) ); ?></p>
                                <?php endif; ?>
                                <?php if ( xn_on( $settings, 'more_show' ) && xn_filled( $settings, 'more_text' ) ) : ?>
                                    <span class="news__more"><?php echo esc_html( $settings['more_text'] ); ?><?php echo $arrow; ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    </article>
                <?php endwhile; wp_reset_postdata(); ?>
            </div>
        <?php elseif ( $is_editor ) : ?>
            <div class="news__empty">Zatiaľ tu nie sú žiadne publikované články (v tomto jazyku / kategórii). Na webe sa sekcia skryje.</div>
        <?php endif; ?>

        <?php if ( xn_on( $settings, 'btn1_show' ) ) : ?>
            <div class="xn-cta-row news__cta"><?php $this->xn_render_button( $settings, 'btn1' ); ?></div>
        <?php endif; ?>
    </div>
</section>
