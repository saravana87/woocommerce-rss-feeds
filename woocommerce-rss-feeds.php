<?php
/**
 * Plugin Name: WooCommerce RSS Feeds
 * Description: Generate RSS feeds for WooCommerce products
 * Version: 1.0.0
 * Author: Your Name
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-rss-feeds
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define constants
define( 'WC_RSS_FEEDS_VERSION', '1.0.0' );
define( 'WC_RSS_FEEDS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_RSS_FEEDS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_RSS_FEEDS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_action( 'admin_notices', 'wc_rss_feeds_woocommerce_missing_notice' );
    return;
}

/**
 * WooCommerce missing notice
 */
function wc_rss_feeds_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e( 'WooCommerce RSS Feeds requires WooCommerce to be installed and active.', 'wc-rss-feeds' ); ?></p>
    </div>
    <?php
}

/**
 * Main Plugin Class
 */
class WC_RSS_Feeds {

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_wc_rss_scan_products', array( $this, 'ajax_scan_products' ) );
        add_action( 'wp_ajax_wc_rss_generate_feeds', array( $this, 'ajax_generate_feeds' ) );

        // RSS feed endpoints
        add_action( 'init', array( $this, 'add_feed_endpoints' ) );
        add_action( 'do_feed_wc_products', array( $this, 'generate_product_feed' ), 10, 1 );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'WooCommerce RSS Feeds', 'wc-rss-feeds' ),
            __( 'WC RSS Feeds', 'wc-rss-feeds' ),
            'manage_options',
            'wc-rss-feeds',
            array( $this, 'admin_page' ),
            'dashicons-rss',
            56
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( 'toplevel_page_wc-rss-feeds' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'wc-rss-feeds-admin',
            WC_RSS_FEEDS_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WC_RSS_FEEDS_VERSION,
            true
        );

        wp_localize_script( 'wc-rss-feeds-admin', 'wc_rss_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wc_rss_feeds_nonce' ),
        ) );

        wp_enqueue_style(
            'wc-rss-feeds-admin',
            WC_RSS_FEEDS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WC_RSS_FEEDS_VERSION
        );
    }

    /**
     * Admin page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e( 'WooCommerce RSS Feeds', 'wc-rss-feeds' ); ?></h1>

            <div class="wc-rss-feeds-container">
                <div class="wc-rss-feeds-section">
                    <h2><?php _e( 'Product Scan', 'wc-rss-feeds' ); ?></h2>
                    <p><?php _e( 'Scan your WooCommerce products to generate RSS feeds.', 'wc-rss-feeds' ); ?></p>

                    <button id="wc-rss-scan-products" class="button button-primary">
                        <?php _e( 'Scan Products', 'wc-rss-feeds' ); ?>
                    </button>

                    <div id="wc-rss-scan-progress" style="display: none;">
                        <div class="progress-bar">
                            <div class="progress-fill" id="wc-rss-progress-fill"></div>
                        </div>
                        <p id="wc-rss-scan-status"><?php _e( 'Scanning products...', 'wc-rss-feeds' ); ?></p>
                    </div>

                    <div id="wc-rss-scan-results" style="display: none;">
                        <h3><?php _e( 'Scan Results', 'wc-rss-feeds' ); ?></h3>
                        <p id="wc-rss-product-count"></p>
                        <button id="wc-rss-generate-feeds" class="button button-secondary">
                            <?php _e( 'Generate RSS Feeds', 'wc-rss-feeds' ); ?>
                        </button>
                    </div>
                </div>

                <div class="wc-rss-feeds-section">
                    <h2><?php _e( 'RSS Feed URLs', 'wc-rss-feeds' ); ?></h2>
                    <div id="wc-rss-feed-urls">
                        <p><?php _e( 'No feeds generated yet. Please scan products first.', 'wc-rss-feeds' ); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Scan products
     */
    public function ajax_scan_products() {
        check_ajax_referer( 'wc_rss_feeds_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        );

        $products = get_posts( $args );
        $total_products = count( $products );

        // Store product count in transient
        set_transient( 'wc_rss_total_products', $total_products, HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'total_products' => $total_products,
            'message'        => sprintf( __( 'Found %d products', 'wc-rss-feeds' ), $total_products )
        ) );
    }

    /**
     * AJAX: Generate feeds
     */
    public function ajax_generate_feeds() {
        check_ajax_referer( 'wc_rss_feeds_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $total_products = get_transient( 'wc_rss_total_products' );

        if ( false === $total_products ) {
            wp_send_json_error( __( 'Please scan products first', 'wc-rss-feeds' ) );
        }

        // Mark feeds as generated
        update_option( 'wc_rss_feeds_generated', true );
        update_option( 'wc_rss_feeds_timestamp', current_time( 'timestamp' ) );

        wp_send_json_success( array(
            'message' => __( 'RSS feeds generated successfully', 'wc-rss-feeds' ),
            'feed_url' => site_url( '/feed/wc-products/' )
        ) );
    }

    /**
     * Add feed endpoints
     */
    public function add_feed_endpoints() {
        add_feed( 'wc-products', array( $this, 'generate_product_feed' ) );
    }

    /**
     * Generate product feed
     */
    public function generate_product_feed() {
        // Check if feeds are generated
        if ( ! get_option( 'wc_rss_feeds_generated' ) ) {
            wp_die( __( 'RSS feeds not generated yet. Please generate them from the admin panel.', 'wc-rss-feeds' ) );
        }

        header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ), true );

        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => 50, // Limit for performance
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $products = get_posts( $args );

        echo '<?xml version="1.0" encoding="' . get_option( 'blog_charset' ) . '"?>' . "\n";
        ?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wfw="http://wellformedweb.org/CommentAPI/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:atom="http://www.w3.org/2005/Atom"
    xmlns:sy="http://purl.org/rss/1.0/modules/syndication/"
    xmlns:slash="http://purl.org/rss/1.0/modules/slash/"
    <?php do_action( 'rss2_ns' ); ?>>

<channel>
    <title><?php bloginfo_rss( 'name' ); ?> - <?php _e( 'Products', 'wc-rss-feeds' ); ?></title>
    <atom:link href="<?php self_link(); ?>" rel="self" type="application/rss+xml" />
    <link><?php bloginfo_rss( 'url' ) ?></link>
    <description><?php bloginfo_rss( 'description' ) ?></description>
    <lastBuildDate><?php echo mysql2date( 'D, d M Y H:i:s +0000', get_lastpostmodified( 'GMT' ), false ); ?></lastBuildDate>
    <language><?php bloginfo_rss( 'language' ); ?></language>
    <sy:updatePeriod><?php echo apply_filters( 'rss_update_period', 'hourly' ); ?></sy:updatePeriod>
    <sy:updateFrequency><?php echo apply_filters( 'rss_update_frequency', '1' ); ?></sy:updateFrequency>
    <?php do_action( 'rss2_head' ); ?>

    <?php foreach ( $products as $product ) : ?>
        <?php
        $product_obj = wc_get_product( $product->ID );
        if ( ! $product_obj ) continue;
        ?>
    <item>
        <title><?php echo esc_html( $product_obj->get_name() ); ?></title>
        <link><?php echo esc_url( get_permalink( $product->ID ) ); ?></link>
        <pubDate><?php echo mysql2date( 'D, d M Y H:i:s +0000', get_post_time( 'Y-m-d H:i:s', true, $product->ID ), false ); ?></pubDate>
        <dc:creator><![CDATA[<?php echo get_the_author_meta( 'display_name', $product->post_author ); ?>]]></dc:creator>
        <?php if ( $product_obj->get_description() ) : ?>
        <description><![CDATA[<?php echo wp_trim_words( $product_obj->get_description(), 50 ); ?>]]></description>
        <?php endif; ?>
        <content:encoded><![CDATA[
            <div>
                <?php if ( $product_obj->get_image_id() ) : ?>
                <img src="<?php echo esc_url( wp_get_attachment_image_url( $product_obj->get_image_id(), 'medium' ) ); ?>" alt="<?php echo esc_attr( $product_obj->get_name() ); ?>" />
                <?php endif; ?>
                <h2><?php echo esc_html( $product_obj->get_name() ); ?></h2>
                <p><?php echo wp_kses_post( $product_obj->get_description() ); ?></p>
                <p><strong><?php _e( 'Price:', 'wc-rss-feeds' ); ?></strong> <?php echo $product_obj->get_price_html(); ?></p>
                <?php if ( $product_obj->get_stock_status() === 'instock' ) : ?>
                <p><strong><?php _e( 'Availability:', 'wc-rss-feeds' ); ?></strong> <?php _e( 'In Stock', 'wc-rss-feeds' ); ?></p>
                <?php else : ?>
                <p><strong><?php _e( 'Availability:', 'wc-rss-feeds' ); ?></strong> <?php _e( 'Out of Stock', 'wc-rss-feeds' ); ?></p>
                <?php endif; ?>
            </div>
        ]]></content:encoded>
        <guid isPermaLink="false"><?php echo esc_url( get_permalink( $product->ID ) ); ?>#<?php echo $product->ID; ?></guid>
        <?php do_action( 'rss2_item' ); ?>
    </item>
    <?php endforeach; ?>
</channel>
</rss>
        <?php
    }
}

// Initialize the plugin
new WC_RSS_Feeds();
