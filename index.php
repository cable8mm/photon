<?php

define( 'PHOTON__ALLOW_ANY_EXTENSION', 1 );
define( 'PHOTON__ALLOW_QUERY_STRINGS', 2 );

require dirname( __FILE__ ) . '/plugin.php';
if ( file_exists( dirname( __FILE__ ) . '/../config.php' ) )
	require dirname( __FILE__ ) . '/../config.php';
else if ( file_exists( dirname( __FILE__ ) . '/config.php' ) ) 
	require dirname( __FILE__ ) . '/config.php';

// Explicit Configuration
$allowed_functions = apply_filters( 'allowed_functions', array(
//	'q'           => RESERVED
//	'zoom'        => global resolution multiplier (argument filter)
	'h'           => 'setheight',       // done
	'w'           => 'setwidth',        // done
	'crop'        => 'crop',            // done
	'resize'      => 'resize_and_crop', // done
	'ulb'         => 'unletterbox',     // compat
	'filter'      => 'filter',          // compat
	'brightness'  => 'brightness',      // compat
	'contrast'    => 'contrast',        // compat
	'colorize'    => 'colorize',        // compat
	'smooth'      => 'smooth',          // compat
	'fit'         => 'fit_in_box',      // compat
) );

unset( $allowed_functions['q'] );

$allowed_types = apply_filters( 'allowed_types', array(
	'gif',
	'jpg',
	'jpeg',
	'png',
) );

// Expects a trailing slash
$tmpdir = apply_filters( 'tmpdir', '/tmp/' );

/* Array of domains exceptions
 * Keys are domain name
 * Values are bitmasks with the following options:
 * PHOTON__ALLOW_ANY_EXTENSION: Allow any extension (including none) in the path of the URL
 * PHOTON__ALLOW_QUERY_STRINGS: Append the string found in the 'q' query string parameter as the query string of the remote URL
 */
$origin_domain_exceptions = apply_filters( 'origin_domain_exceptions', array() );

// You can override this by defining it in config.php
if ( ! defined( 'PHOTON__UPSCALE_MAX_PIXELS' ) )
	define( 'PHOTON__UPSCALE_MAX_PIXELS', 1000 );

require dirname( __FILE__ ) . '/libjpeg.php';

// Implicit configuration
if ( file_exists( '/usr/local/bin/optipng' ) )
	define( 'OPTIPNG', '/usr/local/bin/optipng' );
else
	define( 'OPTIPNG', false );

if ( file_exists( '/usr/local/bin/jpegoptim' ) )
	define( 'JPEGOPTIM', '/usr/local/bin/jpegoptim' );
else
	define( 'JPEGOPTIM', false );

/**
 * zoom - ( "zoom" function via the uri ) - Intended for improving visuals
 * on high pixel ratio devices and browsers when zoomed in. No zoom in crop.
 *
 * Valid zoom levels are 1,1.5,2-10.
 */
function zoom( $arguments, $function_name ) {
	static $zoom;

	if ( !isset( $zoom ) ) {
		if ( isset( $_GET['zoom'] ) ) {
			$zoom = floatval( $_GET['zoom'] );
			// Clamp to 1-10
			$zoom = max( 1, $zoom );
			$zoom = min( 10, $zoom );
			if ( $zoom < 2 ) {
				// Round UP to the nearest half
				$zoom = ceil( $zoom * 2 ) / 2;
			} else {
				// Round UP to the nearest integer
				$zoom = ceil( $zoom );
			}
		} else {
			$zoom = false;
		}
	}

	if ( $zoom <= 1 )
		return $arguments;

	switch ( $function_name ) {
		case 'setheight' :
		case 'setwidth' :
			$new_arguments = $arguments * $zoom;
			if ( substr( $arguments, -1 ) == '%' )
				$new_arguments .= '%';
			break;
		case 'fit_in_box' :
		case 'resize_and_crop' :
			list( $width, $height ) = explode( ',', $arguments );
			$width *= $zoom;
			$height *= $zoom;
			$new_arguments = "$width,$height";
			break;
		default :
			$new_arguments = $arguments;
	}

	return $new_arguments;
}
add_filter( 'arguments', 'zoom', 10, 2 );

/**
 * crop - ("crop" function via the uri) - crop an image
 *
 * @param (resource)image the source gd image resource
 * @param (string)args "x,y,w,h" widh each csv column being /^[0-9]+(px)?$/
 *                 all values in percentages by default, but can be set 
 *                 to absolute pixel values by specifying px ie 25px
 *
 * @return (resource)image the resulting image gd resource
 *
 **/
function crop( &$image, $args ) {
	$args = explode( ',', $args );

	$w = $image->getimagewidth();
	$h = $image->getimageheight();

	if ( substr( $args[2], -2 ) == 'px' )	
		$new_w = max( 0, min( $w, intval( $args[2] ) ) );
	else
		$new_w = round( $w * abs( intval( $args[2] ) ) / 100 );

	if ( substr( $args[3], -2 ) == 'px' )
		$new_h = max( 0, min( $h, intval( $args[3] ) ) );
	else
		$new_h = round( $h * abs( intval( $args[3] ) ) / 100 );

	if ( substr( $args[0], -2 ) == 'px' )
		$s_x = intval( $args[0] );
	else
		$s_x = round( $w * abs( intval( $args[0] ) ) / 100 );

	if ( substr( $args[1], -2 ) == 'px' )
		$s_y = intval( $args[1] );
	else
		$s_y = round( $h * abs( intval( $args[1] ) ) / 100 );

	$image->cropimage( $new_w, $new_h, $s_x, $s_y );
}

/**
 * setheight - ( "h" function via the uri ) - resize the image to an explicit height, maintaining its aspect ratio
 *
 * @param (resource)image the source gd image resource
 * @param (string)args "/^[0-9]+%?$/" the new height in pixels, or as a percentage if suffixed with an %
 * @param boolean $upscale Whether to allow upscaling or not, defaults to not allowing.
 *
 * @return (resource) the resulting gs image resource
 **/
function setheight( &$image, $args, $upscale = false ) {
	$w = $image->getimagewidth();
	$h = $image->getimageheight();
	
	if ( substr( $args, -1 ) == '%' )
		$new_height = round( $h * abs( intval( $args ) ) / 100 );
	else
		$new_height = intval( $args );

	// New height can't be calculated, then bail 
	if ( ! $new_height )
		return;
	// New height is greater than original image, but we don't have permission to upscale
	if ( $new_height > $h && ! $upscale )
		return;
	// Sane limit when upscaling, defaults to 1000
	if ( $new_height > $h && $upscale && $new_height > PHOTON__UPSCALE_MAX_PIXELS ) 
		return;

	$ratio = $h / $new_height;

	$new_w = round( $w / $ratio );
	$new_h = round( $h / $ratio );
	$s_x = $s_y = 0;
	
	$image->resizeimage( $new_w, $new_h, Gmagick::FILTER_UNDEFINED, 1 );
}

/**
 * setwidth - ( "w" function via the uri ) - resize the image to an explicit width, maintaining its aspect ratio
 *
 * @param (resource)image the source gd image resource
 * @param (string)args "/^[0-9]+%?$/" the new width in pixels, or as a percentage if suffixed with an %
 * @param boolean $upscale Whether to allow upscaling or not, defaults to not allowing.
 *
 * @return (resource) the resulting gs image resource
 **/
function setwidth( &$image, $args, $upscale = false ) {
	$w = $image->getimagewidth();
	$h = $image->getimageheight();
	
	if ( substr( $args, -1 ) == '%' )
		$new_width = round( $w * abs( intval( $args ) ) / 100 );
	else
		$new_width = intval( $args );

	// New width can't be calculated, then bail 
	if ( ! $new_width )
		return;
	// New height is greater than original image, but we don't have permission to upscale
	if ( $new_width > $w && ! $upscale )
		return;
	// Sane limit when upscaling, defaults to 1000
	if ( $new_width > $w && $upscale && $new_width > PHOTON__UPSCALE_MAX_PIXELS ) 
		return;

	$ratio = $w / $new_width;

	$new_w = round( $w / $ratio );
	$new_h = round( $h / $ratio );
	$s_x = $s_y = 0;

	$image->resizeimage( $new_w, $new_h, Gmagick::FILTER_UNDEFINED, 1 );
}

/**
 * fit_in_box - ( "fit" function via the uri ) - resize the image to fit it in the dimensions provided, maintaining its aspect ratio
 *
 * @param (resource)image the source gd image resource
 * @param (string)args "(int),(int)" width and height of the box
 *
 * @return (resource) the resulting gs image resource
 **/
function fit_in_box( &$image, $args ) {
	gmagick_to_gd( $image );
	$w = imagesx( $image );
	$h = imagesy( $image );

	list( $width, $height ) = array_map( 'abs', array_map( 'intval', explode( ',', $args ) ) );
	if ( ( $w == $width && $h == $height ) ||
		! $width || ! $height || ( $w < $width && $h < $height )
	) {
		gd_to_gmagick( $image );
		return;
	}

	$width_constraint  = $w / $width;
	$height_constraint = $h / $height;

	$ratio = max( $w / $width, $h / $height );

	$new_w = round( $w / $ratio );
	$new_h = round( $h / $ratio );

	// Make sure rounding didn't round up and above the requested values
	if ( $new_w > $width )
		 $new_w = $width;
	if ( $new_h > $height )
		 $new_h = $height;

	$s_x = $s_y = 0;

	// Preserve transparency	
	$orig_transparent = imagecolortransparent( $image );
	if ( $orig_transparent >= 0 ) {
		$new_i = imagecreate( $new_w, $new_h );
		$trans_color = imagecolorsforindex($image, $orig_transparent);
		$new_transparent = imagecolorallocate($new_i, $trans_color['red'], $trans_color['green'], $trans_color['blue']);
		imagefill($new_i, 0, 0, $new_transparent);
		imagecolortransparent($new_i, $new_transparent);
	} else {
		$new_i = imagecreatetruecolor( $new_w, $new_h );
	}
	
	imagecopyresampled( $new_i, $image, 0, 0, $s_x, $s_y, $new_w, $new_h, $w, $h );
	$image = $new_i;
	gd_to_gmagick( $image );
}

/**
 * resize_and_crop - ("resize" function via the uri) - originally by Alex M.
 *
 * Differs from setwidth, setheight, and crop in that you provide a width/height and it resizes to that and then crops off excess
 *
 * @param (resource) image the source gd image resource
 * @param (string) args "w,h" width,height in pixels
 *
 * @return (resource)image the resulting image gd resource
 *
 **/
function resize_and_crop( &$image, $args ) {
	$w = $image->getimagewidth();
	$h = $image->getimageheight();

	list( $end_w, $end_h ) = explode( ',', $args );

	$end_w = (int) $end_w;
	$end_h = (int) $end_h;

	if ( 0 == $end_w || 0 == $end_h )
		return;

	$ratio_orig = $w / $h;
	$ratio_end = $end_w / $end_h;

	// If the original and new images are proportional (no cropping needed), just do a standard resize
	if ( $ratio_orig == $ratio_end )
		setwidth( $image, $end_w, true );

	// If we need to crop off the sides
	elseif ( $ratio_orig > $ratio_end ) {
		setheight( $image, $end_h, true );
		$x = floor( ( $image->getimagewidth() - $end_w ) / 2 );
		crop( $image, "{$x}px,0px,{$end_w}px,{$end_h}px" );
	}

	// If we need to crop off the top/bottom
	elseif ( $ratio_orig < $ratio_end ) {
		setwidth( $image, $end_w, true );
		$y = floor( ( $image->getimageheight() - $end_h ) / 2 );
		crop( $image, "0px,{$y}px,{$end_w}px,{$end_h}px" );
	}

	return;
}

/**
 * unletterbox - ("ulb" function via the uri) - originally by Demitrious K.
 *
 * Removes black letterboxing bands from the top and bottom of an image
 *
 * $param (resource) img the source gd image resource
 * $param (string) args "" (the function takes no arguments)
 *
 * @return (resource)image the resulting image gd resource
 **/
function unletterbox( &$img, $args ) {
	gmagick_to_gd( $img );

	// rgb values averaged per pixel, and then those averaged for the entire row
	$max_value_considered_black = 3; 

	$width = imagesx( $img );
	$height = imagesy( $img );

	$first_nonblack_line = null;
	for( $h=0; $h < $height; $h++ ) {
		$line_value = 0;
		for( $w=0; $w < $width; $w++ ) {
			$rgb = imagecolorat( $img, $w, $h );
			$r = ( $rgb >> 16 ) & 0xFF;
			$g = ( $rgb >> 8 ) & 0xFF;
			$b = $rgb & 0xFF;
			$line_value += round( ( $r + $g + $b ) / 3 ); 
		}
		if ( round( $line_value/$width ) > $max_value_considered_black ) {
			$first_nonblack_line = $h + 1;
			break;
		}
	}
	if ( ! $first_nonblack_line ) {
		gd_to_gmagick( $img );
		return;
	}

	$last_nonblack_line = null;
	for( $h = $height - 1; $h >= 0; $h-- ) {
		$line_value = 0;
		for( $w=0; $w < $width; $w++ ) {
			$rgb = imagecolorat( $img, $w, $h );
			$r = ( $rgb >> 16 ) & 0xFF;
			$g = ( $rgb >> 8 ) & 0xFF;
			$b = $rgb & 0xFF;
			$line_value += round( ( $r + $g + $b ) / 3 ); 
		}
		if ( round( $line_value / $width ) > $max_value_considered_black ) {
			$last_nonblack_line = $h;
			break;
		}
	}
	if ( ! $last_nonblack_line || $last_nonblack_line <= $first_nonblack_line ) {
		gd_to_gmagick( $img );
		return;
	}

	$args = implode( ',',
		array(
			'0px',
			$first_nonblack_line . 'px',
			$width . 'px',
			( $last_nonblack_line - $first_nonblack_line ) . 'px',
		)
	);
	gd_to_gmagick( $img );
	crop( $img, $args );
}
// {{{ filter($image,$filter)
/**
 * filter - ("filter" via the uri) - originally by Alex M.
 *
 * Performs various filters on the image such as grayscale
 * This is only for filters that accept no args
 *
 * @param resource $image The source GD image resource
 * @param string $filter The filter name
 * @return resource The resulting GD imageresource
 **/
function filter( &$image, $filter ) {
	$args = explode( ',', $filter );
	$filter = array_shift( $args );
	gmagick_to_gd( $image );
	switch ( $filter ) {
		case 'negate':
			do_action( 'bump_stats', 'filter_negate' );
			imagefilter( $image, IMG_FILTER_NEGATE );
			break;
		case 'grayscale':
		case 'greyscale':
			do_action( 'bump_stats', 'filter_grayscale' );
			imagefilter( $image, IMG_FILTER_GRAYSCALE );
			break;
		case 'sepia':
			do_action( 'bump_stats', 'filter_sepia' );
			imagefilter( $image, IMG_FILTER_GRAYSCALE );
			imagefilter( $image, IMG_FILTER_COLORIZE, 90, 60, 40 );
			break;
		case 'edgedetect':
			do_action( 'bump_stats', 'filter_edgedetect' );
			imagefilter( $image, IMG_FILTER_EDGEDETECT );
			break;
		case 'emboss':
			do_action( 'bump_stats', 'filter_emboss' );
			imagefilter( $image, IMG_FILTER_EMBOSS );
			break;
		case 'blurgaussian':
			do_action( 'bump_stats', 'filter_blurgaussian' );
			imagefilter( $image, IMG_FILTER_GAUSSIAN_BLUR );
			break;
		case 'blurselective':
			do_action( 'bump_stats', 'filter_blurselective' );
			imagefilter( $image, IMG_FILTER_SELECTIVE_BLUR );
			break;
		case 'meanremoval':
			do_action( 'bump_stats', 'filter_meanremoval' );
			imagefilter( $image, IMG_FILTER_MEAN_REMOVAL );
			break;
	}
	gd_to_gmagick( $image );
}
// }}}
/**
 * brightness - ("brightness" via the uri) - originally by Alex M.
 *
 * Adjusts image brightness (-255 through 255)
 *
 * @param resource $original The source GD image resource
 * @param resource $brightness The brightness adjustment value
 * @return resource The resulting GD imageresource
 **/
function brightness( &$image, $brightness ) {
	$image->modulateimage( (float) $brightness, null, null );
}

/**
 * contrast - ("contrast" via the uri) - originally by Alex M.
 *
 * Adjusts image contrast (-100 through 100)
 *
 * @param resource $original The source GD image resource
 * @param resource $contrast The contrast adjustment value
 * @return resource The resulting GD imageresource
 **/
function contrast( &$image, $contrast ) {
	$contrast = (int) $contrast;

	gmagick_to_gd( $image );
	imagefilter( $image, IMG_FILTER_BRIGHTNESS, $contrast * -1 ); // Make +value increase contrast
	gd_to_gmagick( $image );
}

/**
 * colorize - ("colorize" via the uri) - originally by Alex M.
 *
 * Hues the image to a certain color:  red,green,blue
 *
 * @param resource $original The source GD image resource
 * @param resource $colors A comma seperated rgb value (255,255,255 = white)
 * @return resource The resulting GD imageresource
 **/
function colorize( &$image, $colors ) {
	$colors = explode( ',', $colors );
	$color = array_map( 'intval', $colors );

	$red   = ( !empty($color[0]) ) ? $color[0] : 0;
	$green = ( !empty($color[1]) ) ? $color[1] : 0;
	$blue  = ( !empty($color[2]) ) ? $color[2] : 0;
	
	gmagick_to_gd( $image );
	imagefilter( $image, IMG_FILTER_COLORIZE, $red, $green, $blue );
	gd_to_gmagick( $image );
}

/**
 * smooth - ("smooth" via the uri) - originally by Alex M.
 *
 * Adjusts image smoothness
 *
 * @param resource $original The source GD image resource
 * @param resource $smoothness The smoothness adjustment value
 * @return resource The resulting GD imageresource
 **/
function smooth( &$image, $smoothness ) {
	gmagick_to_gd( $image );
	imagefilter( $image, IMG_FILTER_SMOOTH, (float) $smoothness );
	gd_to_gmagick( $image );
}

function httpdie( $code='404 Not Found', $message='Error: 404 Not Found' ) {
	$numerical_error_code = preg_replace( '/[^\\d]/', '', $code );
	do_action( 'bump_stats', "http_error-$numerical_error_code" );
	header( 'HTTP/1.1 ' . $code );
	die( $message );
}

function gmagick_to_gd( &$image ) {
	global $type;
	if ( $type == "JPEG" )
		$image->setcompressionquality( 100 );
	$image = imagecreatefromstring( $image->getimageblob() );
}

function gd_to_gmagick( &$image ) {
	global $type;
	ob_start();
	switch( strtolower( $type ) ) {
		case 'gif':
			imagegif( $image, null );
			break;
		case 'png':
			imagepng( $image, null, 0 );
			break;
		default:
			imagejpeg( $image, null, 100 );
			break;
	}
	$image = new Gmagick();
	$image->readimageblob( ob_get_clean() );
}

function fetch_raw_data( $url, $timeout=5, $connect_timeout=2 ) {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout );
	curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
	curl_setopt( $ch, CURLOPT_SSLVERSION, 3 );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $ch, CURLOPT_MAXREDIRS, 3 );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'Photon/1.0' );
	return curl_exec( $ch );
}

function do_a_filter( $function_name, $arguments ) {
	global $image, $allowed_functions;

	if ( ! isset( $allowed_functions[$function_name] ) )
		return;

	$function_name = $allowed_functions[$function_name];
	if ( function_exists( $function_name ) && is_callable( $function_name ) ) {
		do_action( 'bump_stats', $function_name );
		$arguments = apply_filters( 'arguments', $arguments, $function_name );
		$function_name( $image, $arguments );
	}
}

function photon_cache_headers( $expires=63115200 ) {
	header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $expires ) . ' GMT' );
	header( 'Cache-Control: public, max-age='.$expires );
	header( 'X-Content-Type-Options: nosniff' );
	}

$image = new Gmagick();

$parsed = parse_url( $_SERVER['REQUEST_URI'] );
$exploded = explode( '/', $_SERVER['REQUEST_URI'] );
$origin_domain = strtolower( $exploded[1] );
$origin_domain_exception = array_key_exists( $origin_domain, $origin_domain_exceptions ) ? $origin_domain_exceptions[$origin_domain] : 0;

$scheme = 'http' . ( array_key_exists( 'ssl', $_GET ) ? 's' : '' ) . '://';
parse_str( ( empty( $parsed['query'] ) ? '' : $parsed['query'] ),  $_GET  );

$ext = strtolower( pathinfo( $parsed['path'], PATHINFO_EXTENSION ) );

if ( ! in_array( $ext, $allowed_types ) && !( $origin_domain_exception & PHOTON__ALLOW_ANY_EXTENSION ) )
	httpdie( '400 Bad Request', 'The type of image you are trying to process is not allowed' );

$url = $scheme . substr( $parsed['path'], 1 );
$url = preg_replace( '/#.*$/', '', $url );
$url = apply_filters( 'url', $url );

if ( isset( $_GET['q'] ) ) {
	if ( $origin_domain_exception & PHOTON__ALLOW_QUERY_STRINGS ) {
		$url .= '?' . preg_replace( '/#.*$/', '', (string) $_GET['q'] );
		unset( $_GET['q'] );
	} else {
		httpdie( '400 Bad Request', "Sorry, the parameters you provided were not valid" );
	}
}

if ( false === filter_var( $url, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED ) )
	httpdie( '400 Bad Request', "Sorry, the parameters you provided were not valid" );

// Uncomment to automatically unletterbox all ytimg.com images
//if ( false !== strpos( $url, '.ytimg.com/' ) )
//	$_GET['ulb'] = '';
$raw = fetch_raw_data( $url );
if ( empty( $raw ) )
	httpdie( '504 Gateway Timeout', 'We cannot complete this request, remote data could not be fetched' );

try {
	$image->readimageblob( $raw );
	$type = $image->getimageformat();
} catch ( GmagickException $e ) {
	httpdie( '400 Bad Request', 'We cannot complete this request, remote data was invalid' );
}

if ( !in_array( strtolower( $type ), $allowed_types ) )
	httpdie( '400 Bad Request', 'The type of image you are trying to process is not allowed' );

if ( $type == 'JPEG' )
	$quality = get_jpeg_quality( $raw );
else
	$quality = 90;
unset( $raw );

try {
	// Run through all uri supplied functions which are valid and allowed
	foreach( $_GET as $function_name => $arguments ) {
		if ( !is_array( $arguments ) )
			$arguments = array( $arguments );
		foreach ( $arguments as $argument ) {
			if ( $argument === apply_filters( $function_name, $argument ) )
				do_a_filter( $function_name, $argument );
		}
	}

	switch ( strtolower( $image->getimageformat() ) ) {
		case 'png': 
			do_action( 'bump_stats', 'image_png' );
			header( 'Content-Type: image/png' );
			$image->setcompressionquality( $quality );
			$tmp = tempnam( $tmpdir, 'OPTIPNG-' );
			$image->write( $tmp );
			$og = filesize( $tmp );
			exec( OPTIPNG . " $tmp" );
			clearstatcache();
			$save = $og - filesize( $tmp );
			do_action( 'bump_stats', 'png_bytes_saved', $save );
			$fp = fopen( $tmp, 'r' );
			photon_cache_headers();
			header( 'Content-Length: ' . filesize( $tmp ) );
			header( 'X-Bytes-Saved: ' . $save );
			unlink( $tmp );
			fpassthru( $fp );
			break;
		case 'gif': 
			do_action( 'bump_stats', 'image_gif' );
			header( 'Content-Type: image/gif' );
			$image->setcompressionquality( $quality );
			photon_cache_headers();
			echo $image->getimageblob();
			break;
		default: 
			do_action( 'bump_stats', 'image_jpeg' );
			header( 'Content-Type: image/jpeg' );
			$image->setcompressionquality( $quality );
			$tmp = tempnam( $tmpdir, 'JPEGOPTIM-' );
			$image->write( $tmp );
			$og = filesize( $tmp );
			exec( JPEGOPTIM . " -p $tmp" );
			clearstatcache();
			$save = $og - filesize( $tmp );
			do_action( 'bump_stats', 'jpg_bytes_saved', $save );
			$fp = fopen( $tmp, 'r' );
			photon_cache_headers();
			header( 'Content-Length: ' . filesize( $tmp ) );
			header( 'X-Bytes-Saved: ' . $save );
			unlink( $tmp );
			fpassthru( $fp );
			break ;
	}

} catch ( GmagickException $e ) {
	httpdie( '400 Bad Request', "Sorry, the parameters you provided were not valid" );
}

