<?php
/*
Plugin Name: Matrix Linker
Plugin URI: http://www.matrixlinker.com/wordpress/
Description: Include text link units on your web pages in order to gain links from other websites for search engine ranking benefits.
Version: 1.0
Author: Andrew Wilkes
Author URI: http://internetmarketingcoding.com/
*/

// Load the API
include dirname(__FILE__) . '/api.php';

register_activation_hook( __FILE__,   'matrix_linker_install' );
register_deactivation_hook( __FILE__, 'matrix_linker_deactivation' );

wp_register_sidebar_widget(
	'widget_ml',
	'Matrix Linker',
	'widget_ml', array(
        'description' => 'Add a link unit to your sidebar'
    )
);

// Define our WordPress implementation of the API functions
class matrix_linker_wp extends matrix_linker {
	public static function static_init()	{
		new matrix_linker_wp();
	}
	
	/*
		Store the authorization code that is provided by the server for later authentication
	*/
	protected function set_auth_code( $auth_code ) {
		update_option( 'MATRIX_LINKER_AUTH_CODE', $auth_code );
	}
	
	protected function get_auth_code() {
		return get_option( 'MATRIX_LINKER_AUTH_CODE' );
	}
	
	/*
		Store the new links in the options table
	*/
	protected function set_matrix_links( $links ) {
		// Check if we have any links to display
		$link_count = count( $links );
		if ( $link_count < 1 )
			throw new exception( 'No links were found.' );
		
		$num_links  = 5; // This is hard-coded since the link unit should not change in size in order to not mess up the visual design of a host site
		
		// Split links up into groups of $num_links
		$link_batch_count = ceil( $link_count / $num_links );
		$link_batches    = array();
		
		$link_index = 0;
		for ($i = 0; $i < $link_batch_count; $i++)
		{
			$link_batch = array();
			for ($j = 0; $j < $num_links; $j++)
			{
				array_push( $link_batch, $links[$link_index] );
				$link_index = ($link_index + 1) % $link_count; // Increment the link_index and wrap if necessary
			}
			array_push( $link_batches, $link_batch );
		}
		
		// Store the links
		update_option( 'MATRIX_LINKS', serialize( $link_batches ) );
	}
	
	/*
		Obtain links to display in a matrix_linker widget
	*/	
	protected function get_matrix_links( $page_id ) {
		$link_batches = unserialize( get_option( 'MATRIX_LINKS' ) );

		if ( empty( $link_batches ) )
			return array();
		
		$link_batch_count = count( $link_batches );
		$batch_index = ($page_id + 1) % $link_batch_count;
		$matrix_links = $link_batches[$batch_index];
		return $matrix_links;
	}
	
}

matrix_linker_wp::static_init();

/*
	The sidebar widget code to display the links and provide admin forms to add/update or delete a Matrix Linker widget on the current web page (post)
*/
function widget_ml( $args ) {
	global $post;
	
	// Don't display widget on archive pages
	if ( is_archive() )
		return;
	
	// Get the page_id
	if ( is_home() )
		$page_id = 0;
	else
		$page_id = $post->ID;
	
	if ( current_user_can('manage_options') )
		$is_admin = true;
	
	// Check for registered page
	$registered_pages = unserialize(get_option('MATRIX_LINKER_PAGES'));

	// Handle posted data to update the link unit associated with the current page
	if ( isset( $_POST['matrix_linker_keyword'] ) && $is_admin ) {
		foreach( $_POST as $key => $value ) {
			switch( $key ) {
				case 'delete':
					matrix_linker_remove_link_unit();
					unset( $registered_pages[$page_id] );
					break;
					
				case 'update':
				case 'add':
					$keyword = matrix_linker_update_link_unit();
					$registered_pages[$page_id] = $keyword;
					break;
			}
		}
		update_option( 'MATRIX_LINKER_PAGES', serialize( $registered_pages ) );
		// Hide the link unit form after actioning the posted data.
	}
	$keyword = stripslashes($registered_pages[$page_id]);
	$registered_page = ! empty( $keyword ); // Detect if the current page was previously registered or not
	
	// Render the widget according to if the page was registered or the user is the admin
	extract($args);
	if ( $registered_page ) {
		echo $before_widget;
		echo "<ul>";
		matrix_linker::draw_links( $page_id );
		echo "</ul>";
		if ( $is_admin ) // Display an update/delete form for the widget that is displayed on the current page
			matrix_linker_form_template( 'update/delete', $keyword) ;
		echo $after_widget;
	} else { // This page has not yet been registered as containing a Matrix Linker widget
		if ( $is_admin ) { // Display a form to the admin to allow for adding a Matrix Linker widget to the current page
			echo $before_widget;
			if ( is_home() )
				$keyword = get_option('blogname');
			else
				$keyword = $post->post_title;
			matrix_linker_form_template( 'add', $keyword );
			echo $after_widget;
		}
	}
}

function matrix_linker_form_template( $type, $keyword ) {
	?>
<form action="" method="post" id="matrix_linkerForm">
<div style="font-size:11px; border:2px solid #eee; padding:5px;">
<span style="font-weight:bold; font-size:12px;">Matrix Linker Admin *</span><br>
Keyword for return links: <input type="text" name="matrix_linker_keyword" value="<?php echo $keyword; ?>" style="width:150px;" /><br>
	<?php
	// Output the buttons
	if ( $type == 'add' ) { ?>
<input type="submit" value="Add Link Unit" name="add" style="margin-top:10px;" />
	<?php } else { ?>
<input type="submit" value="Update" name="update" style="margin-top:10px;" /> &nbsp;
<input type="submit" value="Delete" name="delete" onClick="return confirm('Are you sure that you want to delete this link unit?');" style="margin-top:10px;" />
	<?php } ?><br>
* Panel only visible to admin
</div>
</form>
	<?php
}

/*
	Update/add details of the page containing the Matrix Linker widget to the link server
*/
function matrix_linker_update_link_unit() {
	$relative_url = matrix_linker_get_relative_url();
	$keyword = $_POST['matrix_linker_keyword'];
	matrix_linker::register_page( $relative_url, $keyword );
	return $keyword;
}

/*
	Update the link server with details of the removed Matrix Linker widget
*/
function matrix_linker_remove_link_unit() {
	$relative_url = matrix_linker_get_relative_url();
	matrix_linker::unregister_page( $relative_url );
}

/*
	Return the relative (to the host site) URL of the current web page
*/
function matrix_linker_get_relative_url() {
	if ( is_archive() || is_home() )
		return '/';
	return str_replace( get_option( 'siteurl' ), '', get_permalink() );
}

/*
	This code runs when the plugin is activated
*/
function matrix_linker_install() {
	if ( ! function_exists('curl_init') ) {
		echo "Matrix Linker requires PHP's CURL extensions";
		return;
	}
	
	// Create an access key for the remote link server
	$key = md5( time() );
	update_option( 'MATRIX_LINKER_KEY', $key );

	// Register this blog with the link server
	matrix_linker::register_website( get_option( 'siteurl' ), 'WP', '3.1', array('key' => $key) );
}

/*
	Remove data associated with this plugin from the database
*/
function matrix_linker_deactivation() {
	delete_option( 'MATRIX_LINKER_KEY' );
	delete_option( 'MATRIX_LINKER_AUTH_CODE' );
	delete_option( 'MATRIX_LINKS' );
	delete_option( 'MATRIX_LINKER_PAGES' );
	unregister_widget('widget_ml');
}

/*
	This code is accessed by the remote link server to replenish the links to be displayed by the Matrix Linker widgets
*/
if ( isset( $_POST["Links"] ) ) {
	// Validate the key
	if ( get_option( 'MATRIX_LINKER_KEY' ) == $_GET['key'] ) {
		// Process the received data
		try {
			matrix_linker::handle_request( $_POST );
		}
		catch(Exception $e) {
			exit( $e->getMessage() );
		}
	}
	exit( 'SUCCESS' );
}
?>