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
			$return['success']['updates'] = $version_call->summary;
		}
	
	}
	
	// If core files have been changed, we need to push the information to SORBI
	if($file_changes){
		$return['core_integrity'] = $file_changes;
	}
	
	return $return;
	
}