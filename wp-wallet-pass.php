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
use Firebase\JWT\Key;

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
		add_settings_field( 'pass_logo_id', 'Pass Logo (media)', [
			$this,
			'field_media'
		], 'mwp-settings', 'mwp_apple', [ 'key' => 'pass_logo_id' ] );
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
			'pass_logo_id',
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

	public function field_media( $args ) {
		$opts = get_option( self::OPT_KEY, [] );
		$key  = esc_attr( $args['key'] );
		$val  = isset( $opts[ $key ] ) ? esc_attr( $opts[ $key ] ) : '';
		$preview = '';
		if ( $val && ( $url = wp_get_attachment_image_url( (int) $val, 'medium' ) ) ) {
			$preview = '<img src="' . esc_url( $url ) . '" style="max-width:140px; max-height:70px; display:block; margin-top:8px; border:1px solid #ccd0d4; background:#fff; padding:4px;" />';
		}
		wp_enqueue_media();
		echo "<input type='number' id='mwp_{$key}' name='" . self::OPT_KEY . "[$key]' value='$val' class='small-text' /> ";
		echo "<button type='button' class='button' id='mwp_select_{$key}'>Select Image</button>";
		echo $preview;
		?>
		<script>
		(function(){
			const btn = document.getElementById('mwp_select_<?php echo esc_js($key); ?>');
			const input = document.getElementById('mwp_<?php echo esc_js($key); ?>');
			if(!btn || !input) return;
			btn.addEventListener('click', function(){
				const frame = wp.media({ title: 'Select Pass Logo', multiple: false, library: { type: 'image' } });
				frame.on('select', function(){
					const attachment = frame.state().get('selection').first().toJSON();
					input.value = attachment.id;
				});
				frame.open();
			});
		})();
		</script>
		<?php
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
		if ( ! $action ) {
			return;
		}

		// For apple/google creation, require user + nonce. For verify, use token.
		if ( in_array( $action, [ 'apple', 'google' ], true ) ) {
			if ( ! $user_id ) {
				return;
			}
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
			$member_name = apply_filters( 'mwp_member_name', $user->display_name, $user );
			$member_id   = apply_filters( 'mwp_member_id', (string) $user->user_login, $user );
		} elseif ( $action === 'verify' ) {
			$token = isset( $_GET['mwp_token'] ) ? sanitize_text_field( wp_unslash( $_GET['mwp_token'] ) ) : '';
			if ( empty( $token ) || ! class_exists( '\\Firebase\\JWT\\JWT' ) ) {
				status_header( 400 );
				wp_die( 'Invalid or missing token' );
			}
			try {
				$payload = JWT::decode( $token, new Key( wp_salt( 'auth' ), 'HS256' ) );
				$user_id     = isset( $payload->uid ) ? absint( $payload->uid ) : 0;
				$member_id   = isset( $payload->mid ) ? sanitize_text_field( $payload->mid ) : '';
				$user        = $user_id ? get_user_by( 'id', $user_id ) : false;
				$member_name = $user ? apply_filters( 'mwp_member_name', $user->display_name, $user ) : '';
			} catch ( \Throwable $e ) {
				status_header( 403 );
				wp_die( 'Invalid token' );
			}

			if ( ! $user ) {
				status_header( 404 );
				wp_die( 'User not found' );
			}

			// Render a minimal verification view
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1" />';
			echo '<div style="font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Arial; padding:20px; max-width:480px; margin:auto">';
			echo '<h2>Member Verification</h2>';
			echo '<p><strong>Name:</strong> ' . esc_html( $member_name ) . '</p>';
			echo '<p><strong>Member ID:</strong> ' . esc_html( $member_id ) . '</p>';
			echo '<p>Status: Active</p>';
			echo '</div>';
			exit;
		} else {
			return;
		}

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

		// Build a signed verification URL for QR scanning
		$token = '';
		try {
			$token = class_exists( '\\Firebase\\JWT\\JWT' ) ? JWT::encode( [ 'uid' => $user_id, 'mid' => $member_id, 'iat' => time(), 'exp' => time() + YEAR_IN_SECONDS ], wp_salt( 'auth' ), 'HS256' ) : '';
		} catch ( \Throwable $e ) {
			$token = '';
		}
		$verify_url = add_query_arg( [ 'mwp_action' => 'verify', 'mwp_token' => $token ], home_url( '/' ) );

		$qr_message = apply_filters( 'mwp_barcode_message', $verify_url, $user_id, $member_id );

		$passJson = [
			'formatVersion'      => 1,
			'passTypeIdentifier' => $opts['pass_type_id'],
			'teamIdentifier'     => $opts['team_id'],
			'organizationName'   => $opts['org_name'],
			'description'        => 'Member Card',
			'serialNumber'       => $serial,
			'backgroundColor'    => '#F6F9FA',
			'foregroundColor'    => '#0D9DDB',
			'labelColor'         => '#0D9DDB',
			'generic'            => [
				'primaryFields'   => [ [ 'key' => 'name', 'label' => 'Name', 'value' => $member_name ] ],
				'auxiliaryFields' => [ [ 'key' => 'memberId', 'label' => 'Member ID', 'value' => $member_id ] ]
			],
			'barcodes'          => [
				[
					'format'          => 'PKBarcodeFormatQR',
					'message'         => $qr_message,
					'messageEncoding' => 'iso-8859-1',
					'altText'         => 'Member ID: ' . $member_id
				]
			]
		];


		try {
			$pkpass = new PKPass\PKPass( $opts['p12_path'], $opts['p12_password'] );
			if ( ! empty( $opts['wwdr_pem'] ) ) {
				$pkpass->setWwdrCertificatePath( $opts['wwdr_pem'] );
			}
			$pkpass->setData( $passJson );
			// Add required images (fallback to placeholder icon if missing)
			$base      = plugin_dir_path( __FILE__ ) . 'assets/';
			$icon_path = file_exists( $base . 'icon.png' ) ? ( $base . 'icon.png' ) : $this->mwp_placeholder_icon();
			// Ensure correct filename inside bundle
			$pkpass->addFile( $icon_path, 'icon.png' );

			// Add logo from settings (media attachment) or optional file in assets
			$logo_path = '';
			if ( ! empty( $opts['pass_logo_id'] ) && ( $p = $this->mwp_prepare_logo_path( (int) $opts['pass_logo_id'] ) ) ) {
				$logo_path = $p;
			} elseif ( file_exists( $base . 'logo.png' ) ) {
				$logo_path = $base . 'logo.png';
			}
			if ( $logo_path ) {
				$pkpass->addFile( $logo_path, 'logo.png' );
			}

			// Add generated background with top/bottom bars
			$bg = $this->mwp_generate_background_image( 640, 400, 24, '#0D9DDB', '#F6F9FA' );
			if ( $bg ) {
				$pkpass->addFile( $bg, 'background.png' );
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

	/**
	 * Prepare a PNG logo path from a media attachment.
	 * Converts to PNG in a temp file if needed.
	 */
	private function mwp_prepare_logo_path( int $attachment_id ): string {
		$filepath = get_attached_file( $attachment_id );
		if ( ! $filepath || ! file_exists( $filepath ) ) {
			return '';
		}
		$ext = strtolower( pathinfo( $filepath, PATHINFO_EXTENSION ) );
		if ( $ext === 'png' ) {
			return $filepath;
		}
		// Convert to PNG using GD if available
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return '';
		}
		$data = @file_get_contents( $filepath );
		if ( ! $data ) {
			return '';
		}
		$im = @imagecreatefromstring( $data );
		if ( ! $im ) {
			return '';
		}
		imagesavealpha( $im, true );
		$tmp = tempnam( sys_get_temp_dir(), 'mwp_logo_' );
		$png = $tmp . '.png';
		@unlink( $tmp );
		imagepng( $im, $png );
		imagedestroy( $im );
		return $png;
	}

	/**
	 * Generate a simple background image with thick top/bottom bars.
	 */
	private function mwp_generate_background_image( int $width, int $height, int $bar_thickness, string $bar_hex, string $bg_hex ): string {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}
		$img = imagecreatetruecolor( $width, $height );
		imagealphablending( $img, true );
		imagesavealpha( $img, true );
		// Colors
		$bar = $this->mwp_hex_to_color( $img, $bar_hex );
		$bg  = $this->mwp_hex_to_color( $img, $bg_hex );
		// Fill background
		imagefilledrectangle( $img, 0, 0, $width, $height, $bg );
		// Top and bottom bars
		imagefilledrectangle( $img, 0, 0, $width, $bar_thickness, $bar );
		imagefilledrectangle( $img, 0, $height - $bar_thickness, $width, $height, $bar );
		$tmp = tempnam( sys_get_temp_dir(), 'mwp_bg_' );
		$png = $tmp . '.png';
		@unlink( $tmp );
		imagepng( $img, $png );
		imagedestroy( $img );
		return $png;
	}

	private function mwp_hex_to_color( $img, string $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		return imagecolorallocatealpha( $img, $r, $g, $b, 0 );
	}
}

new MWP_Plugin();

// ==== Filter examples ====
// Override how member name/ID are resolved from a WP_User object (e.g., pull from custom user meta)
// add_filter('mwp_member_name', function($default, $user){ return get_user_meta($user->ID, 'first_name', true).' '.get_user_meta($user->ID,'last_name',true); }, 10, 2);
// add_filter('mwp_member_id', function($default, $user){ return get_user_meta($user->ID, 'membership_number', true) ?: (string)$user->ID; }, 10, 2);
