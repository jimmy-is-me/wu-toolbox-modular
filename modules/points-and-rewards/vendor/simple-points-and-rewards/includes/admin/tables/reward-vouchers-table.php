<?php

/**
 * Reward Vouchers List Table
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
class SPAR_Reward_Vouchers_List_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct( [
            'singular' => 'reward_voucher',
            'plural'   => 'reward_vouchers',
            'ajax'     => false,
        ] );
    }

    public function prepare_items() {
        global $wpdb;
        $per_page = 10;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        // Read-only search param from URL; sanitized for safe use in LIKE. Nonce not required for a read-only filter.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search_term = ( isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' );
        // Allowed post statuses are a static safe list embedded directly to avoid dynamic placeholder counts.
        // Filter by meta flag instead of fragile title prefix.
        $cache_group = 'spar';
        // Build COUNT(*) query using EXISTS on postmeta for _spar_reward_voucher = '1'.
        $count_args = ['shop_coupon', '_spar_reward_voucher', '1'];
        if ( $search_term ) {
            $count_args[] = '%' . $wpdb->esc_like( $search_term ) . '%';
        }
        $count_key = 'spar_reward_vouchers_count_' . md5( wp_json_encode( array('rv_count_meta', $count_args) ) );
        $total_items = wp_cache_get( $count_key, $cache_group );
        if ( false === $total_items ) {
            $count_args = array(
                'post_type'      => 'shop_coupon',
                'post_status'    => array(
                    'publish',
                    'draft',
                    'private',
                    'pending',
                    'future'
                ),
                'meta_query'     => array(array(
                    'key'     => '_spar_reward_voucher',
                    'value'   => '1',
                    'compare' => '=',
                )),
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'no_found_rows'  => false,
            );
            if ( $search_term ) {
                $count_args['s'] = $search_term;
            }
            $count_query = new WP_Query($count_args);
            $total_items = $count_query->found_posts;
            wp_reset_postdata();
            wp_cache_set(
                $count_key,
                $total_items,
                $cache_group,
                60
            );
        }
        // Build data query with LIMIT/OFFSET using meta filter.
        $data_args = ['shop_coupon', '_spar_reward_voucher', '1'];
        if ( $search_term ) {
            $data_args[] = '%' . $wpdb->esc_like( $search_term ) . '%';
        }
        $data_args[] = $per_page;
        $data_args[] = $offset;
        $results_key = 'spar_reward_vouchers_results_' . md5( wp_json_encode( array('rv_results_meta', $data_args) ) );
        $results = wp_cache_get( $results_key, $cache_group );
        if ( false === $results ) {
            $query_args = array(
                'post_type'      => 'shop_coupon',
                'post_status'    => array(
                    'publish',
                    'draft',
                    'private',
                    'pending',
                    'future'
                ),
                'meta_query'     => array(array(
                    'key'     => '_spar_reward_voucher',
                    'value'   => '1',
                    'compare' => '=',
                )),
                'orderby'        => 'date',
                'order'          => 'DESC',
                'posts_per_page' => $per_page,
                'offset'         => $offset,
            );
            if ( $search_term ) {
                $query_args['s'] = $search_term;
            }
            $query = new WP_Query($query_args);
            $results = array();
            if ( $query->have_posts() ) {
                while ( $query->have_posts() ) {
                    $query->the_post();
                    $post = get_post();
                    $results[] = (object) array(
                        'ID'          => $post->ID,
                        'post_title'  => $post->post_title,
                        'post_date'   => $post->post_date,
                        'post_status' => $post->post_status,
                    );
                }
            }
            wp_reset_postdata();
            wp_cache_set(
                $results_key,
                $results,
                $cache_group,
                60
            );
        }
        $this->items = array_map( function ( $post ) {
            return (object) [
                'ID'             => $post->ID,
                'title'          => $post->post_title,
                'date'           => $post->post_date,
                'status'         => $post->post_status,
                'discount_type'  => get_post_meta( $post->ID, 'discount_type', true ),
                'product_ids'    => get_post_meta( $post->ID, 'product_ids', true ),
                'amount'         => get_post_meta( $post->ID, 'coupon_amount', true ),
                'usage_limit'    => get_post_meta( $post->ID, 'usage_limit', true ),
                'usage_count'    => get_post_meta( $post->ID, 'usage_count', true ),
                'expiry'         => get_post_meta( $post->ID, 'date_expires', true ),
                'email_restrict' => get_post_meta( $post->ID, 'customer_email', true ),
            ];
        }, $results );
        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ] );
    }

    public function get_columns() {
        return [
            'cb'             => '<input type="checkbox" />',
            'title'          => esc_html__( 'Title', 'simple-points-and-rewards' ),
            'discount_type'  => esc_html__( 'Type', 'simple-points-and-rewards' ),
            'amount'         => esc_html__( 'Amount', 'simple-points-and-rewards' ),
            'product'        => esc_html__( 'Product', 'simple-points-and-rewards' ),
            'usage'          => esc_html__( 'Usage', 'simple-points-and-rewards' ),
            'expiry'         => esc_html__( 'Expiry', 'simple-points-and-rewards' ),
            'email_restrict' => esc_html__( 'Customer', 'simple-points-and-rewards' ),
            'status'         => esc_html__( 'Status', 'simple-points-and-rewards' ),
            'date'           => esc_html__( 'Created', 'simple-points-and-rewards' ),
        ];
    }

    public function get_sortable_columns() {
        return array(
            'title'         => array('title', true),
            'discount_type' => array('discount_type', false),
            'amount'        => array('amount', false),
            'expiry'        => array('expiry', false),
            'status'        => array('status', false),
            'date'          => array('date', true),
        );
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="coupon[]" value="%d" />', esc_attr( $item->ID ) );
    }

    protected function column_title( $item ) {
        $edit_link = get_edit_post_link( $item->ID );
        $title = ( !empty( $item->title ) ? $item->title : esc_html__( '(no title)', 'simple-points-and-rewards' ) );
        $actions = [
            'edit'  => sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit', 'simple-points-and-rewards' ) ),
            'trash' => sprintf( '<a href="%s">%s</a>', esc_url( get_delete_post_link( $item->ID ) ), esc_html__( 'Trash', 'simple-points-and-rewards' ) ),
        ];
        return sprintf(
            '<strong><a class="row-title" href="%s">%s</a></strong>%s',
            esc_url( $edit_link ),
            esc_html( $title ),
            $this->row_actions( $actions )
        );
    }

    protected function column_discount_type( $item ) {
        return ucfirst( str_replace( '_', ' ', ( $item->discount_type ?: '-' ) ) );
    }

    protected function column_product( $item ) {
        // If the coupon is for a specific product, get the product ID
        $product_id = get_post_meta( $item->ID, 'product_ids', true );
        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                return sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $product_id ) ), esc_html( $product->get_name() ) );
            }
        }
        return '-';
    }

    protected function column_amount( $item ) {
        // Percent or fixed amount
        if ( $item->discount_type === 'percent' ) {
            return sprintf( '%d%%', (int) $item->amount );
        } else {
            return wc_price( (float) $item->amount );
        }
    }

    protected function column_usage( $item ) {
        if ( $item->usage_count > 0 ) {
            return sprintf( '<span class="dashicons dashicons-yes"></span>' . esc_html__( ' Redeemed ', 'simple-points-and-rewards' ) );
        } else {
            return sprintf( '<span class="dashicons dashicons-no"></span>' . esc_html__( ' Not Redeemed ', 'simple-points-and-rewards' ) );
        }
    }

    protected function column_expiry( $item ) {
        return ( $item->expiry ? esc_html( date_i18n( get_option( 'date_format' ), $item->expiry ) ) : '-' );
    }

    protected function column_email_restrict( $item ) {
        if ( empty( $item->email_restrict ) ) {
            return '-';
        }
        if ( is_array( $item->email_restrict ) ) {
            return esc_html( implode( ', ', $item->email_restrict ) );
        }
        // Get customer for single email
        $user = get_user_by( 'email', $item->email_restrict );
        if ( $user ) {
            return sprintf( '<a href="%s">%s</a>', esc_url( get_edit_user_link( $user->ID ) ), esc_html( $user->display_name ) );
        }
        return esc_html( $item->email_restrict );
    }

    protected function column_status( $item ) {
        return esc_html( ucfirst( $item->status ) );
    }

    protected function column_date( $item ) {
        return ( !empty( $item->date ) ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $item->date ) ) ) : '-' );
    }

    protected function get_default_primary_column_name() {
        return 'title';
    }

    // Header names for each column
    protected function get_primary_column_name() {
        return 'title';
    }

    // Replace the existing display() method
    public function display() {
        $singular = $this->_args['singular'];
        $this->prepare_items();
        $this->views();
        $this->display_tablenav( 'top' );
        // Allowed HTML for the checkbox header cell
        $allowed_cb_html = array(
            'input' => array(
                'type'       => true,
                'id'         => true,
                'name'       => true,
                'value'      => true,
                'checked'    => true,
                'class'      => true,
                'aria-label' => true,
            ),
        );
        ?>
    <table class="wp-list-table <?php 
        echo esc_attr( implode( ' ', $this->get_table_classes() ) );
        ?>">
            <thead>
                <tr>
                    <?php 
        foreach ( $this->get_columns() as $column_key => $column_display_name ) {
            $class = ['manage-column', "column-{$column_key}"];
            if ( 'cb' === $column_key ) {
                $class[] = 'check-column';
            }
            if ( $column_key === $this->get_primary_column_name() ) {
                $class[] = 'column-primary';
            }
            // Sanitize output: allow only the checkbox for cb column; escape others as text
            if ( 'cb' === $column_key ) {
                printf( '<th scope="col" class="%s">%s</th>', esc_attr( implode( ' ', $class ) ), wp_kses( $column_display_name, $allowed_cb_html ) );
            } else {
                printf( '<th scope="col" class="%s">%s</th>', esc_attr( implode( ' ', $class ) ), esc_html( $column_display_name ) );
            }
        }
        ?>
                </tr>
            </thead>

            <tbody id="the-list"<?php 
        if ( $singular ) {
            printf( ' data-wp-lists="%s"', esc_attr( 'list:' . $singular ) );
        }
        ?>>
                <?php 
        $this->display_rows_or_placeholder();
        ?>
            </tbody>

            <tfoot>
                <tr>
                    <?php 
        foreach ( $this->get_columns() as $column_key => $column_display_name ) {
            $class = ['manage-column', "column-{$column_key}"];
            if ( 'cb' === $column_key ) {
                $class[] = 'check-column';
            }
            if ( $column_key === $this->get_primary_column_name() ) {
                $class[] = 'column-primary';
            }
            // Sanitize output: allow only the checkbox for cb column; escape others as text
            if ( 'cb' === $column_key ) {
                printf( '<th scope="col" class="%s">%s</th>', esc_attr( implode( ' ', $class ) ), wp_kses( $column_display_name, $allowed_cb_html ) );
            } else {
                printf( '<th scope="col" class="%s">%s</th>', esc_attr( implode( ' ', $class ) ), esc_html( $column_display_name ) );
            }
        }
        ?>
                </tr>
            </tfoot>
        </table>
        <?php 
    }

    protected function get_table_classes() {
        $classes = parent::get_table_classes();
        $classes[] = 'widefat';
        $classes[] = 'fixed';
        $classes[] = 'striped';
        return $classes;
    }

    public function display_rows() {
        // Allowed HTML for the row checkbox
        $allowed_cb_html = array(
            'input' => array(
                'type'    => true,
                'name'    => true,
                'value'   => true,
                'id'      => true,
                'checked' => true,
                'class'   => true,
            ),
        );
        foreach ( $this->items as $item ) {
            echo '<tr>';
            foreach ( $this->get_columns() as $column_name => $column_display_name ) {
                switch ( $column_name ) {
                    case 'cb':
                        // Output checkbox with strict allowed HTML
                        printf( '<th scope="row" class="check-column">%s</th>', wp_kses( $this->column_cb( $item ), $allowed_cb_html ) );
                        break;
                    case 'title':
                        printf( '<td>%s</td>', wp_kses_post( $this->column_title( $item ) ) );
                        break;
                    case 'discount_type':
                        printf( '<td>%s</td>', wp_kses_post( $this->column_discount_type( $item ) ) );
                        break;
                    case 'product':
                        printf( '<td>%s</td>', wp_kses_post( $this->column_product( $item ) ) );
                        break;
                    case 'amount':
                        printf( '<td>%s</td>', wp_kses_post( $this->column_amount( $item ) ) );
                        break;
                    case 'usage':
                        printf( '<td>%s</td>', wp_kses_post( $this->column_usage( $item ) ) );
                        break;
                    case 'expiry':
                        printf( '<td>%s</td>', wp_kses_post( $this->column_expiry( $item ) ) );
                        break;
                    case 'email_restrict':
                        printf( '<td>%s</td>', wp_kses_post( $this->column_email_restrict( $item ) ) );
                        break;
                    case 'status':
                        printf( '<td>%s</td>', wp_kses_post( $this->column_status( $item ) ) );
                        break;
                    case 'date':
                        printf( '<td>%s</td>', wp_kses_post( $this->column_date( $item ) ) );
                        break;
                    default:
                        echo '<td></td>';
                }
            }
            echo '</tr>';
        }
    }

    public function process_bulk_action() {
        if ( 'delete' === $this->current_action() ) {
            if ( !isset( $_POST['coupon'] ) || !is_array( $_POST['coupon'] ) ) {
                return;
            }
            check_admin_referer( 'bulk-' . $this->_args['plural'] );
            $coupon_ids = array_map( 'absint', (array) wp_unslash( $_POST['coupon'] ) );
            foreach ( $coupon_ids as $coupon_id ) {
                if ( $coupon_id > 0 ) {
                    wp_delete_post( $coupon_id, true );
                }
            }
            $redirect_url = wp_get_referer();
            if ( $redirect_url ) {
                $redirect_url = remove_query_arg( 'action', $redirect_url );
            } else {
                $page_slug = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
                $page_slug = ( $page_slug ? $page_slug : 'spar-reward-vouchers' );
                $redirect_url = admin_url( 'admin.php?page=' . $page_slug );
            }
            wp_safe_redirect( $redirect_url );
            exit;
        }
    }

    public function get_bulk_actions() {
        return [
            'delete' => esc_html__( 'Delete', 'simple-points-and-rewards' ),
        ];
    }

    public function no_items() {
        esc_html_e( 'No reward vouchers found.', 'simple-points-and-rewards' );
    }

    /**
     * Ensure the no-items placeholder spans all columns.
     */
    public function display_rows_or_placeholder() {
        if ( $this->has_items() ) {
            $this->display_rows();
        } else {
            echo '<tr class="no-items">';
            echo '<td class="colspanchange" colspan="' . esc_attr( count( $this->get_columns() ) ) . '" style="text-align:center;">';
            $this->no_items();
            echo '</td>';
            echo '</tr>';
        }
    }

}

function spar_reward_vouchers_admin_page_callback() {
    // Capability check to prevent unauthorized access
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-points-and-rewards' ) );
    }
    $table = new SPAR_Reward_Vouchers_List_Table();
    $table->process_bulk_action();
    // Build dynamic page title with custom rewards label
    $options = get_option( 'spar_options', ( function_exists( 'spar_settings_default' ) ? spar_settings_default() : array() ) );
    $rewards_label = ( isset( $options['rewards_label'] ) ? $options['rewards_label'] : '' );
    $rewards_label = ( $rewards_label !== '' ? $rewards_label : esc_html__( 'Rewards', 'simple-points-and-rewards' ) );
    /* translators: %s: Rewards label */
    $page_title_txt = sprintf( esc_html__( '%s Voucher Coupons', 'simple-points-and-rewards' ), esc_html( $rewards_label ) );
    ?>
    <div class="wrap">
        <?php 
    spar_render_admin_header( $page_title_txt );
    ?>
        <p><?php 
    esc_html_e( 'View and manage voucher coupons generated by customers with their points via the plugin.', 'simple-points-and-rewards' );
    ?></p>

        <?php 
    $table->prepare_items();
    ?>

        <form method="get">
            <?php 
    // Preserve current admin page when searching (read-only context)
    $page_slug = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    $page_slug = ( $page_slug ? $page_slug : 'spar-reward-vouchers' );
    ?>
            <input type="hidden" name="page" value="<?php 
    echo esc_attr( $page_slug );
    ?>" />
            <?php 
    $table->search_box( esc_html__( 'Search Voucher Coupons', 'simple-points-and-rewards' ), 'coupon_search' );
    ?>
        </form>

        <form method="post">
            <?php 
    wp_nonce_field( 'bulk-' . $table->_args['plural'] );
    $table->display();
    ?>
        </form>
    </div>
    <?php 
}
