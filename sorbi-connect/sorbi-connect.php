<?php
/*
  Plugin Name: SORBI Connect
  Plugin URI: http://www.sorbi.com
  Description: Connect your website to the SORBI network
  Author: Yoeri Dekker
  Author URI: http://www.csorbamedia.com/
  Text Domain: sorbi-connect
  Version: 1.0.0
*/

// define global variables
define("SORBI_PATH",plugin_dir_path(__FILE__) );
define("SORBI_URL",	plugin_dir_url(__FILE__) );
define("SORBI_TD",	"sorbi-connect");

// require the sorbi API class
require_once( SORBI_PATH . 'lib/sorbi.class.php' );

// init the SorbiConnect class
$sorbi = new SorbiConnect();

// SORBI API
function sorbi_endpoint($request){
	
	$sorbi = new SorbiConnect();
	
	// Get our data from the request
	$return = array();
	$return['site_key'] = wp_kses_data($request['site_key']);
	$return['site_secret'] = wp_kses_data($request['site_secret']);
	
	// Send data to SORBI
	$sorbi->scan();
	$file_changes = $sorbi->check_file_changes();
	
	// try to get all the versions
	$versions = $sorbi->list_versions();
	
	// Set our Args for external call with SORBI
	$args['site_key'] 		= wp_kses_data($request['site_key']);
	$args['site_secret'] 	= wp_kses_data($request['site_secret']);
	$args['platform'] 		= 'wordpress';
	
	// If we have core, plugins or theme information we need to push the information to SORBI
	if($versions){
	
		$args['versions'] 		= (array) $versions;
		
		// call the SORBI API
		$version_call = $sorbi->sorbi_api_call( 'versions', $args, 'POST', true );
				
		// loop the results
		if( $version_call && isset( $version_call->summary ) && count( $version_call->summary ) > 0 ){
			
			$return['success']['data'] = $version_call->summary;
			
			// define the expiration in seconds
			$site_key_expiration = (int) $version_call->valid_until;
			$site_key = $version_call->site_key;
			
			$messages['success'][] = sprintf( __("Your SORBI site key '{$site_key}' is actived until %s (last check %s)", SORBI_TD ), date( $sorbi->datetimeformat, $site_key_expiration ), date( $sorbi->datetimeformat, time() ) );
			
			$return['success']['valid_until'] = date( $sorbi->datetimeformat, $site_key_expiration );
				
			// update the expiration date
			update_option( 'sorbi_site_key_expiration' , $site_key_expiration );
			
			// save the messages 
			update_option( 'sorbi_messages', (array) $messages );
			
			
		}
	
	}
	
	// If core files have been changed, we need to push the information to SORBI
	if($file_changes){
		//$return['core_integrity'] = $file_changes;
	}
	
	return $return;
	
}