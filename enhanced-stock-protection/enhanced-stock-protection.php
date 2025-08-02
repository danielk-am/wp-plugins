<?php
/**
 * Plugin Name: Enhanced Stock Protection for WooCommerce
 * Description: Prevents negative stock in admin AND protects against race conditions for regular orders
 * Version: 1.0.0
 * Author: Daniel Kam + Cursor
 * Text Domain: enhanced-stock-protection
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enhanced Stock Protection Class
 */
class WC_Enhanced_Stock_Protection {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'init', array( $this, 'init' ) );
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Admin stock validation
        if ( is_admin() ) {
            add_action( 'woocommerce_before_save_order_items', array( $this, 'validate_admin_order_items_stock' ), 10, 2 );
            add_filter( 'woocommerce_ajax_add_order_item_validation', array( $this, 'validate_ajax_order_item' ), 10, 4 );
            add_action( 'admin_footer', array( $this, 'add_admin_validation_script' ) );
        }

        // Race condition protection for regular orders
        add_filter( 'woocommerce_cart_item_required_stock_is_not_enough', array( $this, 'enhance_cart_stock_validation' ), 10, 3 );
        add_action( 'woocommerce_before_checkout_process', array( $this, 'validate_checkout_stock' ) );
        add_filter( 'woocommerce_order_item_quantity', array( $this, 'validate_order_item_quantity' ), 10, 3 );
        
        // Enhanced stock reservation
        add_action( 'woocommerce_checkout_order_created', array( $this, 'enhance_stock_reservation' ), 5 );
        
        // Stock cleanup
        add_action( 'woocommerce_order_status_changed', array( $this, 'cleanup_expired_reservations' ), 10, 4 );
    }

    /**
     * Enhanced cart stock validation with race condition protection
     */
    public function enhance_cart_stock_validation( $has_insufficient_stock, $product, $values ) {
        if ( ! $product->managing_stock() || $product->backorders_allowed() ) {
            return $has_insufficient_stock;
        }

        // Get current stock including reserved stock
        $current_stock = $product->get_stock_quantity();
        $reserved_stock = $this->get_reserved_stock_for_product( $product->get_id() );
        $available_stock = $current_stock - $reserved_stock;
        
        // Get quantity needed for this cart item
        $required_quantity = $values['quantity'];
        
        // Check if we have enough available stock
        if ( $available_stock < $required_quantity ) {
            return true; // Insufficient stock
        }

        return false; // Sufficient stock
    }

    /**
     * Validate stock before checkout process
     */
    public function validate_checkout_stock() {
        if ( ! WC()->cart ) {
            return;
        }

        $errors = array();
        
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];
            
            if ( ! $product->managing_stock() || $product->backorders_allowed() ) {
                continue;
            }

            $current_stock = $product->get_stock_quantity();
            $reserved_stock = $this->get_reserved_stock_for_product( $product->get_id() );
            $available_stock = $current_stock - $reserved_stock;
            $required_quantity = $cart_item['quantity'];

            if ( $available_stock < $required_quantity ) {
                $errors[] = sprintf(
                    __( 'Sorry, "%s" is not available in the requested quantity. Only %d available.', 'enhanced-stock-protection' ),
                    $product->get_name(),
                    $available_stock
                );
            }
        }

        if ( ! empty( $errors ) ) {
            foreach ( $errors as $error ) {
                wc_add_notice( $error, 'error' );
            }
        }
    }

    /**
     * Validate order item quantity with race condition protection
     */
    public function validate_order_item_quantity( $quantity, $order, $item ) {
        if ( ! $item->is_type( 'line_item' ) ) {
            return $quantity;
        }

        $product = $item->get_product();
        if ( ! $product || ! $product->managing_stock() || $product->backorders_allowed() ) {
            return $quantity;
        }

        // For new orders, check available stock
        if ( $order->get_status() === 'pending' || $order->get_status() === 'checkout-draft' ) {
            $current_stock = $product->get_stock_quantity();
            $reserved_stock = $this->get_reserved_stock_for_product( $product->get_id(), $order->get_id() );
            $available_stock = $current_stock - $reserved_stock;

            if ( $quantity > $available_stock ) {
                // Log the race condition
                error_log( sprintf( 
                    'Stock race condition detected: Product %s (ID: %d) - Requested: %d, Available: %d, Order: %d',
                    $product->get_name(),
                    $product->get_id(),
                    $quantity,
                    $available_stock,
                    $order->get_id()
                ) );

                // Return available quantity instead of throwing error
                return $available_stock;
            }
        }

        return $quantity;
    }

    /**
     * Enhance stock reservation with additional validation
     */
    public function enhance_stock_reservation( $order ) {
        // Double-check stock availability before reservation
        foreach ( $order->get_items() as $item ) {
            if ( ! $item->is_type( 'line_item' ) ) {
                continue;
            }

            $product = $item->get_product();
            if ( ! $product || ! $product->managing_stock() || $product->backorders_allowed() ) {
                continue;
            }

            $current_stock = $product->get_stock_quantity();
            $reserved_stock = $this->get_reserved_stock_for_product( $product->get_id(), $order->get_id() );
            $available_stock = $current_stock - $reserved_stock;
            $required_quantity = $item->get_quantity();

            if ( $available_stock < $required_quantity ) {
                // Add order note about stock issue
                $order->add_order_note( 
                    sprintf( 
                        __( 'Stock validation failed: %s - Requested: %d, Available: %d', 'enhanced-stock-protection' ),
                        $product->get_name(),
                        $required_quantity,
                        $available_stock
                    )
                );
            }
        }
    }

    /**
     * Clean up expired reservations when order status changes
     */
    public function cleanup_expired_reservations( $order_id, $old_status, $new_status, $order ) {
        // Release stock reservations for completed/cancelled orders
        if ( in_array( $new_status, array( 'completed', 'cancelled', 'refunded' ) ) ) {
            $this->release_stock_reservations( $order );
        }
    }

    /**
     * Get reserved stock for a product
     */
    private function get_reserved_stock_for_product( $product_id, $exclude_order_id = 0 ) {
        global $wpdb;

        $query = $wpdb->prepare(
            "
            SELECT COALESCE( SUM( stock_table.`stock_quantity` ), 0 ) 
            FROM {$wpdb->wc_reserved_stock} stock_table
            LEFT JOIN {$wpdb->posts} posts ON stock_table.`order_id` = posts.ID
            WHERE posts.post_status IN ( 'wc-checkout-draft', 'wc-pending' )
            AND stock_table.`expires` > NOW()
            AND stock_table.`product_id` = %d
            AND stock_table.`order_id` != %d
            ",
            $product_id,
            $exclude_order_id
        );

        return (int) $wpdb->get_var( $query );
    }

    /**
     * Release stock reservations for an order
     */
    private function release_stock_reservations( $order ) {
        global $wpdb;

        $wpdb->delete(
            $wpdb->wc_reserved_stock,
            array( 'order_id' => $order->get_id() ),
            array( '%d' )
        );
    }

    /**
     * Admin validation methods (from original plugin)
     */
    public function validate_admin_order_items_stock( $order_id, $items ) {
        if ( ! isset( $items['order_item_id'] ) || ! isset( $items['order_item_qty'] ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $errors = array();

        foreach ( $items['order_item_id'] as $item_id ) {
            if ( ! isset( $items['order_item_qty'][ $item_id ] ) ) {
                continue;
            }

            $item = WC_Order_Factory::get_order_item( absint( $item_id ) );
            if ( ! $item || 'line_item' !== $item->get_type() ) {
                continue;
            }

            $product = $item->get_product();
            if ( ! $product || ! $product->managing_stock() || $product->backorders_allowed() ) {
                continue;
            }

            $new_quantity = wc_stock_amount( $items['order_item_qty'][ $item_id ] );
            $old_quantity = wc_stock_amount( $item->get_quantity() );
            $current_stock = $product->get_stock_quantity();
            $reserved_stock = $this->get_reserved_stock_for_product( $product->get_id(), $order_id );
            $available_stock = $current_stock - $reserved_stock;
            
            $quantity_diff = $new_quantity - $old_quantity;
            
            if ( $quantity_diff > 0 && $available_stock < $quantity_diff ) {
                $errors[] = sprintf(
                    __( 'Cannot add %d units of "%s" - only %d available in stock (excluding %d reserved).', 'enhanced-stock-protection' ),
                    $quantity_diff,
                    $product->get_name(),
                    $available_stock,
                    $reserved_stock
                );
            }
        }

        if ( ! empty( $errors ) ) {
            wp_die( 
                '<div class="notice notice-error"><p>' . implode( '<br>', $errors ) . '</p></div>',
                __( 'Stock Validation Error', 'enhanced-stock-protection' ),
                array( 'back_link' => true )
            );
        }
    }

    public function validate_ajax_order_item( $validation_error, $product, $order, $qty ) {
        if ( ! $product->managing_stock() || $product->backorders_allowed() ) {
            return $validation_error;
        }

        $current_stock = $product->get_stock_quantity();
        $reserved_stock = $this->get_reserved_stock_for_product( $product->get_id(), $order->get_id() );
        $available_stock = $current_stock - $reserved_stock;
        
        if ( $available_stock < $qty ) {
            $validation_error->add(
                'insufficient_stock',
                sprintf(
                    __( 'Cannot add %d units of "%s" - only %d available in stock (excluding %d reserved).', 'enhanced-stock-protection' ),
                    $qty,
                    $product->get_name(),
                    $available_stock,
                    $reserved_stock
                )
            );
        }

        return $validation_error;
    }

    public function add_admin_validation_script() {
        $screen = get_current_screen();
        if ( ! $screen || 'post' !== $screen->base || 'shop_order' !== $screen->post_type ) {
            return;
        }

        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var originalQuantities = {};
            
            $('input.quantity').each(function() {
                var $input = $(this);
                var itemId = $input.closest('tr').data('order_item_id');
                originalQuantities[itemId] = parseInt($input.attr('data-qty')) || 0;
            });

            $('input.quantity').on('change', function() {
                var $input = $(this);
                var $row = $input.closest('tr.item');
                var itemId = $row.data('order_item_id');
                var newQty = parseInt($input.val()) || 0;
                var originalQty = originalQuantities[itemId] || 0;
                var $productName = $row.find('.item-name').text().trim();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'check_enhanced_product_stock',
                        product_id: $row.data('product_id'),
                        order_id: '<?php echo get_the_ID(); ?>',
                        nonce: '<?php echo wp_create_nonce( 'check_enhanced_stock_nonce' ); ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data.managing_stock && !response.data.backorders_allowed) {
                            var currentStock = response.data.stock_quantity;
                            var reservedStock = response.data.reserved_stock;
                            var availableStock = currentStock - reservedStock;
                            var quantityDiff = newQty - originalQty;
                            
                            if (quantityDiff > 0 && availableStock < quantityDiff) {
                                alert('Cannot add ' + quantityDiff + ' units of "' + $productName + '" - only ' + availableStock + ' available in stock (excluding ' + reservedStock + ' reserved).');
                                $input.val(originalQty);
                                return false;
                            }
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }
}

/**
 * AJAX handler for enhanced stock checking
 */
function wc_check_enhanced_product_stock_ajax() {
    check_ajax_referer( 'check_enhanced_stock_nonce', 'nonce' );
    
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    $product_id = intval( $_POST['product_id'] );
    $order_id = intval( $_POST['order_id'] );
    $product = wc_get_product( $product_id );
    
    if ( ! $product ) {
        wp_send_json_error( 'Product not found' );
    }

    // Get reserved stock
    global $wpdb;
    $reserved_stock = $wpdb->get_var( $wpdb->prepare(
        "
        SELECT COALESCE( SUM( stock_table.`stock_quantity` ), 0 ) 
        FROM {$wpdb->wc_reserved_stock} stock_table
        LEFT JOIN {$wpdb->posts} posts ON stock_table.`order_id` = posts.ID
        WHERE posts.post_status IN ( 'wc-checkout-draft', 'wc-pending' )
        AND stock_table.`expires` > NOW()
        AND stock_table.`product_id` = %d
        AND stock_table.`order_id` != %d
        ",
        $product_id,
        $order_id
    ) );

    wp_send_json_success( array(
        'managing_stock' => $product->managing_stock(),
        'backorders_allowed' => $product->backorders_allowed(),
        'stock_quantity' => $product->get_stock_quantity(),
        'reserved_stock' => (int) $reserved_stock,
        'stock_status' => $product->get_stock_status()
    ) );
}
add_action( 'wp_ajax_check_enhanced_product_stock', 'wc_check_enhanced_product_stock_ajax' );

// Initialize the plugin
new WC_Enhanced_Stock_Protection(); 
