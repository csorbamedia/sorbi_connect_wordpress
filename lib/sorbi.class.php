<?php 

class SorbiConnect{
	
	// public variables
	public $messages							= array();
	public $notices								= array();
	public $uploaddir							= array();
	public $dateformat							= 'Y-m-d';
	public $timeformat							= 'H:i:s';
	
	// api 
	protected $version							= 'v1';
	protected $api_uri							= 'https://api.sorbi.com/%s/%s';
	protected $timeout							= 30;
	
	// protected variables
	protected $platform 						= 'wordpress';
	protected $pagename 						= 'sorbi-connect';
	
	// options names
	protected $sorbi_message_option_name		= 'sorbi_messages';
	protected $sorbi_notices_option_name		= 'sorbi_notices';
	protected $sorbi_options_group				= 'sorbi_options';
	protected $sorbi_options_section			= 'sorbi_option_section';
	protected $site_key_option_name 			= 'sorbi_site_key';
	protected $site_key_expiration_option_name 	= 'sorbi_site_key_expiration';
	protected $site_secret_option_name 			= 'sorbi_site_secret';
	
	
	protected $sorbi_files_option_name			= 'sorbi_filechanges';
	protected $sorbi_last_file_chec_option_name	= 'sorbi_last_file_check_time';
	
	public function __construct(){
		
		// overwrite date format 
		$this->dateformat = get_option('date_format', 'Y-m-d');
		
		// overwrite time format 
		$this->timeformat = get_option('time_format', 'H:i:s');
		
		// set date time format 
		$this->datetimeformat = "{$this->dateformat} {$this->timeformat}";
		
		// define upload dir 
		$this->uploaddir = wp_upload_dir();
		
		// get the site key 
		$this->site_key = get_option( $this->site_key_option_name, false );
		
		// site key expiration
		$this->site_key_expiration = get_option( $this->site_key_expiration_option_name, false );
		
		// site secret
		$this->site_secret = get_option( $this->site_secret_option_name , false );
		
		// get the messages 
		$this->messages = get_option( $this->sorbi_message_option_name, array() );
		
		// get the notices 
		$this->notices = get_option( $this->sorbi_notices_option_name, array() );
		
		// get system info 
		$this->system_info();
		
		// add the wp admin init listener 
		add_action( 'init', array( $this, 'init' ) );
		
		// add the wp admin init listener 
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		
		// add the menu page 
		add_action( 'admin_menu', array( $this, 'menu_page' ) );
	
		// add the notices if we have them
		add_action( 'admin_notices', array( $this, 'sorbi_notify' ) );
		
		// add the meta tag for validation ot the on the website 
		add_action( 'wp_head', array( $this, 'sorbi_meta_tag' ) );
		
		// add the meta tag for validation ot the on the website 
		add_action( 'admin_head', array( $this, 'sorbi_backend_head' ) );
		
		// add a listener for the upgrades
		add_filter( 'upgrader_post_install', array( $this, 'after_update' ), 5, 3);
		
		// add action for the finished upgrader process
		add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );
		
		// listener for plugin activation
		add_action( 'activated_plugin', array( $this, 'after_update' ), 10, 2 );
		
		// listener for plugin de-activation
		add_action( 'deactivated_plugin', array( $this, 'after_update' ), 10, 2 );
		
		// listener for theme switch
		add_action( 'after_switch_theme', array( $this, 'after_update' ) );
		
		// add rewrite
		add_filter( 'query_vars', array( $this, 'sorbi_sync_rewrite_add_var' ) );
		
		// get current screen 
		add_action( 'current_screen', array( $this, 'get_current_screen' ) );
		
		// Add SORBI listener for WP < 4.4
		add_action( 'init', array( $this, 'sorbi_api_endpoint_v44' ) );
		
		// Add API listener for WP > 4.4
		add_action( 'rest_api_init', array( $this, 'sorbi_api_endpoint_v45' ) );
		
		// register the unistall hook, static for WP > 4.4
		register_uninstall_hook( SORBI_PLUGIN_FILE, 'self::uninstall' );
		
	}
	
	// uninstall function
	static function uninstall(){
		update_option('sorbi_unistall', time() );
	}
	
	public function activation(){
		// flush the rewrite rules
		flush_rewrite_rules();
	}
	
	public function deactivation(){
		// flush the rewrite rules
		flush_rewrite_rules();
	}
	
	// get the systme info 
	public function system_info(){
		
		// debug
		$system = array();
		
		// get os, machine, php version and loaded extensions
		$system['os'] 				= php_uname('s');
		$system['machine'] 			= php_uname('m');
		$system['php'] 				= phpversion();
		$system['extensions']		= $this->get_php_extensions();
		$system['webserver']		= $_SERVER['SERVER_SOFTWARE'] != '' ? $_SERVER['SERVER_SOFTWARE'] : $_SERVER['SERVER_SIGNATURE'];
		$system['memory_limit']		= ini_get('memory_limit');
		$system['zip']				= (!extension_loaded('zip')) ? false : true;
		
		// system vars 
		$system['allow_url_fopen']    = (int) ini_get('allow_url_fopen') === 1 ? true : false ;
		$system['max_execution_time'] = (int) ini_get('max_execution_time');
		
		return $system;
		
	}
	
	// get the current plugin screen
	public function get_current_screen(){
		// debug
		$this->debug();
		
		// get the current screen 
		$this->screen = get_current_screen(); 
		
	}
	
	// backup all tables in db
	public function mysql_backup(){
		
		// set the sql content
		$sql = '';
		
		// debug
		$this->debug();
		
		// set defaults
		$date 			= date("Y-m-d");
		$filename		= "{$this->site_key}-{$date}-backup.sql";
		$zipfile		= "{$this->site_key}-{$date}-database-backup.zip";
		$backup_file 	= "{$this->uploaddir['basedir']}/{$this->folder}/{$zipfile}";
		$backup_url 	= "{$this->uploaddir['baseurl']}/{$this->folder}/{$zipfile}";
		
		// we already have a backup for today
		if( file_exists( $backup_file ) && is_readable( $backup_file ) ){
			return $backup_url;
		}

		//connect to db
		$link = mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD );
		mysqli_set_charset( $link, 'utf8');
		mysqli_select_db( $link, DB_NAME);

		// get all of the tables
		$tables = array();
		$result = mysqli_query( $link, 'SHOW TABLES' );
		while( $row = mysqli_fetch_row( $result ) ) $tables[] = $row[0];

		// disable foreign keys (to avoid errors)
		$sql.= 'SET FOREIGN_KEY_CHECKS=0;' . PHP_EOL;
		$sql.= 'SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";' . PHP_EOL;
		$sql.= 'SET AUTOCOMMIT=0;' . PHP_EOL;
		$sql.= 'START TRANSACTION;' . PHP_EOL;

		// cycle through
		foreach($tables as $table){
			
			$result 	= mysqli_query( $link, 'SELECT * FROM ' . $table );
			$num_fields = (int) mysqli_num_fields( $result );
			$num_rows 	= (int) mysqli_num_rows( $result );
			$i_row 		= 0;

			//$sql.= 'DROP TABLE '.$table.';'; 
			$row2 = mysqli_fetch_row( mysqli_query( $link, 'SHOW CREATE TABLE '.$table ) );
			$sql.= PHP_EOL . PHP_EOL . $row2[1] . PHP_EOL; 

			if ( $num_rows !== 0 ) {
				$row3 = mysqli_fetch_fields( $result );
				$sql.= 'INSERT INTO '.$table.'( ';
				foreach ($row3 as $th) { 
					$sql.= '`'.$th->name.'`, ';
				}
				$sql = substr($sql, 0, -2);
				$sql.= ' ) VALUES';

				for ( $i = 0; $i < $num_fields; $i++ ) {
					while( $row = mysqli_fetch_row( $result ) ){
						$sql.= PHP_EOL . "(";
						for( $j = 0; $j < $num_fields; $j++ ){
							$row[$j] = addslashes( $row[$j] );
							$row[$j] = preg_replace("#\n#","\\n", $row[$j] );
							if ( isset($row[$j]) ) {
								$sql.= '"'.$row[$j].'"' ;
							} else { 
								$sql.= '""';
							}
							if ( $j < ($num_fields-1) ) { 
								$sql.= ',';
							}
						}
						if ( $num_rows === $i_row++ ) {
							$sql.= ");"; // last row
						} else {
							$sql.= "),"; // not last row
						}   
					}
				}
			}
			$sql.= PHP_EOL;
		}

		// enable foreign keys
		$sql.= 'SET FOREIGN_KEY_CHECKS=1;' . PHP_EOL;
		$sql.= 'COMMIT;';
		
		// create zip archive 
		return $this->create_zip( $filename, $sql, $zipfile );
	}
	
	// creates a compressed zip file
	public function create_zip( $filename, $sql, $zipfile ) {
		
		// debug
		$this->debug();
		
		// zip names
		$date 			= date("Y-m-d");
		$backup_file 	= "{$this->uploaddir['basedir']}/{$this->folder}/{$zipfile}";
		$backup_url 	= "{$this->uploaddir['baseurl']}/{$this->folder}/{$zipfile}";

		// now try to create the file
		$zip = new ZipArchive;
		$res = $zip->open( $backup_file, ZipArchive::CREATE );
		if ( $res === true ) {
			if( !$zip->addFromString( $filename, $sql ) ) return false;
			$zip->close();
			return file_exists( $backup_file ) ? $backup_url : false ;
		}
		return false;
	}
	
	// register the SORBI external request
	public function sorbi_sync_rewrite_add_var( $vars ){
		// debug
		$this->debug();
		
		$vars[] = $this->sync_rewrite_var;
		return $vars;
	}

	// get active php extensions
	private function get_php_extensions(){
		// debug
		$this->debug();
		
		$extensions = array();
		foreach ( get_loaded_extensions() as $ext) { 
			$extensions[ $ext ] = strtolower( trim( $ext ) ); 
		}
		return $extensions;
	}
	
	// get the current php version
	private function get_php_version(){
		// debug
		$this->debug();
		
		if ( !defined('PHP_VERSION_ID') ) {
			$version = explode('.', PHP_VERSION );
			return ($version[0] * 10000 + $version[1] * 100 + $version[2] );
		}
		return PHP_VERSION_ID;
	}
	
	/**
	 * Recursive scan of all directories
	 *
	 * @return void
	 **/
	public function scan_dir( $dirname, $recursive = true ){
		// debug
		$this->debug();
		
		if( !in_array( $dirname, $this->dirs ) ) $this->dirs[] = $dirname;
		$path = $dirname . '*';
		foreach( glob( $path, GLOB_ONLYDIR ) as $dir ) {
			if( !in_array( $dir, $this->dirs ) && $recursive ) $this->scan_dir( $dir . DIRECTORY_SEPARATOR, $recursive );
		}
	}
	
	public function scan_files( $dir ){
		// debug
		$this->debug();
		
		$path 		= $dir . '*';
		$dirname = str_replace( ABSPATH, '', $dir );
		foreach( glob( $path ) as $filepath ) {
			$filetime 	= filemtime( $filepath );
			// clean the name
			$file	 	= str_replace( ABSPATH . $dirname , '', $filepath );
			if( !isset( $this->files[$dirname][$file] ) || !in_array( $filetime, $this->files[$dirname][$file] ) ){
				$this->files[$dirname][$file][] = $filetime;
			}
		}
	}
	
	public function scan(){
		// debug
		$this->debug();
		
		// first try json based file change history
		$this->files 	= false;
		$filename		= "{$this->sorbi_files_option_name}.json";
		$filepath 		= "{$this->uploaddir['basedir']}/{$this->folder}/{$filename}";
		
		// try to get json saved values
		if( $this->allow_url_fopen && is_file( $filepath ) && is_readable( $filepath ) ){
			
			// try to get the file content
			$json = file_get_contents( $filepath );
			
			// check if we have received the file
			if( $json ){
				
				// decode the json
				$decode = json_decode( $json, true );
				
				// if we have valid json, we set the files
				if( $decode ){
					$this->files = (array) $decode;
				}
			}
		}
		
		// we dont have the json, try the option 
		if( !$this->files ){
			$this->files = get_option( $this->sorbi_files_option_name , array() );
		}

		// set the empty dirs
		$this->dirs = array();
		
		// map the dirs
		$dirs = array(
			ABSPATH . 'wp-admin' . DIRECTORY_SEPARATOR ,
			ABSPATH . WPINC . DIRECTORY_SEPARATOR,
			WP_CONTENT_DIR . DIRECTORY_SEPARATOR,
		);
		
		foreach( $dirs as $dir ){
			$this->scan_dir( $dir );
		}
		/*
		$this->scan_dir( ABSPATH );
		*/
		
		// now we scan the files in every dir 
		foreach( $this->dirs as $dir ){
			$this->scan_files( $dir );
		}
		
		// try to write the json file
		$jsonfile = file_put_contents( $filepath, json_encode( $this->files ), LOCK_EX );
		
		// fallback to wp_option if we can not write
		if( !$jsonfile ){
			update_option( 'files', $this->files );
		}
		
	}
	
	public function check_file_changes(){
		// debug
		$this->debug();
		
		$now			= time();
		$changes		= array();
		$check_time 	= get_option( $this->sorbi_last_file_chec_option_name, $now );
		
		foreach( $this->files as $folder => $files ){
			foreach( $files as $file => $filetimes ){
				$last_change = max( $filetimes );
				
				if( $last_change > $check_time ){
					$changes[ $folder.$file ] = $last_change;
				}
			}
		}
		
		// now save the last check time 
		update_option( $option_name, $now );
		
		return $changes;
		
	}
	
	/**
	 * Check if we are on the wp-admin
	 *
	 * @return void
	 **/
	public function init(){
		
		// debug
		$this->debug();
		
		// check if the plugin is called from the /wp-admin/ site
		$this->is_admin = is_admin();
		
		// add the rewrite rule
		add_rewrite_rule(
			"^{$this->sync_rewrite}?$",
			"index.php?{$this->sync_rewrite_var}={$this->site_key}",
			'top'
		);
		
		// flush the rewrite rules
		flush_rewrite_rules();
		
	}
	
	/**
	 * Filter on the upgrade hook
	 * Pushes the versions to SORBI
	 *
	 * @return void
	 **/
	public function after_update( $status, $items, $result ){
		
		// try to get all the versions
		$versions = self::list_versions();
		
		// if we have versions, push it to SORBI
		if( $versions ){
			self::update_versions( $versions );
		}
	}
	
	/**
	 * Add the 'sorbi-connect' meta tag to the head
	 * This tag can be use as a fallback when file verification fails
	 *
	 * @return void
	 **/
	public function sorbi_meta_tag(){
		printf('<meta name="sorbi-connect" content="%s">', $this->site_key );
	}
	
	/**
	 * Add some inline styling to the head of wp-admin
	 * This is to prevent loading additonal css files
	 *
	 * @return void
	 **/
	public function sorbi_backend_head(){
		
		$show = ($this->site_secret != '') ? 'display:block !important' : '';
		
		?>
		<style>
		#sorbi_site_key,
		#sorbi_site_secret{min-width:320px; font-size: 22px; color: #8a8a8a; padding: 7px; }
		#sorbi_site_secret{ <?php echo $show; ?> }
		.toplevel_page_sorbi-connect .form-table th{ padding: 0px !important; width: 0px !important; }
		.sorbi-notice{
			padding-left: 50px;
			color:#fff;
			background: 10px center no-repeat #2bb298 url('data:image/svg+xml;base64,PHN2ZyBpZD0iTGF5ZXJfMSIgZGF0YS1uYW1lPSJMYXllciAxIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMTUuMTMgMTA1Ljg3Ij48dGl0bGU+c29yYmkgaWNvbjwvdGl0bGU+PGNpcmNsZSBjeD0iOTEuMTIiIGN5PSI2Ny4yNCIgcj0iMTQuNTkiLz48cGF0aCBkPSJNMTEzLjUyLDc1YTMuODYsMy44NiwwLDAsMSwzLjU2LTMuODRjLTIuNTUtMTYuMzYtMjAuOTQtMjkuNjEtNDUuMjEtMzNsLS4xOS0uMjhjLS43OS0yLjUtNC4yMS00LjUxLTguNjgtNS4yVjIzLjRhOC43NSw4Ljc1LDAsMSwwLTcsLjIydjkuMTdjLTQuMzkuNzMtNy43LDIuNzYtOC40Miw1LjI1bC0uMTMuMTlDMjMuNTYsNDEuNTEsNS4xOSw1NC4zOSwyLjE4LDcwLjM5QTMuODksMy44OSwwLDAsMSw0LjY5LDc0YTMuODQsMy44NCwwLDAsMS0yLjc0LDMuN0M0LDk3LjIxLDI5LjA1LDExMi42Miw1OS41OSwxMTIuNjJjMjkuOTIsMCw1NC41My0xNC44LDU3LjQ5LTMzLjc3QTMuODUsMy44NSwwLDAsMSwxMTMuNTIsNzVaTTkxLjc4LDEwMEgyNi40NmMtMTAuOTQsMC0xOS44LTEwLjYyLTE5LjgtMjQuNDVzOS4wOC0yNC43NSwyMC0yNC43NWMxLjQxLDAsMi4zMy40OCw0LjMzLjgzaDBMNDIuNTcsNTVhNzMuODYsNzMuODYsMCwwLDAsMzYuMjEtLjI2QTIwLDIwLDAsMCwwLDgwLjk1LDU0YzIuMjctLjg4LDYuODYtMi45MiwxMC4xMy0yLjkyLDEwLjk0LDAsMjAuMTYsMTAuNjIsMjAuMTYsMjQuNDVTMTAyLjcyLDEwMCw5MS43OCwxMDBaIiB0cmFuc2Zvcm09InRyYW5zbGF0ZSgtMS45NCAtNi43NSkiLz48Y2lyY2xlIGN4PSIyMi43MSIgY3k9IjY3LjI0IiByPSIxNC41OSIvPjwvc3ZnPg==');
			background-size: 30px auto;
		}
		.sorbi-notice p{
			padding: 1em 0 !important;
			margin: 0px !important;
		}
		</style>
		<?php
	}
	
	/**
	 * Register SORBI api endpoint > WP 45
	 * hooked on init
	 *
	 * @return void
	 **/
	public function sorbi_api_endpoint_v45(){
		
		register_rest_route( 'sorbi/v1', '/get_informations/site_key=(?P<site_key>[a-zA-Z0-9-]+)/site_secret=(?P<site_secret>[a-zA-Z0-9-./$]+)', array(
			'methods'  => WP_REST_Server::READABLE,
			'callback' => 'sorbi_endpoint'
		  ) );
		
	}
	
	/**
	 * Register SORBI api endpoint < WP 44
	 * hooked on init
	 *
	 * @return void
	 **/
	public function sorbi_api_endpoint_v44(){
		
		if(isset($_REQUEST['sorbi_rest_api'])){
			header("Content-Type: application/json;charset=utf-8");
			$data = json_encode(sorbi_endpoint($_REQUEST));
			echo $data;
			die();
		}
		
	}
	
	
	/**
	 * The notify function will show admin notices if we have them
	 * hooked on the shutdown
	 *
	 * @return void
	 **/
	public function sorbi_notify() {
		
		// print messages, persistant
		if( count( $this->messages ) > 0 ){
			foreach( $this->messages as $class => $messages ){
				$messages = array_unique( $messages );
				foreach( $messages as $message ){
					printf('<div class="sorbi-notice notice notice-%s is-dismissible"><p>%s</p></div>', $class, $message );
				}
			}
		}
		
		// now print the notices, temporarily
		if( count( $this->notices ) > 0 ){
			foreach( $this->notices as $class => $notices ){
				$notices = array_unique( $notices );
				foreach( $notices as $notice ){
					printf('<div class="sorbi-notice notice notice-%s is-dismissible"><p>%s</p></div>', $class, $notice );
				}
			}
		}
		
		// now remove the notices 
		delete_option( $this->sorbi_notices_option_name );
		
	}

	/**
	 * The validate site key function will post the submitted site key to the SORBI API
	 * 
	 * @param string $site_key the sha1 hashed site key from SORBI
	 * @param bool $manual if the call is made manually or by the plugin
	 * @return void
	 **/
	public function validate_site_key( $site_key, $manual = true ){
				
		// only execute on wp-admin
		if( !$this->is_admin ) return;
		
		// empty the messages
		$this->messages = array();
		
		// empty the notices
		$this->notices = array();
		
		// check if the site_key is set
		if( (string) $site_key !== '' ){
			
			// reset the messages and expiration
			update_option( $this->sorbi_message_option_name, $this->messages );
			update_option( $this->site_key_expiration_option_name, false );
			
			// set default valid 
			$valid 			= false;
			
			// create the html verification file 
			$filename 		= "{$site_key}.html";
			$filepath 		= "{$this->uploaddir['basedir']}/{$filename}";
			$fileurl 		= "{$this->uploaddir['baseurl']}/{$filename}";
			$filecontent 	= "sorbi:{$site_key}";
			
			// check if we can create the file
			$file_validation = is_file( $filepath ) && is_readable( $filepath ) ;
			
			// if we don't have the file, try to create it
			if( $file_validation === false ){
				$file_validation = file_put_contents( $filepath, $filecontent );
			}
			
			// we can use file validation
			if( $file_validation ){
				
				// now check if we can validate the file 
				$valid = self::sorbi_api_call( 
					'activate/key',
					array( 
						'site_key' 				=> $site_key,
						'method'				=> 'file',
						'path'					=> $fileurl,
						'activation_type'		=> 1
					)
				);
			}
			
			// we have a fallback for met-tag validation 
			if( !$valid ){
				$valid = self::sorbi_api_call( 
					'activate/key',
					array( 
						'site_key' 				=> $site_key,
						'method'				=> 'meta',
						'activation_type'		=> 1
					)
				);
			}
			
			// finaly, we save the valid until
			if( $valid && (int) $valid->activated_website === 1 ){
								
				// overwrite site key 
				$this->site_key = $site_key;
				
				// overwrite site_secret
				$this->site_secret = $valid->site_secret ? $valid->site_secret  : __('Oops! Something went wrong.');
				
				// set the success message including expiration
				if($this->site_secret != ''){
					$this->messages['success'][] = sprintf( __("Copy the site secret key and")) . ' ' . sprintf('<a href="https://panel.sorbi.com/?tmp_key=%s">'.__('click here!').'</a>', $valid->tmp_key);
					
					// update the site_secret
					update_option( $this->site_secret_option_name, $this->site_secret );
				}
								
			}
			
		}else{
			// we have an empty key
			$this->messages['error'][] = __("Your SORBI Connect site key can not be empty.", SORBI_TD );
		}
		
		// save the messages 
		update_option( $this->sorbi_message_option_name, (array) $this->messages );
		
		// save the notices 
		update_option( $this->sorbi_notices_option_name, (array) $this->notices );
		
		return $site_key;
	}
	
	/**
	 * On admin init we check the site key and expiration
	 * After we set the setting fields
	 * 
	 * @return void
	 **/
	public function admin_init(){
		
		// debug
		$this->debug();
		
		// get plugin details
		$this->plugin_data = get_plugin_data( SORBI_PLUGIN_FILE );
				
		// if we have no key
		if( !$this->site_key || (string) $this->site_key == '' ){
			$this->messages['info'][] = sprintf( __("Please add your SORBI Connect site key. If you don't have a key, <a href='%s' target='_blank'>get one here!</a>", SORBI_TD ), 'https://www.sorbi.com' );
		}else{
			
			// we do have a key, if it's expired, we force to auto check if it is valid 
			if( time() > $this->site_key_expiration ){
				self::validate_site_key( $this->site_key, false );
			}
			
		}
		
		register_setting(
            $this->sorbi_options_group, // Option group
            $this->site_key_option_name, // Option name
			array( $this, 'validate_site_key')
        );
		
		add_settings_section(
            $this->sorbi_options_section, // ID
            __("Connect your SORBI site key", SORBI_TD ), // Title
			array( $this, 'site_key_info' ), // Callback
            $this->pagename // Page
        );  
		
		add_settings_field(
            $this->site_key_option_name, // ID
            '', // Title 
            array( $this, 'site_key_callback' ), // Callback
            $this->pagename, // Page     
			$this->sorbi_options_section,
			array(
				'name' => $this->site_key_option_name,
				'placeholder' => __('Paste the key here ...')
			)
        );
		
		add_settings_field(
            $this->site_secret_option_name, // ID
            '', // Title 
            array( $this, 'site_secret_callback' ), // Callback
            $this->pagename, // Page     
			$this->sorbi_options_section,
			array(
				'name' => $this->site_secret_option_name,
				'placeholder' => __('Your secret key')
			)
        );
		
	}
	
	/** 
     * Get the site key info text and print its values
	 *
	 * @return void
     */
	public function site_key_info(){
        _e('Request your site key to use SORBI on your website.', SORBI_TD);
    }
	
	/** 
     * Get the site key and print its value in the input
	 *
	 * @return void
     */
    public function site_key_callback( $args ){
        printf('<input type="text" id="%s" name="%s" value="%s" placeholder="%s" />', $args['name'], $args['name'], ( $this->site_key ? esc_attr( $this->site_key ) : '' ), $args['placeholder']  );
    }
	
	/** 
     * Get the site secret and print its value in the input
	 *
	 * @return void
     */
	public function site_secret_callback( $args ){
			printf('<input type="text" id="%s" name="%s" value="%s" placeholder="%s" style="display:none;" />', $args['name'], $args['name'], ( $this->site_secret ? esc_attr( $this->site_secret ) : '' ), $args['placeholder'] );
	}
	
	/**
	 * Create the settings page menu item for SORBI Connect
	 * This function will create a top-level settings page menu item in the WP Backend
	 * 
	 * @return void
	 */
	public function menu_page(){
		
		// add the menu page
		add_menu_page(
			'SORBI Connect',
			'SORBI Connect',
			'manage_options',
			$this->pagename,
			array( $this, 'sorbi_settings_page' ), 
			'data:image/svg+xml;base64,PHN2ZyBpZD0iTGF5ZXJfMSIgZGF0YS1uYW1lPSJMYXllciAxIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMTUuMTMgMTA1Ljg3Ij48dGl0bGU+c29yYmkgaWNvbjwvdGl0bGU+PGNpcmNsZSBjeD0iOTEuMTIiIGN5PSI2Ny4yNCIgcj0iMTQuNTkiLz48cGF0aCBkPSJNMTEzLjUyLDc1YTMuODYsMy44NiwwLDAsMSwzLjU2LTMuODRjLTIuNTUtMTYuMzYtMjAuOTQtMjkuNjEtNDUuMjEtMzNsLS4xOS0uMjhjLS43OS0yLjUtNC4yMS00LjUxLTguNjgtNS4yVjIzLjRhOC43NSw4Ljc1LDAsMSwwLTcsLjIydjkuMTdjLTQuMzkuNzMtNy43LDIuNzYtOC40Miw1LjI1bC0uMTMuMTlDMjMuNTYsNDEuNTEsNS4xOSw1NC4zOSwyLjE4LDcwLjM5QTMuODksMy44OSwwLDAsMSw0LjY5LDc0YTMuODQsMy44NCwwLDAsMS0yLjc0LDMuN0M0LDk3LjIxLDI5LjA1LDExMi42Miw1OS41OSwxMTIuNjJjMjkuOTIsMCw1NC41My0xNC44LDU3LjQ5LTMzLjc3QTMuODUsMy44NSwwLDAsMSwxMTMuNTIsNzVaTTkxLjc4LDEwMEgyNi40NmMtMTAuOTQsMC0xOS44LTEwLjYyLTE5LjgtMjQuNDVzOS4wOC0yNC43NSwyMC0yNC43NWMxLjQxLDAsMi4zMy40OCw0LjMzLjgzaDBMNDIuNTcsNTVhNzMuODYsNzMuODYsMCwwLDAsMzYuMjEtLjI2QTIwLDIwLDAsMCwwLDgwLjk1LDU0YzIuMjctLjg4LDYuODYtMi45MiwxMC4xMy0yLjkyLDEwLjk0LDAsMjAuMTYsMTAuNjIsMjAuMTYsMjQuNDVTMTAyLjcyLDEwMCw5MS43OCwxMDBaIiB0cmFuc2Zvcm09InRyYW5zbGF0ZSgtMS45NCAtNi43NSkiLz48Y2lyY2xlIGN4PSIyMi43MSIgY3k9IjY3LjI0IiByPSIxNC41OSIvPjwvc3ZnPg=='
		);
		
	}
	
	/**
	 * Creates a version object for plugins, themes and core with names and versions
	 * 
	 * @return array $versions
	 */
	public function list_versions(){
		
		// set the args array
		$args = array();
		
		// Check if get_plugins() function exists. This is required on the front end of the
		// site, since it is in a file that is normally only loaded in the admin.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		
		require_once ABSPATH . 'wp-admin/includes/update.php';
		
		// for the plugins
		$plugins = get_plugins();
		$updates = array();
		$core_update = 0;
		
		// General update information
		$update_plugins = get_site_transient( 'update_plugins' );        
		if ( ! empty( $update_plugins->response ) )
            $updates['plugins'] = count( $update_plugins->response );
			
			foreach($update_plugins->response as $key => $update){
				$plugin_updates[$key] = $update->new_version;
			}
			
		$update_themes = get_site_transient( 'update_themes' );
        if ( ! empty( $update_themes->response ) )
            $updates['themes'] = count( $update_themes->response );
			
			foreach($update_themes->response as $key => $update){
				$theme_updates[$key] = $update->new_version;
			}
			
		$update_wordpress = get_core_updates( array('dismissed' => false) );
        if ( ! empty( $update_wordpress ) && ! in_array( $update_wordpress[0]->response, array('development', 'latest') ) )
            $core_update = 1;
		
		if( $plugins ){
						
			foreach( $plugins as $path => $plugin ){
				
				$key = ($plugin['Plugin Slug'] != '') ? $plugin['Plugin Slug'] : str_replace(' ','-', strtolower($plugin['Name']));
				
				$versions['plugin'][ $key ] = array(
					'name'		=> $plugin['Name'],
					'active' 	=> is_plugin_active( $path ),
					'version' 	=> $plugin['Version'],
					'update_available' => array_key_exists($path, $plugin_updates)
				);
			}
		}
				
		// for the core 
		$core_version = get_bloginfo('version');
		$versions['core'][ $this->platform ] = array(
			'name'		=> "WordPress {$core_version}",
			'active' 	=> true,
			'version' 	=> $core_version,
			'update_available' => $core_update
		);
		
		// for the theme
		$current = wp_get_theme();
		$themes = wp_get_themes( array( 'errors' => null ) );
				
		foreach( $themes as $key => $theme ){
			$versions['theme'][ $key ] = array(
				'name'		=> $theme['Name'],
				'active' 	=> ( $current->get( 'Name' ) === $theme['Name'] ),
				'version' 	=> $theme['Version'],
				'update_available' => array_key_exists($key, $theme_updates)
			);
		}
				
		// get checksums 
		if( !$manual ){
			$valid 		= true;
			$checksums	= array();
			$extra 		= '';
			$wp_locale 	= isset( $wp_local_package ) ? $wp_local_package : 'en_US' ;
			$api_url 	= sprintf( 'https://api.wordpress.org/core/checksums/1.0/?version=%s&locale=%s', $core_version, $wp_locale );
			$api_call 	= wp_remote_get( $api_url );
			
			if( is_array($api_call) ){
				$json 		= json_decode ( $api_call['body'] );

				foreach( $json->checksums as $file => $checksum ) {
					$file_path = ABSPATH . $file;
					
					if ( !file_exists( $file_path ) ){
						$valid = false;
						$checksums['missing'][] = $file;
						continue;
					}
					
					if( md5_file ($file_path) !== $checksum ) {
						$valid = false;
						$checksums['invalid'][] = $file;
						continue;
					}

				}
			}
			
			// add the core checksum result 
			$versions['security']['core_integrity'] = array(
				'name'		=> 'core-integrity',
				'active' 	=> ( $valid ? 1 : 0 ),
				'version' 	=> "{$core_version}-{$wp_locale}",
				'extra' 	=> ( $valid ? '' : json_encode( $checksums ) )
			);
			
		}
				
		// return result
		return $versions;
	}
	
	private function update_versions( $versions ){
		
		// set args
		$args['site_key'] 		= $this->site_key;
		$args['site_secret'] 	= $this->site_secret;
		$args['platform'] 		= $this->platform;
		$args['versions'] 		= (array) $versions;
		
		// call the SORBI API
		$version_call = self::sorbi_api_call( 'versions', $args, 'POST', true );
		
		// loop the results
		if( $version_call && isset( $version_call->summary ) && count( $version_call->summary ) > 0 ){
			$this->notices['success'][] = implode('<br>', $version_call->summary );
		}
	}
	
	/**
	 * Create the settings page for SORBI Connect
	 * This function will create a settings page in the WP Backend
	 * 
	 * @return void
	 */
	public function sorbi_settings_page(){ ?>
        <div class="wrap">
            <h1>SORBI Connect</h1>
            <form method="post" action="options.php">
            <?php
                // This prints out all hidden setting fields
                settings_fields( $this->sorbi_options_group );
                do_settings_sections( $this->pagename );
                submit_button( __('Validate SORBI site key', SORBI_TD ) );
            ?>
            </form>
			
			
        </div>
		<pre>
        <?php
		
		self::scan();
		$scan = self::check_file_changes();
		var_dump( $scan );
		
		?>
		</pre>
		<?php
	}
	
	 /**
     * Call the SORBI API
     *
     * @param array $input Contains all settings fields as array keys
     */
	public function sorbi_api_call( $action = '' , $args = array(), $method = 'POST', $silent = false ){
				
		// construct the call
		$call = array(
			'method' 		=> $method,
			'timeout'   	=> $this->timeout,
			'httpversion' 	=> '1.0',
			'blocking'    	=> true,
			'headers'     	=> array(),
			'body' 			=> $args
		);
						
		// construct api url
		$url = sprintf( $this->api_uri, $this->version, $action );
		
		// execute request
		$response = wp_remote_post( $url, $call );
				
		// wp error check
		if( is_wp_error( $response ) ){
			if( !$silent ) $this->messages['error'][] = $response->get_error_message();
			return false;
		}
		
		// check if we have a body 
		if( !isset( $response['body'] ) || empty( $response['body'] ) ) {
			if( !$silent ) $this->messages['error'][] = __('We received an empty response form the server.', SORBI_TD );
			return false;
		}
		
		// create the json object 
		$json = json_decode( $response['body'], true );
		
		// check for API errors 
		if( !$json['status'] ){
			if( !$silent ) $this->messages['error'][] = isset( $json['error']['message'] ) ? $json['error']['message'] : __('The call to the SORBI API failed.', SORBI_TD );
			return false;
		}
		
		// return as object
		return (object) $json;
	}
	
	// debug function
	private function debug(){
		// for debugging purposes
		if( $this->debug ) {
			$backtrace = debug_backtrace(); 
			$debug = array(
				'time'		=> microtime( true ),
				'backtrace' => "{$backtrace[1]['class']}:{$backtrace[1]['function']}"
			);
			$this->timer[] = (object) $debug;
		}
	}
	
	// Check if there is an update available
	private function check_available_update($slug, $version, $type){
		$update_available = false;
		return $update_available;
	}
	
}