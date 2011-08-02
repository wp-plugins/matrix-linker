<?php
/*
	Please note that the PHP code in this library file is intended to be a cross-CMS-platform API, hence the abstract class stuff.
	
	No changes should be made to this (tested) code. Add your customization in your platform-specific override code.
*/

/*
	Simple type constraint class to validate data.
*/
class type_constraint {
	public static function is_a_string( $arg ) {
		if ( is_string( $arg ) === false )
			throw new exception( 'String expected.' );
	}
	public static function is_an_array( $arg ) {
		if ( is_array( $arg ) === false )
			throw new exception( 'Array expected.' );
	}
	public static function is_a_bool( $arg ) {
		if ( is_bool( $arg ) === false )
			throw new exception( 'Bool expected.' );
	}
	public static function is_nullable_array( $arg ) {
		if ( is_null($arg) === false and is_array( $arg ) === false )
			throw new exception( 'Nullable array expected.' );
	}
	public static function is_nullable_string( $arg ) {
		if ( is_null($arg) === false and is_string( $arg ) === false )
			throw new exception( 'Nullable string expected.' );
	}
}

/*
	Represents a single entry within the packet of links from the master server.
*/
class matrix_link {
	/*
		Initializes a new instance of the matrix_link class.
	*/
	public function matrix_link( $new_url, $new_title ) {
		type_constraint::is_a_string( $new_url );
		type_constraint::is_a_string( $new_title );
		
		$this->url   = $new_url;
		$this->title = $new_title;
	}
	
	public function get_url() {
		return $this->url;
	}
	
	public function get_title() {
		return $this->title;
	}
	
	private $url;
	private $title;
}

/*
	Main class for all client websites and pages.
	The class is abstract since several functions require platform-specific implementations.
*/
abstract class matrix_linker {
	/*
		Called from every page that contains the link module, and echos the HTML for the links.
		
		Param $page_identifier : Identifier of the page we wish to get links for.
	*/
	public static function draw_links( $page_identifier ) {
		$matrix_links = self::$instance->get_matrix_links( $page_identifier );

		foreach ( $matrix_links as $matrix_link )
			echo "<li><a href=\"http://" . $matrix_link->get_url() . '">' . $matrix_link->get_title() . "</a></li>";
	}
	
	/*
		Called when this script is installed on the website.
		
		Param $url        : URL of the website we're registering.
		Param $type       : Name of the CMS the website uses.
		Param $version    : Version of the CMS the calling script was written for.
		Param $additional : Additional variables.
	*/
	public static function register_website( $url, $type, $version, $additional = null ) {
		type_constraint::is_a_string( $url );
		type_constraint::is_a_string( $type );
		type_constraint::is_a_string( $version );
		type_constraint::is_nullable_array( $additional );
		
		// Build request data
		$post_data = $additional ? $additional : array();
		$post_data['Mode']    = 'RegisterWebsite';
		$post_data['URL']     = $url;
		$post_data['Type']    = $type;
		$post_data['Version'] = $version;
		
		// Request
		$response = self::http_request( $post_data );
		
		// Process the received authorization code
		$auth_code = $response['AuthCode'];
		type_constraint::is_a_string( $auth_code );
		
		self::$instance->set_auth_code( $auth_code );
		
		// Confirm reception of the authorization code and receive default links
		$response = self::confirm_website_registration();
		
		// Store default links
		$matrix_links = self::unserialize_matrix_links( $response['Links'] );
		self::$instance->set_matrix_links( $matrix_links );
	}
	
	/*
		Called when the link module is added to a page.
		
		Param $relative_url : URL of the page we wish to register, relative to the website base URL.
		Param $title       : Link text that will point to this page.
		Param $additional  : Additional variables.
	*/
	public static function register_page( $relative_url, $title, $additional = null ) {
		type_constraint::is_a_string( $relative_url );
		type_constraint::is_a_string( $title );
		type_constraint::is_nullable_array( $additional );
		
		// Build request data
		$post_data = $additional ? $additional : array();
		$post_data['Mode']        = 'RegisterPage';
		$post_data['AuthCode']    = self::$instance->get_auth_code();
		$post_data['RelativeURL'] = $relative_url;
		$post_data['Title']       = $title;
		
		self::http_request( $post_data );
	}
	
	/*
		Called when the link module is removed from a page.
		
		Param $relative_url : URL of the page we wish to unregister, relative to the website base URL.
		Param $additional  : Additional variables.
	*/
	public static function unregister_page( $relative_url, $additional = null ) {
		type_constraint::is_a_string( $relative_url );
		type_constraint::is_nullable_array( $additional );
		
		// Build request data
		$post_data = $additional ? $additional : array();
		$post_data['Mode']        = 'UnregisterPage';
		$post_data['AuthCode']    = self::$instance->get_auth_code();
		$post_data['RelativeURL'] = $relative_url;
		self::http_request($post_data);
	}
	
	/*
		Sets the authentification code sent from the server as a reply from register_website.
		
		Param $auth_code : The new authentification code to be set.
	*/
	protected abstract function set_auth_code($auth_code);
	
	/*
		Returns the authentification code as sent from the server as a reply from register_website.
		
		Returns : The string set when set_auth_code is called.
	*/
	protected abstract function get_auth_code();
	
	/*
		Called periodically from the master server to send a new list of matrix_link instances.
		
		Param $matrix_links : An array of matrix_link instances.
	*/
	protected abstract function set_matrix_links( $matrix_links );
	
	/*
		Returns an array of matrix_link instances that should be displayed for the page, the returned values should remain consistent until set_matrix_links is called again.
		
		Param $page_identifier : The unique identifier for the current page.
	*/
	protected abstract function get_matrix_links( $page_identifier );
	
	/*
		Handles external requests from the matrix_linker server.
		
		Param $post_data : An array of data returned from an HTTP request.
	*/
	public static function handle_request( $post_data ) {
		type_constraint::is_an_array( $post_data );
		
		// Extract message ID and authentification code
		$mode      = $post_data['Mode'];
		$auth_code = $post_data['AuthCode'];
		
		type_constraint::is_a_string( $mode );
		type_constraint::is_a_string( $auth_code );
		
		// Make sure the request authenticates
		$expected_auth_code = self::$instance->get_auth_code();
		
		if ($auth_code != $expected_auth_code)
			throw new exception( "Authentication codes failed to match." );
		
		// Dispatch this request to the relevant message handler
		switch ( $mode )
		{
			case 'SetMatrixLinks':
				self::handle_request_set_matrix_links( $post_data );
				break;
			
			default:
				throw new exception( "Unknown mode to handle: $mode." );
				break;
		}
	}
	
	/*
		Initializes a new instance of the matrix_linker class.
	*/
	protected function matrix_linker() {
		if ( self::$instance )
			throw new exception( 'An instance of matrix_linker has already been instanced.' );
		
		self::$instance = $this;
	}

	/*
		Posts the auth_code back to the server at the time of website registration.
		
		Returns : HTTP request response array.
	*/	
	private static function confirm_website_registration() {
		// Build request data
		$post_data = array();
		$post_data['Mode']     = 'ConfirmWebsiteRegistration';
		$post_data['AuthCode'] = self::$instance->get_auth_code();
		
		return self::http_request($post_data);
	}
	
	/*
		Posts a request to the master server.
		
		Param $post_data : Array of the post data.
		
		Returns : HTTP request response array.
	*/
	private static function http_request($post_data) {
		type_constraint::is_an_array( $post_data );
		type_constraint::is_a_string( $post_data['Mode'] );
		
		// Submit request
		$c = curl_init();
		curl_setopt($c, CURLOPT_URL, 'http://www.matrixlinker.com/tools/reg.php');
		curl_setopt($c, CURLOPT_POST, TRUE);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($c, CURLOPT_POSTFIELDS, $post_data);
		
		$response = curl_exec($c);
		$error    = curl_error($c);
		curl_close($c);
		
		// Throw curl error?
		if ( $response === false )
			throw new exception( "Matrix Linker failed to register with remote server with error code: $error" );
		
		// Unserialize response
		$response = unserialize( $response );
		$error    = $response['Error'];
		
		type_constraint::is_nullable_array( $response );
		type_constraint::is_nullable_string( $error );
		
		// Throw response error?
		if ( $error )
			throw new exception( "Matrix Linker http_request mode '" . $post_data['Mode'] . "' Failed with message: " . $error );
			
		return $response;
	}
	
	/*
		Handles the server request for setting the current matrix_links.
		
		Param $post_data : Array of the post data.
	*/
	private static function handle_request_set_matrix_links( $post_data ) {
		$matrix_links = self::unserialize_matrix_links( $post_data['Links'] );
		self::$instance->set_matrix_links( $matrix_links );
	}
	
	/*
		Splits a string of URL and title pairs into an array of matrix_links.
		
		Param $links : Serialized URL and titles.
		
		Returns : An array of matrix_link instances.
	*/
	private static function unserialize_matrix_links( $links ) {
		$matrix_links = array();
		
		// Each link pair is delimited by a tab
		$split_links  = explode( "\n", $links );
		foreach ( $split_links as $split_link )	{
			// Qualify the link data format or reject it. The format is basically: "URL\tTitle Text"
			if ( preg_match( "#^[-\.a-z0-9_/?=]+\t[- a-zA-Z0-9?']+$#", $split_link ) == 1 ) {
				// Separate the URL and title
				list( $url, $title ) = explode( "\t", $split_link );
				
				// Push the new matrix_link into the array
				array_push( $matrix_links, new matrix_link( $url, $title ) );
			}
		}
		
		return $matrix_links;
	}
	
	/*
		Instance to an implementation of the matrix_linker class.
	*/
	private static $instance;
}
