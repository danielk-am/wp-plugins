<?php
/**
 * Plugin Name: Enhanced Stock Query Monitor
 * Description: Monitors and logs all WooCommerce stock-related database queries to help identify performance issues and unauthorized modifications.
 * Version: 1.0.1
 * Author: Daniel Kam
 * Text Domain: enhanced-stock-monitor
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced Stock Query Monitor Class
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
        if (
            stripos($query, '_postmeta') !== false &&
            (stripos($query, '_stock') !== false || stripos($query, '_backorders') !== false) &&
            stripos(ltrim($query), 'SELECT') !== 0 &&
            stripos($query, '_order_stock_reduced') === false
        ){
            
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
                'source_info' => $this->get_source_info() // Enhanced source tracking
            );
            
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
     * Get enhanced source information with full context
     */
    private function get_source_info() {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
        
        // Skip the first few entries (monitoring function calls)
        foreach (array_slice($backtrace, 4) as $trace) {
            if (isset($trace['file']) && isset($trace['line'])) {
                $file_path = str_replace(ABSPATH, '', $trace['file']);
                
                // Skip database wrapper classes (query-monitor, etc.)
                if (strpos($file_path, 'query-monitor') !== false || 
                    strpos($file_path, 'class-wpdb') !== false ||
                    strpos($file_path, 'meta.php') !== false ||
                    strpos($file_path, 'post.php') !== false) {
                    continue;
                }
                
                // Check if it's a plugin file
                if (strpos($file_path, 'wp-content/plugins/') !== false) {
                    $plugin_name = explode('/', $file_path)[2];
                    return array(
                        'source' => 'Plugin: ' . $plugin_name,
                        'file' => $file_path,
                        'line' => $trace['line'],
                        'function' => $trace['function'] ?? 'Unknown',
                        'class' => $trace['class'] ?? 'Unknown',
                        'full_context' => $this->get_full_context($backtrace)
                    );
                } elseif (strpos($file_path, 'wp-content/themes/') !== false) {
                    $theme_name = explode('/', $file_path)[2];
                    return array(
                        'source' => 'Theme: ' . $theme_name,
                        'file' => $file_path,
                        'line' => $trace['line'],
                        'function' => $trace['function'] ?? 'Unknown',
                        'class' => $trace['class'] ?? 'Unknown',
                        'full_context' => $this->get_full_context($backtrace)
                    );
                } elseif (strpos($file_path, 'wp-includes/') !== false) {
                    // For WordPress core, look deeper to find the actual source
                    continue;
                }
            }
        }
        
        // If we get here, look for the most relevant source in the backtrace
        foreach (array_slice($backtrace, 4) as $trace) {
            if (isset($trace['file']) && isset($trace['line'])) {
                $file_path = str_replace(ABSPATH, '', $trace['file']);
                
                // Look for WooCommerce specific files
                if (strpos($file_path, 'woocommerce/') !== false) {
                    $parts = explode('/', $file_path);
                    $wc_component = isset($parts[2]) ? $parts[2] : 'woocommerce';
                    return array(
                        'source' => 'WooCommerce: ' . $wc_component,
                        'file' => $file_path,
                        'line' => $trace['line'],
                        'function' => $trace['function'] ?? 'Unknown',
                        'class' => $trace['class'] ?? 'Unknown',
                        'full_context' => $this->get_full_context($backtrace)
                    );
                }
                
                // Look for any meaningful source
                if (strpos($file_path, 'wp-content/') !== false) {
                    $parts = explode('/', $file_path);
                    $component = isset($parts[2]) ? $parts[2] : 'unknown';
                    return array(
                        'source' => 'Component: ' . $component,
                        'file' => $file_path,
                        'line' => $trace['line'],
                        'function' => $trace['function'] ?? 'Unknown',
                        'class' => $trace['class'] ?? 'Unknown',
                        'full_context' => $this->get_full_context($backtrace)
                    );
                }
            }
        }
        
        return array(
            'source' => 'Unknown', 
            'file' => 'Unknown', 
            'line' => 'Unknown', 
            'function' => 'Unknown', 
            'class' => 'Unknown',
            'full_context' => $this->get_full_context($backtrace)
        );
    }
    
    /**
     * Get full context information for better debugging
     */
    private function get_full_context($backtrace) {
        $context = array();
        
        // Look for the actual trigger source (skip database layers)
        foreach (array_slice($backtrace, 4) as $trace) {
            if (isset($trace['file']) && isset($trace['line'])) {
                $file_path = str_replace(ABSPATH, '', $trace['file']);
                
                // Skip database wrapper classes
                if (strpos($file_path, 'query-monitor') !== false || 
                    strpos($file_path, 'class-wpdb') !== false ||
                    strpos($file_path, 'meta.php') !== false ||
                    strpos($file_path, 'post.php') !== false) {
                    continue;
                }
                
                // Found the actual source
                if (strpos($file_path, 'wp-content/') !== false) {
                    $context['actual_source'] = array(
                        'file' => $file_path,
                        'line' => $trace['line'],
                        'function' => $trace['function'] ?? 'Unknown',
                        'class' => $trace['class'] ?? 'Unknown'
                    );
                    break;
                }
            }
        }
        
        // Parse and capture full URL context
        $full_uri = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        $context['request'] = array(
            'uri' => $full_uri,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'None',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        );
        
        // Parse query parameters for better context
        if (strpos($full_uri, '?') !== false) {
            $uri_parts = explode('?', $full_uri);
            $context['request']['base_uri'] = $uri_parts[0];
            $context['request']['query_string'] = $uri_parts[1];
            
            // Parse specific query parameters
            parse_str($uri_parts[1], $query_params);
            $context['request']['parsed_params'] = $query_params;
            
            // Extract specific IDs for common admin pages
            if (isset($query_params['page'])) {
                $context['request']['admin_page'] = $query_params['page'];
                
                // Capture snippet ID
                if ($query_params['page'] === 'edit-snippet' && isset($query_params['id'])) {
                    $context['request']['snippet_id'] = $query_params['id'];
                }
                
                // Capture other common IDs
                if (isset($query_params['post'])) {
                    $context['request']['post_id'] = $query_params['post'];
                }
                if (isset($query_params['action'])) {
                    $context['request']['action'] = $query_params['action'];
                }
            }
        }
        
        // Add specific context based on trigger type
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $context['ajax'] = array(
                'action' => $_POST['action'] ?? $_GET['action'] ?? 'Unknown',
                'post_data' => array_keys($_POST ?? array()),
                'get_data' => array_keys($_GET ?? array())
            );
        }
        
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $context['rest'] = array(
                'route' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown'
            );
        }
        
        if (defined('DOING_CRON') && DOING_CRON) {
            $context['cron'] = array(
                'hook' => get_transient('doing_cron') ?: 'Unknown',
                'timestamp' => time()
            );
        }
        
        return $context;
    }
    
    /**
     * WooCommerce notice
     */
    public function woocommerce_notice() {
        if (!$this->is_woocommerce_active()) {
            echo '<div class="notice notice-error"><p>Enhanced Stock Monitor requires WooCommerce to be installed and activated.</p></div>';
        }
    }
}

// Initialize the plugin
new Enhanced_Stock_Monitor();
