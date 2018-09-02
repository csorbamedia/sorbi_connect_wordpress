<?php
/*
  Plugin Name: SORBI Connect
  Plugin URI: http://www.sorbi.com
  Description: Connect your website to the SORBI network
  Author: Yoeri Dekker
  Author URI: http://www.csorbamedia.com/
  Text Domain: sorbi-connect
  Version: 1.0.1
*/

// define global variables
define('DONOTCACHEPAGE', true);
define('SORBI_PLUGIN_FILE', __FILE__);
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
	
	// We need to check is site_key and site_secret is in database also
	$registered_site_key = get_option( 'sorbi_site_key' , false );
	$registered_site_secret = get_option( 'sorbi_site_secret' , false );

	// Get our data from the request
	$return = array();
	$return['site_key'] = wp_kses_data($request['site_key']);
	$return['site_secret'] = wp_kses_data($request['site_secret']);
		
	// If it does not match with what is in the database of the website
	if($return['site_key'] != $registered_site_key || $return['site_secret'] != $registered_site_secret){
		return false;
	}
	
	// try to get all the versions
	$versions = $sorbi->list_versions();
		
	// System information
	$system = $sorbi->system_info();
		
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
						
			$site_key = $version_call->site_key;
			
			$messages['success'][] = sprintf( __("Your SORBI site key '{$site_key}' is actived.", SORBI_TD ) );
			
			// save the messages 
			update_option( 'sorbi_messages', (array) $messages );
			
		}
		
	}
	
	if($system){
		// call the SORBI API to send Server system information
		$system['extensions']			= json_encode($system['extensions']);
		$system_args['site_key'] 		= wp_kses_data($request['site_key']);
		$system_args['site_secret'] 	= wp_kses_data($request['site_secret']);
		$system_args['report_key'] 		= wp_kses_data($request['report_key']);
		$system_args['platform'] 		= 'wordpress';
		$system_args['servers'] 		= $system;
		$version_call 					= $sorbi->sorbi_api_call( 'servers', $system_args, 'POST', true );
	}
	
	return $return;
	
}
