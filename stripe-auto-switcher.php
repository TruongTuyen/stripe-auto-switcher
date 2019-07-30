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
	}

	public function do_schedule_action() {
		$this->update_stripe_settings();
	}

	public function register_woo_events() {
		$events = array(
			'saw_event_do_switch' => array(
				'args' => array(),
				'group' => 'saw_events',
				'time' => 6 * 60 * 60,
			),
		);

		foreach ( $events as $action_hook => $args ) {
			$args = wp_parse_args(
				$args,
				array(
					'args' => array(),
					'group' => 'saw_events',
					'time' => 6 * 60 * 60,
				)
			);

			$index = 0;
			if ( ! WC()->queue()->get_next( $action_hook, null, $args['group'] ) ) {
				$index ++;
				WC()->queue()->schedule_recurring( time() + 10 * $index, $args['time'], $action_hook, $args['args'], $args['group'] );
			}
		}
	}

	public function register_cron_schedule( $schedules ) {
		$schedules['every_6_hours'] = array(
			'interval' => 6 * 60 * 60,
			'display'  => esc_html__( 'Every 6 hours', 'saw' ),
		);

		return $schedules;
	}

	public static function register_schedule_action() {
		if ( ! wp_next_scheduled( 'saw_run_switch' ) ) {
			wp_schedule_event( time(), 'every_6_hours', 'saw_run_switch' );
		}
	}

	public function update_stripe_settings() {
		$options = get_option( 'saw-settings', array() );
		if ( is_array( $options ) && isset( $options['stripe_infos'] ) && count( $options['stripe_infos'] ) > 1 ) {
			$stripe_infos = array_filter( $options['stripe_infos'] );
			reset( $stripe_infos );
			$stripe_settings = get_option( 'woocommerce_stripe_settings', array() );

			$current_publishable_key = $stripe_settings['publishable_key'];
			$current_secret_key = $stripe_settings['secret_key'];
			$get_index = -1;
			foreach ( array_keys( $stripe_infos ) as $index => $key ) {
				$item = $stripe_infos[ $key ];
				if ( isset( $item['live_publishable_key'] ) && $item['live_publishable_key'] == $current_publishable_key && isset( $item['live_secret_key'] ) && $item['live_secret_key'] == $current_secret_key ) {
					$get_index = (int) $index;
				}
			}
			$info_data = $stripe_infos[ array_keys( $stripe_infos )[0] ];
			if ( array_key_exists( $get_index + 1, array_keys( $stripe_infos ) ) ) {
				$info_data = $stripe_infos[ array_keys( $stripe_infos )[ $get_index + 1 ] ];
			}
			if ( isset( $info_data['live_publishable_key'] ) && ! empty( $info_data['live_publishable_key'] ) && isset( $info_data['live_secret_key'] ) && ! empty( $info_data['live_secret_key'] ) ) {
				$stripe_settings['publishable_key'] = $info_data['live_publishable_key'];
				$stripe_settings['secret_key'] = $info_data['live_secret_key'];
				update_option( 'saw_current_val', $info_data['live_publishable_key'] );
				update_option( 'woocommerce_stripe_settings', $stripe_settings );
			}
		}
	}

	public function render_options_page() {
		$cmb_options = new_cmb2_box(
			array(
				'id'           => 'saw-settings',
				'title'        => esc_html__( 'Stripe Auto Switcher', 'teesight' ),
				'object_types' => array( 'options-page' ),
				'option_key'   => 'saw-settings',
				'menu_title'   => esc_html__( 'Stripe Switcher', 'teesight' ),
				'save_button'  => esc_html__( 'Save Changes', 'teesight' ),
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

}

new Stripe_Auto_Switcher();

register_activation_hook( __FILE__, 'Stripe_Auto_Switcher::register_schedule_action' );

