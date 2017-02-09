<?php

define( 'PHOTON__ALLOW_QUERY_STRINGS', 1 );

require dirname( __FILE__ ) . '/plugin.php';
if ( file_exists( dirname( __FILE__ ) . '/../config.php' ) )
	require dirname( __FILE__ ) . '/../config.php';
else if ( file_exists( dirname( __FILE__ ) . '/config.php' ) )
	require dirname( __FILE__ ) . '/config.php';

// Explicit Configuration
$allowed_functions = apply_filters( 'allowed_functions', array(
//	'q'           => RESERVED
//	'zoom'        => global resolution multiplier (argument filter)
//	'quality'     => sets the quality of JPEG images during processing
//	'strip        => strips JPEG images of exif, icc or all "extra" data (params: info,color,all)
	'h'           => 'set_height',      // done
	'w'           => 'set_width',       // done
	'crop'        => 'crop',            // done
	'resize'      => 'resize_and_crop', // done
	'fit'         => 'fit_in_box',      // done
	'lb'          => 'letterbox',       // done
	'ulb'         => 'unletterbox',     // compat
	'filter'      => 'filter',          // compat
	'brightness'  => 'brightness',      // compat
	'contrast'    => 'contrast',        // compat
	'colorize'    => 'colorize',        // compat
	'smooth'      => 'smooth',          // compat
) );

unset( $allowed_functions['q'] );

$allowed_types = apply_filters( 'allowed_types', array(
	'gif',
	'jpg',
	'jpeg',
	'png',
) );

$disallowed_file_headers = apply_filters( 'disallowed_file_headers', array(
	'8BPS',
) );

// Expects a trailing slash
$tmpdir = apply_filters( 'tmpdir', '/tmp/' );
$remote_image_max_size = apply_filters( 'remote_image_max_size', 55 * 1024 * 1024 );

/* Array of domains exceptions
 * Keys are domain name
 * Values are bitmasks with the following options:
 * PHOTON__ALLOW_QUERY_STRINGS: Append the string found in the 'q' query string parameter as the query string of the remote URL
 */
$origin_domain_exceptions = apply_filters( 'origin_domain_exceptions', array() );

define( 'JPG_MAX_QUALITY', 89 );
define( 'PNG_MAX_QUALITY', 80 );
define( 'WEBP_MAX_QUALITY', 80 );

// The 'w' and 'h' parameter are processed distinctly
define( 'ALLOW_DIMS_CHAINING', true );

// Strip all meta data from WebP images by default
define( 'CWEBP_DEFAULT_META_STRIP', 'all' );

// You can override this by defining it in config.php
if ( ! defined( 'UPSCALE_MAX_PIXELS' ) )
	define( 'UPSCALE_MAX_PIXELS', 2000 );

// Allow smaller upscales for GIFs, compared to the other image types
if ( ! defined( 'UPSCALE_MAX_PIXELS_GIF' ) )
	define( 'UPSCALE_MAX_PIXELS_GIF', 1000 );

// Implicit configuration
if ( file_exists( '/usr/local/bin/optipng' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'OPTIPNG', '/usr/local/bin/optipng' );
else
	define( 'OPTIPNG', false );

if ( file_exists( '/usr/local/bin/pngquant' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'PNGQUANT', '/usr/local/bin/pngquant' );
else
	define( 'PNGQUANT', false );

if ( file_exists( '/usr/local/bin/cwebp' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'CWEBP', '/usr/local/bin/cwebp' );
else
	define( 'CWEBP', false );

if ( file_exists( '/usr/local/bin/jpegoptim' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'JPEGOPTIM', '/usr/local/bin/jpegoptim' );
else
	define( 'JPEGOPTIM', false );

require dirname( __FILE__ ) . '/class-image-processor.php';

function httpdie( $code = '404 Not Found', $message = 'Error: 404 Not Found' ) {
	$numerical_error_code = preg_replace( '/[^\\d]/', '', $code );
	do_action( 'bump_stats', "http_error-$numerical_error_code" );
	header( 'HTTP/1.1 ' . $code );
	die( $message );
}

function fetch_raw_data( $url, $timeout = 10, $connect_timeout = 3 ) {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout );
	curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $ch, CURLOPT_MAXREDIRS, 3 );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'Photon/1.0' );
	curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
	curl_setopt( $ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
	curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl_handle, $data ) {
		global $raw_data, $raw_data_size, $remote_image_max_size;

		$data_size = strlen( $data );
		$raw_data .= $data;
		$raw_data_size += $data_size;

		if ( $raw_data_size > $remote_image_max_size )
			httpdie( '400 Bad Request', "You can only process images up to $remote_image_max_size bytes." );

		return $data_size;
	} );

	return curl_exec( $ch );
}

$parsed = parse_url( $_SERVER['REQUEST_URI'] );
$exploded = explode( '/', $_SERVER['REQUEST_URI'] );
$origin_domain = strtolower( $exploded[1] );
$origin_domain_exception = array_key_exists( $origin_domain, $origin_domain_exceptions ) ? $origin_domain_exceptions[$origin_domain] : 0;

$scheme = 'http' . ( array_key_exists( 'ssl', $_GET ) ? 's' : '' ) . '://';
parse_str( ( empty( $parsed['query'] ) ? '' : $parsed['query'] ),  $_GET  );

$ext = strtolower( pathinfo( $parsed['path'], PATHINFO_EXTENSION ) );

$url = $scheme . substr( $parsed['path'], 1 );
$url = preg_replace( '/#.*$/', '', $url );
$url = apply_filters( 'url', $url );

if ( isset( $_GET['q'] ) ) {
	if ( $origin_domain_exception & PHOTON__ALLOW_QUERY_STRINGS ) {
		$url .= '?' . preg_replace( '/#.*$/', '', (string) $_GET['q'] );
		unset( $_GET['q'] );
	} else {
		httpdie( '400 Bad Request', 'Sorry, the parameters you provided were not valid' );
	}
}

if ( false === filter_var( $url, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED ) )
	httpdie( '400 Bad Request', 'Sorry, the parameters you provided were not valid' );

$raw_data = '';
$raw_data_size = 0;
$fetched = fetch_raw_data( $url );
if ( ! $fetched || empty( $raw_data ) )
	httpdie( '404 Not Found', 'We cannot complete this request, remote data could not be fetched' );

foreach ( $disallowed_file_headers as $file_header ) {
	if ( substr( $raw_data, 0, strlen( $file_header ) ) == $file_header )
		httpdie( '400 Bad Request', 'Error 0002. The type of image you are trying to process is not allowed.' );
}

$img_proc = new Image_Processor();
if ( ! $img_proc )
	httpdie( '500 Internal Server Error', 'Error 0003. Unable to load the image.' );

$img_proc->send_nosniff_header = true;
$img_proc->norm_color_profile  = false;
$img_proc->send_bytes_saved    = true;
$img_proc->send_etag_header    = true;
$img_proc->canonical_url       = $url;
$img_proc->image_max_age       = 63115200;
$img_proc->image_data          = $raw_data;

if ( ! $img_proc->load_image() )
	httpdie( '400 Bad Request', 'Error 0004. Unable to load the image.' );

if ( ! in_array( $img_proc->image_format, $allowed_types ) )
	httpdie( '400 Bad Request', 'Error 0005. The type of image you are trying to process is not allowed.' );

$original_mime_type = $img_proc->mime_type;
$img_proc->process_image();

// Update the stats of the processed functions
foreach ( $img_proc->processed as $function_name ) {
	do_action( 'bump_stats', $function_name );
}

switch ( $original_mime_type ) {
	case 'image/png':
		do_action( 'bump_stats', 'image_png' . ( 'image/webp' == $img_proc->mime_type ? '_as_webp' : '' ) );
		do_action( 'bump_stats', 'png_bytes_saved', $img_proc->bytes_saved );
		break;
	case 'image/gif':
		do_action( 'bump_stats', 'image_gif' );
		break;
	default:
		do_action( 'bump_stats', 'image_jpeg' . ( 'image/webp' == $img_proc->mime_type ? '_as_webp' : '' ) );
		do_action( 'bump_stats', 'jpg_bytes_saved', $img_proc->bytes_saved );
}
