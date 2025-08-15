<?php
/**
 * Plugin Name: Enhanced Stock Query Monitor
 * Description: Monitors and logs all WooCommerce stock-related database queries to help identify performance issues and unauthorized modifications.
 * Version: 1.0.1
 * Author: Daniel Kam + Cursor
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: enhanced-stock-monitor
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced Stock Query Monitor Class
 * 
 * Monitors and logs all database queries related to WooCommerce stock management
 * to help identify potential performance issues, unauthorized stock modifications, or
 * problematic plugins that may be causing excessive database load.
 */
class Enhanced_Stock_Monitor {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Only initialize if WooCommerce is active
        if ($this->is_woocommerce_active()) {
            $this->init();
        }
    }
    
    /**
     * Initialize the plugin
     */
    private function init() {
        // Add the query filter
        add_filter('query', array($this, 'monitor_stock_queries'));
        
        // Add admin notice if WooCommerce is not active
        add_action('admin_notices', array($this, 'woocommerce_notice'));
        
        // Add settings page
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        
        // Add activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Check if WooCommerce is active
     */
    private function is_woocommerce_active() {
        return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
    }
    
    /**
     * Monitor stock-related queries
     */
    public function monitor_stock_queries($query) {
        // Check if monitoring is enabled
        if (!$this->is_monitoring_enabled()) {
            return $query;
        }
        
        if (
            stripos($query, '_postmeta') !== false &&
            (stripos($query, '_stock') !== false OR stripos($query, '_backorders') !== false) &&
            stripos(ltrim($query), 'SELECT') !== 0 &&
            stripos($query, '_order_stock_reduced') === false
        ){
            
            // Get detailed backtrace with file paths and line numbers
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
            
            // Build detailed debug info
            $debug_info = array(
                'query' => $query,
                'timestamp' => current_time('mysql'),
                'user_id' => get_current_user_id(),
                'username' => wp_get_current_user()->user_login ?? 'Unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
                'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                'backtrace' => array()
            );
            
            // Process backtrace to find relevant info
            foreach ($backtrace as $index => $trace) {
                if (isset($trace['file']) && isset($trace['line'])) {
                    $file_path = str_replace(ABSPATH, '', $trace['file']);
                    
                    // Check if it's a plugin file
                    if (strpos($file_path, 'wp-content/plugins/') !== false) {
                        $plugin_name = explode('/', $file_path)[2]; // Get plugin folder name
                        $debug_info['backtrace'][] = array(
                            'index' => $index,
                            'file' => $file_path,
                            'line' => $trace['line'],
                            'function' => $trace['function'] ?? 'Unknown',
                            'class' => $trace['class'] ?? 'Unknown',
                            'plugin' => $plugin_name,
                            'type' => $trace['type'] ?? 'Unknown'
                        );
                    } else {
                        $debug_info['backtrace'][] = array(
                            'index' => $index,
                            'file' => $file_path,
                            'line' => $trace['line'],
                            'function' => $trace['function'] ?? 'Unknown',
                            'class' => $trace['class'] ?? 'Unknown',
                            'plugin' => 'WordPress Core',
                            'type' => $trace['type'] ?? 'Unknown'
                        );
                    }
                }
            }
            
            // Check if it's a cron job
            if (defined('DOING_CRON') && DOING_CRON) {
                $debug_info['trigger'] = 'WordPress Cron';
                $debug_info['cron_hook'] = get_transient('doing_cron') ?: 'Unknown';
            }
            
            // Enhanced REST API logging
            if (defined('REST_REQUEST') && REST_REQUEST) {
                $debug_info['trigger'] = 'REST API';
                $debug_info['rest_route'] = $_SERVER['REQUEST_URI'] ?? 'Unknown';
                
                // Get REST API authentication details (without sensitive keys)
                $debug_info['rest_auth'] = array(
                    'user_id' => get_current_user_id(),
                    'username' => wp_get_current_user()->user_login ?? 'Unknown',
                    'auth_method' => $_SERVER['HTTP_AUTHORIZATION'] ? 'OAuth/Basic' : 'None'
                );
                
                // Get the actual REST API endpoint details
                $rest_server = rest_get_server();
                if ($rest_server) {
                    $debug_info['rest_endpoint'] = array(
                        'route' => $rest_server->get_raw_data() ?: 'Unknown',
                        'method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
                        'namespace' => 'wc/v3', // WooCommerce REST API namespace
                        'resource' => 'products' // Based on the URI pattern
                    );
                }
                
                // Check if it's coming from a specific plugin or external source
                $referer = $_SERVER['HTTP_REFERER'] ?? 'None';
                $debug_info['referer'] = $referer;
                
                // Check for common external system headers
                $debug_info['external_indicators'] = array(
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'None',
                    'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'None',
                    'x_real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? 'None',
                    'x_forwarded_host' => $_SERVER['HTTP_X_FORWARDED_HOST'] ?? 'None',
                    'x_forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'None'
                );
                
                // Check for WooCommerce specific headers (without sensitive data)
                $debug_info['wc_headers'] = array(
                    'webhook_source' => $_SERVER['HTTP_X_WC_WEBHOOK_SOURCE'] ? 'Present' : 'None',
                    'webhook_topic' => $_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? 'None',
                    'webhook_signature' => $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ? 'Present' : 'None'
                );
            }
            
            // Check if it's an AJAX call
            if (defined('DOING_AJAX') && DOING_AJAX) {
                $debug_info['trigger'] = 'AJAX';
                $debug_info['ajax_action'] = $_POST['action'] ?? $_GET['action'] ?? 'Unknown';
            }
            
            // Check if it's a CLI command
            if (defined('WP_CLI') && WP_CLI) {
                $debug_info['trigger'] = 'WP-CLI';
                $debug_info['cli_command'] = implode(' ', $_SERVER['argv'] ?? array());
            }
            
            // Check if it's a webhook
            if (defined('REST_REQUEST') && REST_REQUEST && 
                (strpos($_SERVER['REQUEST_URI'], 'webhooks') !== false || 
                 isset($_SERVER['HTTP_X_WC_WEBHOOK_SOURCE']))) {
                $debug_info['trigger'] = 'WooCommerce Webhook';
                $debug_info['webhook_details'] = array(
                    'source' => $_SERVER['HTTP_X_WC_WEBHOOK_SOURCE'] ?? 'Unknown',
                    'topic' => $_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? 'Unknown',
                    'signature' => $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ? 'Present' : 'None'
                );
            }
            
            // Log the enhanced debug info
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->warning(
                    'Enhanced Stock Monitor: ' . wc_print_r($debug_info, true), 
                    array('source' => 'enhanced-stock-monitor')
                );
            }
            
            // Also log to WordPress debug log if enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Enhanced Stock Monitor: ' . print_r($debug_info, true));
            }
        }

        return $query;
    }
    
    /**
     * Check if monitoring is enabled
     */
    private function is_monitoring_enabled() {
        return get_option('esm_enable_monitoring', true);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Stock Monitor',
            'Stock Monitor',
            'manage_woocommerce',
            'enhanced-stock-monitor',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting('esm_options', 'esm_enable_monitoring');
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Enhanced Stock Monitor Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('esm_options');
                do_settings_sections('esm_options');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Monitoring</th>
                        <td>
                            <label>
                                <input type="checkbox" name="esm_enable_monitoring" value="1" 
                                       <?php checked(1, get_option('esm_enable_monitoring', true)); ?> />
                                Monitor stock-related queries
                            </label>
                            <p class="description">
                                When enabled, this plugin will log all stock-related database queries to help identify performance issues.
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <h2>Recent Logs</h2>
            <p>Check the WooCommerce logs for recent stock monitoring entries. Look for entries with source "enhanced-stock-monitor".</p>
            <p><a href="<?php echo admin_url('admin.php?page=wc-status&tab=logs'); ?>" class="button">View WooCommerce Logs</a></p>
        </div>
        <?php
    }
    
    /**
     * WooCommerce notice
     */
    public function woocommerce_notice() {
        if (!$this->is_woocommerce_active()) {
            echo '<div class="notice notice-error"><p>Enhanced Stock Monitor requires WooCommerce to be installed and activated.</p></div>';
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        add_option('esm_enable_monitoring', true);
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed
        // Note: We keep the options in case the plugin is reactivated
    }
}

// Initialize the plugin
new Enhanced_Stock_Monitor();
