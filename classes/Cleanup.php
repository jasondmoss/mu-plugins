<?php

/**
 * WordPress Clean-Up.
 *
 * @category   CleanUp
 * @package    WordPress
 * @subpackage MustUsePlugin
 * @author     Jason D. Moss <jason@jadmlabs.com>
 * @copyright  2016 Jason D. Moss. All Rights Reserved.
 * @license    https://www.jdmlabs.com/license/license-MIT-1.1.txt [MIT License] [LICENSE]
 * @link       https://www.jdmlabs.com/
 */


namespace MU\Classes;

class Cleanup
{

    /**
     * Constructor.
     *
     * @access public
     */
    public function __construct()
    {
        /* Remove unnecessary <link>'s. */
        add_action('wp_head', 'ob_start', 1, 0);
        add_action('wp_head', function () {
            ob_start();
            $pattern = '/.*'. preg_quote(esc_url(get_feed_link('comments_'. get_default_feed())), '/') .'.*[\r\n]+/';
            echo preg_replace($pattern, '', ob_get_clean());
        }, 3, 0);
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'feed_links_extra', 3);
        remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
        remove_action('wp_head', 'wp_shortlink_wp_head', 10);

        /* Remove inline CSS and JS for WP emoji support. */
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

        /* Remove inline CSS used by posts with galleries. */
        add_filter('use_default_gallery_style', '__return_false');

        /* Remove inline CSS used by Recent Comments widget. */
        global $wp_widget_factory;
        if (isset($wp_widget_factory->widgets['WP_Widget_Recent_Comments'])) {
            remove_action('wp_head', [
                $wp_widget_factory->widgets['WP_Widget_Recent_Comments'],
                'recent_comments_style'
            ]);
        }

        /* Remove self-closing tag and change ''s to "'s on rel_canonical(). */
        if (!function_exists('aioseop_init_class') || !class_exists('WPSEO_Frontend')) {
            remove_action('wp_head', 'rel_canonical');
            add_action('wp_head', [$this, 'relCanonical']);
        }

        /*  */
        remove_filter('the_excerpt', 'wpautop');

        /* Remove the WordPress version from RSS feeds. */
        add_filter('the_generator', '__return_false');

        /*  */
        add_filter('style_loader_tag', [$this, 'cleanStyleTag']);
        add_filter('script_loader_tag', [$this, 'cleanScriptTag']);

        /*  */
        add_filter('embed_oembed_html', [$this, 'embedWrap']);

        /*  */
        add_filter('get_bloginfo_rss', [$this, 'removeDefaultDescription']);

        /* Remove WP version info appended to styles and scripts. */
        foreach ([
            'script_loader_src',
            'style_loader_src'
        ] as $filter) {
            add_filter($filter, [$this, 'removeWordPressVersionStrings']);
        }

        /* HTML5-ize all content output. */
        foreach ([
            'the_excerpt',
            'the_content',
            'post_thumbnail_html',
            'get_avatar',
            'comment_text',
            'comment_id_fields'
        ] as $filter) {
            add_filter($filter, [$this, 'removeSelfClosingTags'], 10);
        }
    }


    /* ---------------------------------------------------------------------- */


    /**
     * ...
     *
     * @access public
     * @final
     */
    final public function relCanonical()
    {
        global $wp_the_query;

        if (!is_singular() || !$queriedId = $wp_the_query->get_queried_object_id()) {
            return;
        }

        $link = get_permalink($queriedId);
        echo "\n<link rel=\"canonical\" href=\"$link\">\n";
    }


    /**
     * Clean up output of stylesheet <link> tags.
     *
     * @return string
     * @access public
     * @final
     */
    final public function cleanStyleTag($input)
    {
        preg_match_all(
            "!<link rel='stylesheet'\s?(id='[^']+')?\s+href='(.*)' type='text/css' media='(.*)' />!",
            $input,
            $matches
        );

        if (isset($matches[2][0]) && isset($matches[3][0])) {
            /**
             * Only display media if it is actually meaningful.
             */
            $media = ('' !== $matches[3][0]/* && 'all' !== $matches[3][0]*/) ? " media=\"{$matches[3][0]}\"" : '';

            return "<link rel=\"stylesheet\" href=\"{$matches[2][0]}\"{$media}>\n";
        }

        return preg_replace('/\s+\/>/', '>', $input);
    }


    /**
     * Clean up output of script tags.
     *
     * @param string $input
     *
     * @return string
     * @access public
     * @final
     */
    final public function cleanScriptTag($input)
    {
        $input = str_replace(" type='text/javascript'", '', $input);
        $input = str_replace(' type="text/javascript"', '', $input);
        $input = str_replace("'", '"', $input);

        return $input;
    }


    /**
     * Hide WP version strings from scripts and styles.
     *
     * @param string $src
     *
     * @return string
     * @access public
     * @final
     */
    final public function removeWordPressVersionStrings($src)
    {
        parse_str(parse_url($src, PHP_URL_QUERY), $query);
        if (!empty($query['ver'])) {
            $src = remove_query_arg('ver', $src);
        }

        return $src;
    }


    /**
     * Remove unnecessary self-closing tags
     *
     * @param string $input
     *
     * @return mixed
     * @access public
     * @final
     */
    final public function removeSelfClosingTags($input)
    {
        return preg_replace('/\s+\/>/', '>', $input);
    }


    /**
     * Wrap embedded media as suggested by Readability.
     *
     * @param string $cache
     *
     * @return string
     * @access public
     * @final
     *
     * @link https://gist.github.com/965956
     * @link https://www.readability.com/publishers/guidelines#publisher
     */
    final public function embedWrap($cache)
    {
        return "<div class=\"entry-content-asset\">{$cache}</div>";
    }


    /**
     * Don't return the default description in the RSS feed if it hasn't been
     * changed.
     *
     * @param array $bloginfo
     *
     * @return array
     * @access public
     * @final
     */
    final public function removeDefaultDescription($bloginfo)
    {
        $defaultTagline = 'Just another WordPress site';

        return ($bloginfo === $defaultTagline) ? '' : $bloginfo;
    }
}

/* <> */