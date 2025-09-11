<?php

/**
 * Plugin Name: Wp Wallet Pass
 * Description: Add to Apple Wallet (.pkpass) and Google Wallet for member profiles with dynamic name & member ID.
 * Version: 1.0.0
 * Author: You
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---- Composer Autoload ----
$autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

use Firebase\JWT\JWT;

class MWP_Plugin {
	const OPT_KEY = 'mwp_options';

	public function __construct() {
		// Settings
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		// Public endpoints
		add_action( 'init', [ $this, 'register_query_vars_and_rewrites' ] );
		add_action( 'template_redirect', [ $this, 'handle_template_redirect' ] );

		// Shortcode
		add_shortcode( 'member_wallet_pass', [ $this, 'shortcode_buttons' ] );

		// Flush rewrites on activation/deactivation
		register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
		register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );
	}

	// === Activation ===
	public static function activate() {
		( new self() )->register_query_vars_and_rewrites();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	// === Settings ===
	public function add_settings_page() {
		add_options_page(
			'WP Wallet Pass',
			'WP Wallet Pass',
			'manage_options',
			'mwp-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( self::OPT_KEY, self::OPT_KEY, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_options' ]
		] );

		add_settings_section( 'mwp_apple', 'Apple Wallet', '__return_false', 'mwp-settings' );
		add_settings_field( 'team_id', 'Team ID', [
			$this,
			'field_text'
		], 'mwp-settings', 'mwp_apple', [ 'key' => 'team_id' ] );
		add_settings_field( 'pass_type_id', 'Pass Type ID', [
			$this,
			'field_text'
		], 'mwp-settings', 'mwp_apple', [ 'key' => 'pass_type_id' ] );
		add_settings_field( 'org_name', 'Organization Name', [
			$this,
			'field_text'
		], 'mwp-settings', 'mwp_apple', [ 'key' => 'org_name' ] );
		add_settings_field( 'p12_path', '.p12 Cert Path', [
			$this,
			'field_text'
		], 'mwp-settings', 'mwp_apple', [
			'key'         => 'p12_path',
			'placeholder' => WP_CONTENT_DIR . '/uploads/wallet-keys/cert.p12'
		] );
		add_settings_field( 'p12_password', '.p12 Password', [
			$this,
			'field_password'
		], 'mwp-settings', 'mwp_apple', [ 'key' => 'p12_password' ] );
		add_settings_field( 'wwdr_pem', 'WWDR PEM (optional path)', [
			$this,
			'field_text'
		], 'mwp-settings', 'mwp_apple', [
			'key'         => 'wwdr_pem',
			'placeholder' => WP_CONTENT_DIR . '/uploads/wallet-keys/WWDR.pem'
		] );

		add_settings_section( 'mwp_google', 'Google Wallet', '__return_false', 'mwp-settings' );
		add_settings_field( 'issuer_id', 'Issuer ID', [
			$this,
			'field_text'
		], 'mwp-settings', 'mwp_google', [ 'key' => 'issuer_id' ] );
		add_settings_field( 'class_id', 'Class ID (e.g. issuer.member_class)', [
			$this,
			'field_text'
		], 'mwp-settings', 'mwp_google', [ 'key' => 'class_id' ] );
		add_settings_field( 'sa_json_path', 'Service Account JSON Path', [
			$this,
			'field_text'
		], 'mwp-settings', 'mwp_google', [
			'key'         => 'sa_json_path',
			'placeholder' => WP_CONTENT_DIR . '/uploads/wallet-keys/google-sa.json'
		] );
	}

	public function sanitize_options( $opts ) {
		$safe = [];
		$keys = [
			'team_id',
			'pass_type_id',
			'org_name',
			'p12_path',
			'p12_password',
			'wwdr_pem',
			'issuer_id',
			'class_id',
			'sa_json_path'
		];
		foreach ( $keys as $k ) {
			$safe[ $k ] = isset( $opts[ $k ] ) ? sanitize_text_field( $opts[ $k ] ) : '';
		}

		return $safe;
	}

	public function field_text( $args ) {
		$opts = get_option( self::OPT_KEY, [] );
		$key  = esc_attr( $args['key'] );
		$val  = isset( $opts[ $key ] ) ? esc_attr( $opts[ $key ] ) : '';
		$ph   = isset( $args['placeholder'] ) ? esc_attr( $args['placeholder'] ) : '';
		echo "<input type='text' name='" . self::OPT_KEY . "[$key]' value='$val' class='regular-text' placeholder='$ph' />";
	}

	public function field_password( $args ) {
		$opts = get_option( self::OPT_KEY, [] );
		$key  = esc_attr( $args['key'] );
		$val  = isset( $opts[ $key ] ) ? esc_attr( $opts[ $key ] ) : '';
		echo "<input type='password' name='" . self::OPT_KEY . "[$key]' value='$val' class='regular-text' />";
	}

	public function render_settings_page() {
		echo '<div class="wrap"><h1>WP Wallet Pass</h1><form method="post" action="options.php">';
		settings_fields( self::OPT_KEY );
		do_settings_sections( 'mwp-settings' );
		submit_button( 'Save Settings' );
		echo '</form>';
		echo '<p><strong>Notes:</strong> Store secrets outside webroot if possible and reference absolute paths. Ensure files are readable by PHP only.</p>';
		echo '</div>';
	}

	// === Query vars & rewrites ===
	public function register_query_vars_and_rewrites() {
		add_rewrite_rule( '^wallet/(apple|google)/([0-9]+)/?$', 'index.php?mwp_action=$matches[1]&mwp_user=$matches[2]', 'top' );
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'mwp_action';
			$vars[] = 'mwp_user';
			$vars[] = 'mwp_nonce';

			return $vars;
		} );
	}

	public function handle_template_redirect() {
		$action  = get_query_var( 'mwp_action' );
		$user_id = absint( get_query_var( 'mwp_user' ) );
		if ( ! $action || ! $user_id ) {
			return;
		}

		// Nonce check
		$nonce = isset( $_GET['mwp_nonce'] ) ? sanitize_text_field( $_GET['mwp_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mwp_' . $user_id ) ) {
			status_header( 403 );
			wp_die( 'Invalid nonce' );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			status_header( 404 );
			wp_die( 'User not found' );
		}

		// Resolve dynamic fields (filterable)
		$member_name = apply_filters( 'mwp_member_name', $user->display_name, $user );
		$member_id   = apply_filters( 'mwp_member_id', (string) $user->user_login, $user ); // default: username

		if ( $action === 'apple' ) {
			$this->serve_apple_pkpass( $member_name, $member_id, $user_id );
		} elseif ( $action === 'google' ) {
			$this->redirect_google_wallet( $member_name, $member_id, $user_id );
		}
		exit;
	}

	// === Shortcode ===
	public function shortcode_buttons( $atts ) {
		$a = shortcode_atts( [ 'user_id' => '' ], $atts, 'member_wallet_pass' );

		$user_id = absint( $a['user_id'] );
		if ( ! $user_id ) {
			// Try to detect displayed author or fallback to current user
			if ( is_author() ) {
				$user_id = (int) get_query_var( 'author' );
			} else if ( function_exists( 'bp_displayed_user_id' ) && bp_displayed_user_id() ) { // BuddyPress
				$user_id = (int) bp_displayed_user_id();
			} else {
				$user_id = get_current_user_id();
			}
		}
		if ( ! $user_id ) {
			return '<em>No member context.</em>';
		}


		$nonce = wp_create_nonce( 'mwp_' . $user_id );
		// Use query-arg URLs to avoid relying on rewrite flush/state.
		$apple_url  = add_query_arg( [
			'mwp_action' => 'apple',
			'mwp_user'   => $user_id,
			'mwp_nonce'  => $nonce,
		], home_url( '/' ) );
		$google_url = add_query_arg( [
			'mwp_action' => 'google',
			'mwp_user'   => $user_id,
			'mwp_nonce'  => $nonce,
		], home_url( '/' ) );

		ob_start(); ?>
        <div class="mwp-wallet-buttons">
            <a class="mwp-apple" href="<?php echo esc_url( $apple_url ); ?>">Add to Apple Wallet</a>
            <a class="mwp-google" href="<?php echo esc_url( $google_url ); ?>">Add to Google Wallet</a>
        </div>
        <style>
            .mwp-wallet-buttons {
                display: flex;
                gap: 10px;
                align-items: center;
            }

            .mwp-wallet-buttons a {
                padding: 10px 14px;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
            }

            .mwp-apple {
                background: #000;
                color: #fff;
            }

            .mwp-google {
                background: #f1f1f1;
                color: #111;
            }
        </style>
		<?php
		return ob_get_clean();
	}

	// === Apple Wallet ===
	private function serve_apple_pkpass( string $member_name, string $member_id, int $user_id ) {
		// Generate and stream a .pkpass for the member
		$opts = get_option( self::OPT_KEY, [] );
		foreach ( [ 'team_id', 'pass_type_id', 'org_name', 'p12_path', 'p12_password' ] as $req ) {
			if ( empty( $opts[ $req ] ) ) {
				status_header( 500 );
				wp_die( 'Apple settings incomplete: ' . $req );
			}
		}
		if ( ! class_exists( 'PKPass\\PKPass' ) ) {
			status_header( 500 );
			wp_die( 'php-pkpass library not found. Run composer install.' );
		}

		$serial = 'user-' . $user_id . '-' . time();

		$passJson = [
			'formatVersion'      => 1,
			'passTypeIdentifier' => $opts['pass_type_id'],
			'teamIdentifier'     => $opts['team_id'],
			'organizationName'   => $opts['org_name'],
			'description'        => 'Member Card',
			'serialNumber'       => $serial,
			'backgroundColor'    => 'rgb(0,0,0)',
			'foregroundColor'    => 'rgb(255,255,255)',
			'labelColor'         => 'rgb(255,255,255)',
			'generic'            => [
				'primaryFields'   => [ [ 'key' => 'name', 'label' => 'Name', 'value' => $member_name ] ],
				'auxiliaryFields' => [ [ 'key' => 'memberId', 'label' => 'Member ID', 'value' => $member_id ] ]
			],
			'barcode'            => [
				'format'          => 'PKBarcodeFormatQR',
				'message'         => 'MEMBER:' . $member_id,
				'messageEncoding' => 'iso-8859-1'
			]
		];


		try {
			$pkpass = new PKPass\PKPass( $opts['p12_path'], $opts['p12_password'] );
			if ( ! empty( $opts['wwdr_pem'] ) ) {
				$pkpass->setWWDRcertPath( $opts['wwdr_pem'] );
			}
			$pkpass->setJSON( json_encode( $passJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			// Add required images (fallback to placeholder icon if missing)
			$base      = plugin_dir_path( __FILE__ ) . 'assets/';
			$icon_path = file_exists( $base . 'icon.png' ) ? ( $base . 'icon.png' ) : $this->mwp_placeholder_icon();
			$pkpass->addAsset( $icon_path );
			if ( file_exists( $base . 'logo.png' ) ) {
				$pkpass->addAsset( $base . 'logo.png' );
			}

			$blob = $pkpass->create();
			if ( ! $blob ) {
				status_header( 500 );
				wp_die( 'Failed to create pass' );
			}

			nocache_headers();
			header( 'Content-Type: application/vnd.apple.pkpass' );
			header( 'Content-Disposition: attachment; filename="member-' . $user_id . '.pkpass"' );
			echo $blob;
			exit;
		} catch ( Throwable $e ) {
			status_header( 500 );
			wp_die( 'PKPass error: ' . $e->getMessage() );
		}
	}

	// === Google Wallet ===
	private function redirect_google_wallet( string $member_name, string $member_id, int $user_id ) {
		$opts = get_option( self::OPT_KEY, [] );
		foreach ( [ 'issuer_id', 'sa_json_path' ] as $req ) {
			if ( empty( $opts[ $req ] ) ) {
				status_header( 500 );
				wp_die( 'Google settings incomplete: ' . $req );
			}
		}
		if ( ! class_exists( 'Firebase\\JWT\\JWT' ) ) {
			status_header( 500 );
			wp_die( 'firebase/php-jwt not found. Run composer install.' );
		}

		$serviceAccount = json_decode( @file_get_contents( $opts['sa_json_path'] ), true );
		if ( ! $serviceAccount || empty( $serviceAccount['private_key'] ) || empty( $serviceAccount['client_email'] ) ) {
			status_header( 500 );
			wp_die( 'Invalid service account JSON' );
		}

		$issuerId = trim( $opts['issuer_id'] );
		$classId  = ! empty( $opts['class_id'] ) ? trim( $opts['class_id'] ) : ( $issuerId . '.member_class' );
		$objectId = $issuerId . '.user_' . $user_id; // must be globally unique per Google requirements

		$claims = [
			'iss'     => $serviceAccount['client_email'],
			'aud'     => 'google',
			'typ'     => 'savetowallet',
			'iat'     => time(),
			'origins' => [ home_url() ],
			'payload' => [
				'genericClasses' => [
					[
						'id'                 => $classId,
						'issuerName'         => get_bloginfo( 'name' ),
						'hexBackgroundColor' => '#000000',
						'logo'               => [ 'sourceUri' => [ 'uri' => get_site_icon_url() ] ],
						'cardTitle'          => [
							'defaultValue' => [
								'language' => 'en-US',
								'value'    => 'Member Card'
							]
						],
					]
				],
				'genericObjects' => [
					[
						'id'              => $objectId,
						'classId'         => $classId,
						'state'           => 'ACTIVE',
						'header'          => [ 'defaultValue' => [ 'language' => 'en-US', 'value' => 'Member Card' ] ],
						'subheader'       => [ 'defaultValue' => [ 'language' => 'en-US', 'value' => $member_name ] ],
						'textModulesData' => [
							[
								'header' => 'Member ID',
								'body'   => $member_id
							]
						],
						'barcode'         => [ 'type' => 'QR_CODE', 'value' => 'MEMBER:' . $member_id ]
					]
				]
			]
		];

		try {
			$jwt = JWT::encode( $claims, $serviceAccount['private_key'], 'RS256' );
			$url = 'https://pay.google.com/gp/v/save/' . $jwt;
			wp_redirect( $url );
			exit;
		} catch ( Throwable $e ) {
			status_header( 500 );
			wp_die( 'JWT error: ' . $e->getMessage() );
		}
	}

	/**
	 * Create a temporary 1x1 transparent PNG and return its path.
	 * Used as a safe fallback when assets/icon.png is missing.
	 */
	private function mwp_placeholder_icon(): string {
		$b64  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Yh9W5YAAAAASUVORK5CYII=';
		$tmp  = tempnam( sys_get_temp_dir(), 'mwp_icon_' );
		$path = $tmp . '.png';
		@unlink( $tmp );
		file_put_contents( $path, base64_decode( $b64 ) );
		return $path;
	}
}

new MWP_Plugin();

// ==== Filter examples ====
// Override how member name/ID are resolved from a WP_User object (e.g., pull from custom user meta)
// add_filter('mwp_member_name', function($default, $user){ return get_user_meta($user->ID, 'first_name', true).' '.get_user_meta($user->ID,'last_name',true); }, 10, 2);
// add_filter('mwp_member_id', function($default, $user){ return get_user_meta($user->ID, 'membership_number', true) ?: (string)$user->ID; }, 10, 2);
