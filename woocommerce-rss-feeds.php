<?php
/**
 * Plugin Name: WooCommerce RSS Feeds
 * Description: Generate RSS feeds for WooCommerce products
 * Version: 1.0.0
 * Author: Sara
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
                        <?php
                        $feed_generated = get_option( 'wc_rss_feeds_generated' );
                        $upload_dir = wp_upload_dir();
                        $file_url = $upload_dir['baseurl'] . '/wc-rss-feeds/products-feed.xml';
                        if ( $feed_generated ) {
                            echo '<h3>' . __( 'Your RSS Feed is Ready!', 'wc-rss-feeds' ) . '</h3>';
                            echo '<p><strong>' . __( 'Feed URL:', 'wc-rss-feeds' ) . '</strong></p>';
                            echo '<p><code>' . esc_url( $file_url ) . '</code></p>';
                            echo '<div class="feed-actions">';
                            echo '<a href="' . esc_url( $file_url ) . '" target="_blank" class="button button-primary">' . __( 'View Feed', 'wc-rss-feeds' ) . '</a> ';
                            echo '<a href="' . esc_url( $file_url ) . '" download class="button button-secondary">' . __( 'Download RSS File', 'wc-rss-feeds' ) . '</a>';
                            echo '</div>';
                            echo '<p class="description">' . __( 'Copy this URL to use in RSS readers, or click Download to save the RSS file locally.', 'wc-rss-feeds' ) . '</p>';
                        } else {
                            echo '<p>' . __( 'No feeds generated yet. Please scan products and generate feeds first.', 'wc-rss-feeds' ) . '</p>';
                            echo '<p><strong>' . __( 'Expected Feed URL:', 'wc-rss-feeds' ) . '</strong></p>';
                            echo '<p><code>' . esc_url( $file_url ) . '</code></p>';
                            echo '<p class="description">' . __( 'This will be the URL of your RSS feed after generation.', 'wc-rss-feeds' ) . '</p>';
                        }
                        ?>
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

        // Generate and save RSS file to server
        $result = $this->generate_and_save_rss_file();

        if ( $result['success'] ) {
            // Mark feeds as generated
            update_option( 'wc_rss_feeds_generated', true );
            update_option( 'wc_rss_feeds_timestamp', current_time( 'timestamp' ) );
            update_option( 'wc_rss_file_path', $result['file_path'] );

            wp_send_json_success( array(
                'message' => sprintf( __( 'RSS feeds generated successfully! %d products saved to file.', 'wc-rss-feeds' ), $result['product_count'] ),
                'feed_url' => site_url( '/feed/wc-products/' ),
                'file_path' => $result['file_path']
            ) );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * Generate and save RSS file to server
     */
    private function generate_and_save_rss_file() {
        // Create feeds directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $feeds_dir = $upload_dir['basedir'] . '/wc-rss-feeds';

        if ( ! file_exists( $feeds_dir ) ) {
            wp_mkdir_p( $feeds_dir );
        }

        $file_path = $feeds_dir . '/products-feed.xml';

        // Get all products (no limit for file generation)
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => -1, // Get all products
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $products = get_posts( $args );
        $product_count = count( $products );

        // Start output buffering to capture RSS content
        ob_start();
        $this->generate_rss_content( $products );
        $rss_content = ob_get_clean();

        // Save to file
        if ( file_put_contents( $file_path, $rss_content ) !== false ) {
            return array(
                'success' => true,
                'file_path' => $file_path,
                'product_count' => $product_count,
                'message' => 'RSS file saved successfully'
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Failed to save RSS file to server'
            );
        }
    }

    /**
     * Add feed endpoints
     */
    public function add_feed_endpoints() {
        add_feed( 'wc-products', array( $this, 'generate_product_feed' ) );
    }

    /**
     * Generate RSS content (extracted from generate_product_feed)
     */
    private function generate_rss_content( $products ) {
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
    <lastBuildDate><?php echo mysql2date( 'D, d M Y H:i:s +0530', get_lastpostmodified( 'GMT' ), false ); ?></lastBuildDate>
    <language>ta-en</language>
    <generator>WooCommerce RSS Feeds v<?php echo WC_RSS_FEEDS_VERSION; ?></generator>
    <sy:updatePeriod><?php echo apply_filters( 'rss_update_period', 'hourly' ); ?></sy:updatePeriod>
    <sy:updateFrequency><?php echo apply_filters( 'rss_update_frequency', '1' ); ?></sy:updateFrequency>
    <?php do_action( 'rss2_head' ); ?>

    <?php foreach ( $products as $product ) : ?>
        <?php
        $product_obj = wc_get_product( $product->ID );
        if ( ! $product_obj ) continue;

        // Get both descriptions to include all Tamil content
        $description = '';
        $full_description = $product_obj->get_description();
        $short_description = $product_obj->get_short_description();

        // Include short description first (often contains Tamil summary)
        if ( ! empty( $short_description ) ) {
            $description .= wp_kses_post( $short_description );
        }

        // Add full description if it exists and is different
        if ( ! empty( $full_description ) && $full_description !== $short_description ) {
            if ( ! empty( $description ) ) {
                $description .= ' '; // Add space between descriptions
            }
            $description .= wp_kses_post( $full_description );
        }

        if ( empty( $description ) ) {
            $description = esc_html( $product_obj->get_name() );
        }
        ?>
    <item>
        <title><?php echo esc_html( $product_obj->get_name() ); ?></title>
        <link><?php echo esc_url( get_permalink( $product->ID ) ); ?></link>
        <description><![CDATA[<?php echo wp_kses_post( $description ); ?>]]></description>
        <pubDate><?php echo mysql2date( 'D, d M Y H:i:s +0530', get_post_time( 'Y-m-d H:i:s', true, $product->ID ), false ); ?></pubDate>
        <guid><?php echo esc_url( get_permalink( $product->ID ) ); ?></guid>
        <language>ta-en</language>

        <?php if ( $product_obj->get_sku() ) : ?>
        <sku><?php echo esc_html( $product_obj->get_sku() ); ?></sku>
        <?php endif; ?>

        <?php if ( $product_obj->get_regular_price() ) : ?>
        <price><?php echo esc_html( $product_obj->get_regular_price() ); ?></price>
        <currency><?php echo esc_html( get_woocommerce_currency() ); ?></currency>
        <?php endif; ?>

        <?php if ( $product_obj->get_sale_price() && $product_obj->get_sale_price() < $product_obj->get_regular_price() ) : ?>
        <sale_price><?php echo esc_html( $product_obj->get_sale_price() ); ?></sale_price>
        <?php endif; ?>

        <availability><?php echo $product_obj->get_stock_status() === 'instock' ? 'in_stock' : 'out_of_stock'; ?></availability>

        <?php
        $categories = wp_get_post_terms( $product->ID, 'product_cat', array( 'fields' => 'names' ) );
        if ( ! empty( $categories ) ) :
        ?>
        <category><?php echo esc_html( implode( ', ', $categories ) ); ?></category>
        <?php endif; ?>

        <author><?php echo esc_html( get_the_author_meta( 'display_name', $product->post_author ) ); ?></author>

        <?php
        $keywords = array();
        if ( ! empty( $categories ) ) {
            $keywords = array_merge( $keywords, $categories );
        }
        $tags = wp_get_post_terms( $product->ID, 'product_tag', array( 'fields' => 'names' ) );
        if ( ! empty( $tags ) ) {
            $keywords = array_merge( $keywords, $tags );
        }
        if ( ! empty( $keywords ) ) :
        ?>
        <keywords><?php echo esc_html( implode( ', ', $keywords ) ); ?></keywords>
        <?php endif; ?>

        <content:encoded><![CDATA[
            <div itemscope itemtype="https://schema.org/Product">
                <meta itemprop="name" content="<?php echo esc_attr( $product_obj->get_name() ); ?>" />
                <meta itemprop="description" content="<?php echo esc_attr( $description ); ?>" />
                <link itemprop="url" href="<?php echo esc_url( get_permalink( $product->ID ) ); ?>" />

                <?php if ( $product_obj->get_image_id() ) : ?>
                <img itemprop="image" src="<?php echo esc_url( wp_get_attachment_image_url( $product_obj->get_image_id(), 'medium' ) ); ?>" alt="<?php echo esc_attr( $product_obj->get_name() ); ?>" />
                <?php endif; ?>

                <h2><?php echo esc_html( $product_obj->get_name() ); ?></h2>
                <p><?php echo wp_kses_post( $description ); ?></p>

                <div itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                    <p><strong><?php _e( 'Price:', 'wc-rss-feeds' ); ?></strong>
                    <span itemprop="price" content="<?php echo esc_attr( $product_obj->get_price() ); ?>">
                        <?php echo $product_obj->get_price_html(); ?>
                    </span>
                    <meta itemprop="priceCurrency" content="<?php echo esc_attr( get_woocommerce_currency() ); ?>" />
                    <link itemprop="availability" href="https://schema.org/<?php echo $product_obj->get_stock_status() === 'instock' ? 'InStock' : 'OutOfStock'; ?>" />
                    </p>
                </div>

                <?php if ( $product_obj->get_stock_status() === 'instock' ) : ?>
                <p><strong><?php _e( 'Availability:', 'wc-rss-feeds' ); ?></strong> <?php _e( 'In Stock', 'wc-rss-feeds' ); ?></p>
                <?php else : ?>
                <p><strong><?php _e( 'Availability:', 'wc-rss-feeds' ); ?></strong> <?php _e( 'Out of Stock', 'wc-rss-feeds' ); ?></p>
                <?php endif; ?>

                <?php if ( $product_obj->get_sku() ) : ?>
                <p><strong><?php _e( 'SKU:', 'wc-rss-feeds' ); ?></strong> <?php echo esc_html( $product_obj->get_sku() ); ?></p>
                <?php endif; ?>

                <?php if ( ! empty( $categories ) ) : ?>
                <p><strong><?php _e( 'Categories:', 'wc-rss-feeds' ); ?></strong> <?php echo esc_html( implode( ', ', $categories ) ); ?></p>
                <?php endif; ?>

                <?php if ( $product_obj->get_average_rating() > 0 ) : ?>
                <div itemprop="aggregateRating" itemscope itemtype="https://schema.org/AggregateRating">
                    <p><strong><?php _e( 'Rating:', 'wc-rss-feeds' ); ?></strong>
                    <span itemprop="ratingValue"><?php echo esc_html( $product_obj->get_average_rating() ); ?></span> / 5
                    (<span itemprop="reviewCount"><?php echo esc_html( $product_obj->get_review_count() ); ?></span> reviews)
                    </p>
                </div>
                <?php endif; ?>
            </div>
        ]]></content:encoded>
        <?php do_action( 'rss2_item' ); ?>
    </item>
    <?php endforeach; ?>
</channel>
</rss>
        <?php
    }

    /**
     * Generate product feed
     */
    /**
     * Generate product feed (now serves static file)
     */
    public function generate_product_feed() {
        // Check if feeds are generated
        if ( ! get_option( 'wc_rss_feeds_generated' ) ) {
            wp_die( __( 'RSS feeds not generated yet. Please generate them from the admin panel.', 'wc-rss-feeds' ) );
        }

        $file_path = get_option( 'wc_rss_file_path' );

        // Check if this is a download request
        $is_download = isset( $_GET['download'] ) && $_GET['download'] === '1';

        if ( $is_download ) {
            // Set headers for file download
            header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ), true );
            header( 'Content-Disposition: attachment; filename="woocommerce-products-feed.xml"' );
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
        } else {
            header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ), true );
        }

        // Serve the static RSS file
        if ( file_exists( $file_path ) ) {
            readfile( $file_path );
            exit;
        } else {
            // Fallback: generate dynamically if file doesn't exist
            $args = array(
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'orderby'        => 'date',
                'order'          => 'DESC',
            );

            $products = get_posts( $args );
            $this->generate_rss_content( $products );
        }
    }
}

// Initialize the plugin
new WC_RSS_Feeds();
