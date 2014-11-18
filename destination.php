<?php
/**
 * upload.php
 *
 * Copyright 2009, Moxiecode Systems AB
 * Released under GPL License.
 *
 * License: http://www.plupload.com/license
 * Contributing: http://www.plupload.com/contributing
 */

if( empty( $_SERVER['HTTP_REFERER'] ) )
    die();

global $feu_destination_dir;
global $feu_destination_url;
// global $front_end_upload;

// HTTP headers for no cache etc
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// we need these WP files to grab our destination dir
ob_start();
$wpenv = preg_replace( "/wp-content.*/", "wp-load.php", __FILE__ );
$wpfile = str_replace( "wp-load.php", "wp-admin" . DIRECTORY_SEPARATOR . "includes" . DIRECTORY_SEPARATOR . "file.php", $wpenv );
require_once( $wpenv );
require_once( $wpfile );
ob_end_clean();

// cleanup potential unwanted dir
$unwanted = dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'FEU_DESTINATION_DIR';
if( file_exists( $unwanted ) )
{
	rename( $unwanted, uniqid( $unwanted ) );
}


function feu_parse_request_headers()
{
    $headers = array();
    foreach($_SERVER as $key => $value) {
        if (substr($key, 0, 5) <> 'HTTP_') {
            continue;
        }
        $header             = str_replace( ' ', '-', str_replace( '_', ' ', strtolower( substr( $key, 5) ) ) );
        $headers[$header]   = $value;
    }
    return $headers;
}

$headers = feu_parse_request_headers();
if( empty( $headers['hash'] ) || empty( $headers['feu'] ) )
{
    die();
}
$hash   = sanitize_text_field( $headers['hash'] );
$feu    = sanitize_text_field( $headers['feu'] );

if( !$hash_transient = get_transient( $hash ) )
    die();

$salt = get_option( '_feufilesalt' );

if( empty( $salt ) )
    die();

if( !$transient = get_transient( 'feuupload_' . $feu ) )
    die();

$local_test = sha1( $salt . $feu . $_SERVER['REMOTE_ADDR'] );
if( $local_test !== $transient )
    die();


if( empty( $_SERVER['HTTP_REFERER'] ) || false === get_transient( 'feu_referer_' . md5( $_SERVER['HTTP_REFERER'] . $hash . $salt ) ) )
    die();


// Settings
$targetDir = $feu_destination_dir;

if( empty( $targetDir ) )
{
    // plugin isn't active
    die();
}

// 5 minutes execution time
@set_time_limit(5 * 60);


// Get parameters
$chunk = isset($_REQUEST["chunk"]) ? $_REQUEST["chunk"] : 0;
$chunks = isset($_REQUEST["chunks"]) ? $_REQUEST["chunks"] : 0;
$fileName = isset($_REQUEST["name"]) ? $_REQUEST["name"] : '';

// Clean the fileName for security reasons
$fileName = preg_replace('/[^\w\._]+/', '', $fileName);

// check the extension
$extensions = array( 'jpg', 'jpeg', 'gif', 'png', 'pdf', 'doc', 'docx', 'xls', 'txt', 'rtf', 'zip' );

$settings = get_option( FEU_PREFIX . 'settings' );
if( !empty( $settings['custom_file_extensions'] ) )
{
    $custom_extensions = explode( ',', $settings['custom_file_extensions']);
    foreach( $custom_extensions as $ext )
        $extensions[] = str_replace( '.', '', trim( $ext ) );
}

// does it contain .php?
if( strpos( strtolower( $fileName ), '.php' ) !== false )
    die();

// does it end with .php?
if( strtolower( substr( $fileName, -4 ) ) == '.php' )
    die();

// is it in the whitelist of file
if( !in_array( substr( $fileName, -3 ), $extensions ) && !in_array( substr( $fileName, -4 ), $extensions ) )
    die();






// Make sure the fileName is unique but only if chunking is disabled
if ($chunks < 2 && file_exists($targetDir . DIRECTORY_SEPARATOR . $fileName)) {
	$ext = strrpos($fileName, '.');
	$fileName_a = substr($fileName, 0, $ext);
	$fileName_b = substr($fileName, $ext);

	$count = 1;
	while (file_exists($targetDir . DIRECTORY_SEPARATOR . $fileName_a . '_' . $count . $fileName_b))
		$count++;

	$fileName = $fileName_a . '_' . $count . $fileName_b;
}

$filePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

// Create target dir
if (!file_exists($targetDir))
	@mkdir($targetDir);

// Look for the content type header
if (isset($_SERVER["HTTP_CONTENT_TYPE"]))
	$contentType = $_SERVER["HTTP_CONTENT_TYPE"];

if (isset($_SERVER["CONTENT_TYPE"]))
	$contentType = $_SERVER["CONTENT_TYPE"];

// Handle non multipart uploads older WebKit versions didn't support multipart in HTML5
if (strpos($contentType, "multipart") !== false) {
	if (isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
		// Open temp file
		$out = fopen("{$filePath}.part", $chunk == 0 ? "wb" : "ab");
		if ($out) {
			// Read binary input stream and append it to temp file
			$in = fopen($_FILES['file']['tmp_name'], "rb");

			if ($in) {
				while ($buff = fread($in, 4096))
					fwrite($out, $buff);
			} else
				die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
			fclose($in);
			fclose($out);
			@unlink($_FILES['file']['tmp_name']);
		} else
			die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}');
	} else
		die('{"jsonrpc" : "2.0", "error" : {"code": 103, "message": "Failed to move uploaded file."}, "id" : "id"}');
} else {
	// Open temp file
	$out = fopen("{$filePath}.part", $chunk == 0 ? "wb" : "ab");
	if ($out) {
		// Read binary input stream and append it to temp file
		$in = fopen("php://input", "rb");

		if ($in) {
			while ($buff = fread($in, 4096))
				fwrite($out, $buff);
		} else
			die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');

		fclose($in);
		fclose($out);
	} else
		die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}');
}

$feuidhash = 0;

// Check if file has been uploaded
if (!$chunks || $chunk == $chunks - 1) {

    // obscure the file within the filesystem
    $fileFlag           = uniqid( 'FEU' . md5( $fileName ) );
    $filePathObscured   = $targetDir . DIRECTORY_SEPARATOR . $fileFlag;

    // store the original file name for reference later
    $file_record = array(
        'post_title'    => $fileFlag,
        'post_type'     => 'feu_file',
        'post_status'   => 'private',
    );
    $cpt_id = wp_insert_post( $file_record );

    // add our post meta
    add_post_meta( $cpt_id, 'feu_fileflag', $fileFlag );
    add_post_meta( $cpt_id, 'feu_filename', $fileName );
    add_post_meta( $cpt_id, 'feu_filepath', $filePathObscured );

    // tack on our hash
    $feuidhash = uniqid( md5( $filePathObscured . time() ) );
    add_post_meta( $cpt_id, 'feu_idhash',   $feuidhash );

    // rename it to obscurity
    rename( "{$filePath}.part", $filePathObscured );
}

$final_hash = $feuidhash;
die( $final_hash );
