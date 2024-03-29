<?php
/**
 * Plugin Name: Stripe Auto Switcher
 * Plugin URI: https://pressmaximum.com/
 * Description: An auto switcher for Stripe.
 * Version: 0.0.1
 * Author: PressMaximum
 * Author URI: https://pressmaximum.com/
 * Text Domain: saw
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'STRIPE_AUTO_SWITCHER_DIR', plugin_dir_path( __FILE__ ) );

if ( ! class_exists( 'CMB2' ) ) {
	require_once STRIPE_AUTO_SWITCHER_DIR . 'inc/CMB2/init.php';
}

class Stripe_Auto_Switcher {
	public function __construct() {
		add_action( 'cmb2_admin_init', array( $this, 'render_options_page' ) );
		add_action( 'wp_loaded', array( $this, 'register_woo_events' ) );
		add_action( 'saw_event_do_switch', array( $this, 'do_schedule_action' ) );

		add_action( 'update_option_saw-settings', array( $this, 'when_option_updated' ), 10, 3 );
		add_action( 'activated_plugin', array( $this, 'plugin_activation_redirect' ), PHP_INT_MAX );
		add_action( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_settings_links' ), 10, 1 );

	}

	public function when_option_updated( $old_value, $value, $option ) {
		if ( isset( $old_value['recurrence_time'] ) && isset( $value['recurrence_time'] ) && $old_value['recurrence_time'] != $value['recurrence_time'] ) {
			WC()->queue()->cancel_all( 'saw_event_do_switch', array(), 'saw_events' );
		}
	}

	public function do_schedule_action() {
		$this->update_stripe_settings();
	}

	public function get_setting_recurrence_time() {
		$options      = get_option( 'saw-settings', array() );
		$setting_time = 6;
		if ( is_array( $options ) && isset( $options['recurrence_time'] ) && is_numeric( $options['recurrence_time'] ) ) {
			$setting_time = absint( $options['recurrence_time'] );
		}
		return $setting_time;
	}

	public function register_woo_events() {
		$options      = get_option( 'saw-settings', array() );
		$setting_time = $this->get_setting_recurrence_time();
		$events       = array(
			'saw_event_do_switch' => array(
				'args'  => array(),
				'group' => 'saw_events',
				'time'  => $setting_time * 60 * 60,
			),
		);

		foreach ( $events as $action_hook => $args ) {
			$args = wp_parse_args(
				$args,
				array(
					'args'  => array(),
					'group' => 'saw_events',
					'time'  => $setting_time * 60 * 60,
				)
			);

			$index = 0;
			if ( ! WC()->queue()->get_next( $action_hook, null, $args['group'] ) ) {
				$index ++;
				WC()->queue()->schedule_recurring( time() + 10 * $index, $args['time'], $action_hook, $args['args'], $args['group'] );
			}
		}
	}

	public function update_stripe_settings() {
		$options = get_option( 'saw-settings', array() );
		if ( is_array( $options ) && isset( $options['stripe_infos'] ) && count( $options['stripe_infos'] ) > 1 ) {
			$stripe_infos = array_filter( $options['stripe_infos'] );
			reset( $stripe_infos );
			$stripe_settings = get_option( 'woocommerce_stripe_settings', array() );

			$current_publishable_key = ( isset( $stripe_settings['publishable_key'] ) ) ? $stripe_settings['publishable_key'] : '';
			$current_secret_key      = ( isset( $stripe_settings['secret_key'] ) ) ? $stripe_settings['secret_key'] : '';
			$get_index               = -1;
			foreach ( array_keys( $stripe_infos ) as $index => $key ) {
				$item = $stripe_infos[ $key ];
				if ( isset( $item['live_publishable_key'] ) && $item['live_publishable_key'] == $current_publishable_key && isset( $item['live_secret_key'] ) && $item['live_secret_key'] == $current_secret_key ) {
					$get_index = (int) $index;
					break;
				}
			}
			$info_data = $stripe_infos[ array_keys( $stripe_infos )[0] ];
			if ( array_key_exists( $get_index + 1, array_keys( $stripe_infos ) ) ) {
				$info_data = $stripe_infos[ array_keys( $stripe_infos )[ $get_index + 1 ] ];
			}
			if ( isset( $info_data['live_publishable_key'] ) && ! empty( $info_data['live_publishable_key'] ) && isset( $info_data['live_secret_key'] ) && ! empty( $info_data['live_secret_key'] ) ) {
				$stripe_settings['publishable_key'] = $info_data['live_publishable_key'];
				$stripe_settings['secret_key']      = $info_data['live_secret_key'];
				update_option( 'saw_current_publishable_key_val', $info_data['live_publishable_key'] );
				update_option( 'saw_current_secret_key_val', $info_data['live_secret_key'] );
				update_option( 'woocommerce_stripe_settings', $stripe_settings );
			}
		}
	}



	public function render_options_page() {
		$cmb_options     = new_cmb2_box(
			array(
				'id'           => 'saw-settings',
				'title'        => esc_html__( 'Stripe Auto Switcher', 'teesight' ),
				'object_types' => array( 'options-page' ),
				'option_key'   => 'saw-settings',
				'menu_title'   => esc_html__( 'Stripe Switcher', 'teesight' ),
				'save_button'  => esc_html__( 'Save Changes', 'teesight' ),
			)
		);
		$stripe_settings = get_option( 'woocommerce_stripe_settings', array() );

		$current_publishable_key = ( isset( $stripe_settings['publishable_key'] ) ) ? $stripe_settings['publishable_key'] : '';
		$current_secret_key      = ( isset( $stripe_settings['secret_key'] ) ) ? $stripe_settings['secret_key'] : '';

		$cmb_options->add_field(
			array(
				'name'       => esc_html__( 'Current publishable key', 'saw' ),
				'id'         => '_saw_current_publishable_key',
				'type'       => 'text',
				'save_field' => false, // Otherwise CMB2 will end up removing the value.
				'attributes' => array(
					'readonly' => 'readonly',
					'disabled' => 'disabled',
				),
				'default'    => $current_publishable_key,
			)
		);

		$cmb_options->add_field(
			array(
				'name'       => esc_html__( 'Current secret key', 'saw' ),
				'id'         => '_saw_current_secret_key',
				'type'       => 'text',
				'save_field' => false, // Otherwise CMB2 will end up removing the value.
				'attributes' => array(
					'readonly' => 'readonly',
					'disabled' => 'disabled',
				),
				'default'    => $current_secret_key,
			)
		);

		$recurrence_time_option = array();
		for ( $i = 1; $i <= 24; $i++ ) {
			$recurrence_time_option[ $i ] = sprintf( 'Every %d Hours', $i );
		}
		$cmb_options->add_field(
			array(
				'name'             => esc_html__( 'Recurrence Time', 'saw' ),
				'desc'             => esc_html__( 'The hours do you want to switch account', 'saw' ),
				'id'               => 'recurrence_time',
				'type'             => 'select',
				'show_option_none' => false,
				'default'          => 6,
				'options'          => $recurrence_time_option,
			)
		);

		$group_field_id = $cmb_options->add_field(
			array(
				'id'      => 'stripe_infos',
				'type'    => 'group',
				'name'    => esc_html__( 'Stripe infos', 'teesight' ),
				'options' => array(
					'group_title'    => esc_html__( 'Info {#}', 'teesight' ),
					'add_button'     => esc_html__( 'Add Another Info', 'teesight' ),
					'remove_button'  => esc_html__( 'Remove Info', 'teesight' ),
					'sortable'       => true,
					'closed'         => true,
					'remove_confirm' => esc_html__( 'Are you sure you want to remove?', 'teesight' ),
					'repeatable'     => false,
				),
			)
		);
		$cmb_options->add_group_field(
			$group_field_id,
			array(
				'name' => esc_html__( 'Live Publishable Key', 'teesight' ),
				'id'   => 'live_publishable_key', // woocommerce_stripe_publishable_key.
				'type' => 'text',
			)
		);
		$cmb_options->add_group_field(
			$group_field_id,
			array(
				'name' => esc_html__( 'Live Secret Key', 'teesight' ),
				'id'   => 'live_secret_key', // woocommerce_stripe_secret_key.
				'type' => 'text',
			)
		);

	}

	public function plugin_activation_redirect( $plugin ) {
		if ( plugin_basename( STRIPE_AUTO_SWITCHER_DIR . 'stripe-auto-switcher.php' ) === $plugin ) {
			$redirect_args = array(
				'page' => 'saw-settings',
			);
			$redirect_url  = add_query_arg( $redirect_args, admin_url( 'admin.php' ) );
			exit( wp_redirect( $redirect_url ) );
		}
	}

	public function plugin_settings_links( $links ) {
		$redirect_args = array(
			'page' => 'saw-settings',
		);
		$redirect_url  = add_query_arg( $redirect_args, admin_url( 'admin.php' ) );
		$settings_link = '<a href="' . esc_url( $redirect_url ) . '" title="' . esc_attr__( 'Settings', 'saw' ) . '">' . esc_html__( 'Settings', 'saw' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

}

new Stripe_Auto_Switcher();

register_deactivation_hook( __FILE__, 'saw_plugin_deactivation' );

function saw_plugin_deactivation() {
	if ( function_exists( 'WC' ) ) {
		WC()->queue()->cancel_all( 'saw_event_do_switch', array(), 'saw_events' );
	}
}

function saw_sanitize_int( $value, $field_args, $field ) {
	if ( ! is_numeric( $value ) ) {
		$default = 0;
		if ( isset( $field_args['default'] ) && is_numeric( $field_args['default'] ) ) {
			$default = absint( $field_args['default'] );
		}
		$sanitized_value = $default;
	} else {
		// Ok, let's clean it up.
		$sanitized_value = absint( $value );
	}
	return $sanitized_value;
}
