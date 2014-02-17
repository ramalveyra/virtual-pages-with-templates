<?php
/*
Plugin Name: Virtual Pages with Templates
Plugin URI: https://github.com/Link7/virtual-pages-with-templates
Description: A plugin to display virtual pages with support for shortcodes
Version: 1.0
Author: Link7
Author URI: https://github.com/Link7
License: GPL3
License URI: http://www.gnu.org/licenses/gpl.html
*/
if ( ! defined( 'ABSPATH' ) ) exit('No direct script access allowed'); // Exit if accessed directly

if (!class_exists('VirtualPagesTemplates'))
{
	class VirtualPagesTemplates
	{
		public $template = NULL;
        public $template_content = NULL;
        public $options = array();
        public $shortcodes = NULL;
        public $use_custom_permalink = FALSE;
        public $permalink_structure = NULL;
        public $custom_permalink_structure = NULL;
        public $keyword = NULL;
        public $notice = NULL;
        public $notice_iserror = FALSE;
        public $menu_slug = NULL;

		public function __construct() 
		{	
			if ( ! is_admin() )
			{
			//	add_action('vpt_create_virtual', array($this, 'create_virtual') );
				add_filter('the_posts', array(&$this, 'create_virtual'));
				add_action('template_redirect', array($this, 'virtual_page_redirect'));

				remove_action( 'wp_head', 'wp_shortlink_wp_head', 10, 0 ); // Remove WordPress shortlink on wp_head hook
			}else{
				add_action( 'admin_menu', array($this, 'display_menu') );
				register_uninstall_hook(__FILE__, array('VirtualPagesTemplates','vpt_uninstall_plugin'));

			}
			
			$this->permalink_structure = get_option('permalink_structure');
	  	}

	  	/**
		* vpt_uninstall_plugin
		* 
		* completely removes the plugin installation
		*
		* @access public 
		* @return void
		*/
	  	public function vpt_uninstall_plugin(){
	  		delete_option('vpt_options');
	  	}

	  	/**
		* virtual_page_redirect
		* 
		* redirects to a virtual page/post if data being searched is not existing (posts/pages)
		*
		* @access public 
		* @return void
		*/
	  	public function virtual_page_redirect() {
		    if (is_search()) {
		        global $wp_query;

		        if ($this->options['affect_search'] )
		        {
		        	if (count($wp_query->posts) == 0  || !is_null($this->template) && $wp_query->post->ID == $this->template->ID)
		        	{
		        		$structure = $this->permalink_structure;
		        		if ($this->use_custom_permalink){
		        			$structure = $this->options['virtualpageurl'];
			        	}

		        		if (strpos($structure, '%postname%'))
		        			wp_redirect( str_replace('%postname%', $wp_query->query['s'] , $structure) );
			        }	
		        }
		    }
		}

	  	/**
		* display_menu
		* 
		* add's the menu to the admin / dashboard
		*
		* @access public 
		* @return NONE
		*/
	  	public function display_menu()
		{
			$this->menu_slug = add_options_page( 'Virtual Page Settings', 'Virtual Page Settings', 'manage_options', dirname(__FILE__) . '/form.php' );
			$this->menu_slug = str_replace('settings_page_', '', $this->menu_slug) . '.php';

			// use `admin_print_scripts` instead of `admin_enqueue_scripts` so this only loads on this specific form and NOT on all admin pages
			add_action('admin_print_scripts-' . $this->menu_slug, array($this, 'admin_includes') );

			// load on checking of $_POSTs when on this page
			add_action('load-'.$this->menu_slug, array($this,'check_posts'));
			
		}

		/**
		* check_posts
		* 
		* used in the dashboard, checks if there's an update and save into DB - wp_options
		*
		* @access public 
		* @return NONE
		*/
		public function check_posts()
		{	
			if(isset($_POST['vpt_hidden']) && $_POST['vpt_hidden'] == 'Y') {  
				unset($_POST['vpt_hidden']);
				unset($_POST['submit']);

				$extra = '';
				if (!isset($_POST['page_template'])){
					$extra = '&no-template=true';
				}
				else
				{
				$_POST['use_custom_permalink_structure'] = isset($_POST['use_custom_permalink_structure']) ? $_POST['use_custom_permalink_structure'] : '0';
				$_POST['affect_search'] = isset($_POST['affect_search']) ? $_POST['affect_search'] : '0';
				update_option('vpt_options', $_POST);
					$extra = '&settings-updated=true';
				}
				
				wp_redirect(admin_url('options-general.php?page=' . $this->menu_slug . $extra));
				
			}

			if ( ! empty( $_GET['settings-updated'] ) ) 
			{
				$this->notice = 'Settings saved.';
				add_action('admin_notices', array($this, 'display_notification'));
			}

			if ( ! empty( $_GET['no-template'] ) ) 
			{
				$this->notice = 'Page template is required. You can make a template by creating a <a href="'.admin_url('post-new.php').'">post</a> or a <a href="'.admin_url('post-new.php?post_type=page').'">page</a> as save it as draft.';
				$this->notice_iserror = TRUE;
				add_action('admin_notices', array($this, 'display_notification'));
			}
		}

		private function validate_page_template()
		{
			//if ()
		}

		/**
		* init_keyord
		* 
		* initialize the keyword
		*
		* @access public 
		* @return void
		*/
		public function init_keyword($current_url_trimmed, $virtualpageurl_trimmed){
			global $wp,$wp_query;
			if (isset($wp_query->query['name']) and $wp_query->query['name'])
            {
            	$this->keyword = $wp_query->query['name'];
            }
            else
            {
            	$needles = array('%postname%');
            	$replacements_regex = array(
                	'(?<postname>[^/]+)',
            	);
            	$regex = str_replace($needles, $replacements_regex, $virtualpageurl_trimmed);
            	$regex = str_replace('/', "\/", $regex);

            	$match = preg_match('/(?Ji)^' . $regex.'/', $current_url_trimmed, $matches);
            	if (!empty($matches)){
            		$this->keyword = $matches['postname'];
            	}
			}
		}

		/**
		* create_virtual
		* 
		* creates the virtual page / post based on the template used
		*
		* @access public 
		* @param object $posts - the wp posts
		* @return $posts
		*/
		public function create_virtual($posts)
		{
			global $wp,$wp_query;

            $this->options = get_option('vpt_options');

            $current_url = $_SERVER['REQUEST_URI'];
            if (!isset($this->options['use_custom_permalink_structure']))
            	$this->options['use_custom_permalink_structure'] = 0;
            $this->use_custom_permalink = (BOOL) $this->options['use_custom_permalink_structure'];

            if (!$this->use_custom_permalink)
				$virtualpageurl = $this->permalink_structure;
            else
            	$virtualpageurl = $this->options['virtualpageurl'];

            // trim slashes
            $virtualpageurl_trimmed = trim($virtualpageurl, '/');
            $current_url_trimmed = trim($current_url, '/');

            $this->init_keyword($current_url_trimmed, $virtualpageurl_trimmed);
            $virtual_url = str_replace('%postname%', $this->keyword, $virtualpageurl_trimmed);

            if ($virtual_url == $current_url_trimmed && (count($wp_query->posts) == 0 || (isset($wp_query->query['error']) && $wp_query->query['error'] == '404')) ) 
            {
            	$this->keyword = str_replace('-', ' ', $this->keyword);
            	// get the template details
            	$this->template_content = $this->get_template_content();
            	
            	//create a fake page
                $post = new stdClass;
               	$post->post_author = 1;
                $post->post_name = $wp->request;
               	$post->guid = get_home_url('/' . $this->keyword);
             
                $post->post_title = $this->keyword;
                //put your custom content here
                $post->post_content = $this->template_content;
                //just needs to be a number - negatives are fine
                $post->ID = $this->template->ID;
                $post->post_status = 'publish';
                $post->comment_status = 'closed';
                $post->ping_status = 'open';
                $post->comment_count = 0;
                //dates may need to be overwritten if you have a "recent posts" widget or similar - set to whatever you want
                $post->post_date = current_time('mysql');
                $post->post_date_gmt = current_time('mysql',1);
                $post->post_parent = 0;
                $post->menu_order = 0;
                $post->filter ='raw';
                // is page or a post
                $post->post_type = $this->options['post_type'];

                $posts = NULL;
                $posts[] = $post;

                $wp_query->is_singular = TRUE;
                $wp_query->is_home = FALSE;
                $wp_query->is_archive = FALSE;
                $wp_query->is_category = FALSE;
                unset($wp_query->query['error']);
                $wp_query->query_vars['error']='';
                $wp_query->is_404 = FALSE;
                $wp_query->found_posts = TRUE;
                $wp_query->is_attachment = FALSE;
                $wp_query->query_vars['page'] = 0;
                $wp_query->query_vars['attachment'] = NULL;
                unset($wp_query->query['attachment']);
                if ($post->post_type == 'post')
                {
                	// add the uncateegorized class to the article
            		add_filter('post_class',array($this, 'add_uncategorized_class'));
                	$wp_query->query['name'] = $this->keyword;
                	$wp_query->is_page = FALSE;
                	$wp_query->is_single = TRUE;
                }
                else
                {
               		$wp_query->query['pagename'] = $this->keyword;
               		$wp_query->query_vars['pagename'] = $this->keyword;
               		$wp_query->is_page = TRUE;
               		$wp_query->is_single = FALSE;
                }
                $wp_query->query['page'] = NULL;
            }

            return $posts;
            }
            
		/**
		* add_uncategorized_class
		* 
		* add the uncategorized class to the article the same as the normal posts does on uncategorized posts
		*
		* @access public 
		* @param array $classes
		* @return arrat $classes
		*/
		public function add_uncategorized_class($classes){
    		$classes[] = 'category-uncategorized';
    		return $classes;
		}

		/**
		* get_template_content
		* 
		* gets the template content and update the data e.g. converts %keyword% and spin texts
		*
		* @access public 
		* @param object $posts - the wp posts
		* @return $posts
		*/
		private function get_template_content()
		{
			global $wp,$wp_query;

			$this->template = get_post($this->options['page_template']);      

			$this->template_content = str_replace('%vpt-keyword%', $this->keyword, $this->template->post_content);

			return $this->template_content;
		}

		/**
		 * Include required admin files.
		 *
		 * @access public
		 * @return void
		 */
		public function admin_includes()
		{
			// do admin includes here
			wp_enqueue_script('vpt-scripts',plugins_url( '/js/scripts.js' , __FILE__ ),array( 'jquery' ));
		}

		/**
		 * Include required frontend files.
		 *
		 * @access public
		 * @return void
		 */
		public function frontend_includes()
		{
			// do site includes here
			
		}

		public function display_notification()
		{	
			if ($this->notice_iserror) {
				echo '<div id="message" class="error">';
			}
			else {
				echo '<div id="message" class="updated fade">';
			}

			echo '<p><strong>' . $this->notice . '</strong></p></div>';
		}   

	}	
}


new VirtualPagesTemplates;
