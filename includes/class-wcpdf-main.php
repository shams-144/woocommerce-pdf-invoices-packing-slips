<?php
namespace WPO\WC\PDF_Invoices;

use WPO\WC\PDF_Invoices\Compatibility\WC_Core as WCX;
use WPO\WC\PDF_Invoices\Compatibility\Order as WCX_Order;
use WPO\WC\PDF_Invoices\Compatibility\Product as WCX_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( !class_exists( '\\WPO\\WC\\PDF_Invoices\\Main' ) ) :

class Main {
	
	function __construct()	{
		add_action( 'wp_ajax_generate_wpo_wcpdf', array($this, 'generate_pdf_ajax' ) );
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach_pdf_to_email' ), 99, 3 );
		add_filter( 'wpo_wcpdf_custom_attachment_condition', array( $this, 'disable_free_attachment'), 10, 4 );

		if ( isset(WPO_WCPDF()->settings->debug_settings['enable_debug']) ) {
			$this->enable_debug();
		}
		if ( isset(WPO_WCPDF()->settings->debug_settings['html_output']) ) {
			add_filter( 'wpo_wcpdf_use_path', '__return_false' );
		}

		// include template specific custom functions
		$template_path = WPO_WCPDF()->settings->get_template_path();
		if ( file_exists( $template_path . '/template-functions.php' ) ) {
			require_once( $template_path . '/template-functions.php' );
		}
	}

	/**
	 * Attach PDF to WooCommerce email
	 */
	public function attach_pdf_to_email ( $attachments, $email_id, $order ) {
		// check if all variables properly set
		if ( !is_object( $order ) || !isset( $email_id ) ) {
			return $attachments;
		}

		// Skip User emails
		if ( get_class( $order ) == 'WP_User' ) {
			return $attachments;
		}

		$order_id = WCX_Order::get_id( $order );

		if ( get_class( $order ) !== 'WC_Order' && $order_id == false ) {
			return $attachments;
		}

		// WooCommerce Booking compatibility
		if ( get_post_type( $order_id ) == 'wc_booking' && isset($order->order) ) {
			// $order is actually a WC_Booking object!
			$order = $order->order;
		}

		// do not process low stock notifications, user emails etc!
		if ( in_array( $email_id, array( 'no_stock', 'low_stock', 'backorder', 'customer_new_account', 'customer_reset_password' ) ) || get_post_type( $order_id ) != 'shop_order' ) {
			return $attachments; 
		}

		$tmp_path = $this->get_tmp_path('attachments');

		// clear pdf files from temp folder (from http://stackoverflow.com/a/13468943/1446634)
		// array_map('unlink', ( glob( $tmp_path.'*.pdf' ) ? glob( $tmp_path.'*.pdf' ) : array() ) );

		$attach_to_document_types = $this->get_documents_for_email( $email_id, $order );
		foreach ( $attach_to_document_types as $document_type ) {
			do_action( 'wpo_wcpdf_before_attachment_creation', $order, $email_id, $document_type );

			try {
				// prepare document
				$document = wcpdf_get_document( $document_type, (array) $order_id, true );
				if ( !$document ) { // something went wrong, continue trying with other documents
					continue;
				}

				// get pdf data & store
				$pdf_data = $document->get_pdf();
				$filename = $document->get_filename();
				$pdf_path = $tmp_path . $filename;
				file_put_contents ( $pdf_path, $pdf_data );
				$attachments[] = $pdf_path;

				do_action( 'wpo_wcpdf_email_attachment', $pdf_path, $document_type );				
			} catch (Exception $e) {
				error_log($e->getMessage());
				continue;
			}
		}

		return $attachments;
	}

	public function get_documents_for_email( $email_id, $order ) {
		$documents = WPO_WCPDF()->documents->get_documents();

		$attach_documents = array();
		foreach ($documents as $document) {
			$attach_documents[ $document->get_type() ] = $document->get_attach_to_email_ids();
		}
		$attach_documents = apply_filters('wpo_wcpdf_attach_documents', $attach_documents );

		$document_types = array();		
		foreach ($attach_documents as $document_type => $attach_to_email_ids ) {
			// legacy settings: convert abbreviated email_ids
			foreach ($attach_to_email_ids as $key => $attach_to_email_id) {
				if ($attach_to_email_id == 'completed' || $attach_to_email_id == 'processing') {
					$attach_to_email_ids[$key] = "customer_" . $attach_to_email_id . "_order";
				}
			}

			$extra_condition = apply_filters('wpo_wcpdf_custom_attachment_condition', true, $order, $email_id, $document_type );
			if ( in_array( $email_id, $attach_to_email_ids ) && $extra_condition === true ) {
				$document_types[] = $document_type;
			}
		}

		return $document_types;
	}

	/**
	 * Load and generate the template output with ajax
	 */
	public function generate_pdf_ajax() {
		// Check the nonce
		if( empty( $_GET['action'] ) || ! is_user_logged_in() || !check_admin_referer( $_GET['action'] ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'woocommerce-pdf-invoices-packing-slips' ) );
		}

		// Check if all parameters are set
		if ( empty( $_GET['document_type'] ) && !empty( $_GET['template_type'] ) ) {
			$_GET['document_type'] = $_GET['template_type'];
		}

		if( empty( $_GET['document_type'] ) || empty( $_GET['order_ids'] ) ) {
			wp_die( __( 'Some of the export parameters are missing.', 'woocommerce-pdf-invoices-packing-slips' ) );
		}

		// Generate the output
		$document_type = $_GET['document_type'];

		$order_ids = (array) explode('x',$_GET['order_ids']);
		// Process oldest first: reverse $order_ids array
		$order_ids = array_reverse($order_ids);

		// Check the user privileges
		if( apply_filters( 'wpo_wcpdf_check_privs', !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) && !isset( $_GET['my-account'] ), $order_ids ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'woocommerce-pdf-invoices-packing-slips' ) );
		}

		// User call from my-account page
		if ( !current_user_can('manage_options') && isset( $_GET['my-account'] ) ) {
			// Only for single orders!
			if ( count( $order_ids ) > 1 ) {
				wp_die( __( 'You do not have sufficient permissions to access this page.', 'woocommerce-pdf-invoices-packing-slips' ) );
			}

			// Check if current user is owner of order IMPORTANT!!!
			$order = WCX::get_order ( $order_ids[0] );
			if ( WCX_Order::get_prop( $order, 'customer_id' ) != get_current_user_id() ) {
				wp_die( __( 'You do not have sufficient permissions to access this page.', 'woocommerce-pdf-invoices-packing-slips' ) );
			}

			// if we got here, we're safe to go!
		}
	

		try {
			$document = wcpdf_get_document( $document_type, $order_ids, true );

			if ( $document ) {
				$output_format = WPO_WCPDF()->settings->get_output_format( $document_type );
				switch ( $output_format ) {
					case 'html':
						$document->output_html();
						break;
					case 'pdf':
					default:
						if ( has_action( 'wpo_wcpdf_created_manually' ) ) {
							do_action( 'wpo_wcpdf_created_manually', $document->get_pdf(), $document->get_filename() );
						}
						$document->output_pdf();
						break;
				}
			}
		} catch (Exception $e) {
			echo $e->getMessage();
		}

		exit;
	}

	/**
	 * Return tmp path for different plugin processes
	 */
	public function get_tmp_path ( $type = '' ) {
		$tmp_base = $this->get_tmp_base();
		// check if tmp folder exists => if not, initialize 
		if ( !@is_dir( $tmp_base ) ) {
			$this->init_tmp( $tmp_base );
		}
		
		if ( empty( $type ) ) {
			return $tmp_base;
		}

		switch ( $type ) {
			case 'dompdf':
				$tmp_path = $tmp_base . 'dompdf';
				break;
			case 'font_cache':
			case 'fonts':
				$tmp_path = $tmp_base . 'fonts';
				break;
			case 'attachments':
				$tmp_path = $tmp_base . 'attachments/';
				break;
			default:
				$tmp_path = $tmp_base . $type;
				break;
		}

		// double check for existence, in case tmp_base was installed, but subfolder not created
		if ( !@is_dir( $tmp_path ) ) {
			@mkdir( $tmp_path );
		}

		return $tmp_path;
	}

	/**
	 * return the base tmp folder (usually uploads)
	 */
	public function get_tmp_base () {
		// wp_upload_dir() is used to set the base temp folder, under which a
		// 'wpo_wcpdf' folder and several subfolders are created
		// 
		// wp_upload_dir() will:
		// * default to WP_CONTENT_DIR/uploads
		// * UNLESS the ‘UPLOADS’ constant is defined in wp-config (http://codex.wordpress.org/Editing_wp-config.php#Moving_uploads_folder)
		// 
		// May also be overridden by the wpo_wcpdf_tmp_path filter

		$upload_dir = wp_upload_dir();
		$upload_base = trailingslashit( $upload_dir['basedir'] );
		$tmp_base = trailingslashit( apply_filters( 'wpo_wcpdf_tmp_path', $upload_base . 'wpo_wcpdf/' ) );
		return $tmp_base;
	}

	/**
	 * Install/create plugin tmp folders
	 */
	public function init_tmp ( $tmp_base ) {
		// create plugin base temp folder
		@mkdir( $tmp_base );

		// create subfolders & protect
		$subfolders = array( 'attachments', 'fonts', 'dompdf' );
		foreach ( $subfolders as $subfolder ) {
			$path = $tmp_base . $subfolder . '/';
			@mkdir( $path );

			// copy font files
			if ( $subfolder == 'fonts' ) {
				$this->copy_fonts( $path );
			}

			// create .htaccess file and empty index.php to protect in case an open webfolder is used!
			@file_put_contents( $path . '.htaccess', 'deny from all' );
			@touch( $path . 'index.php' );
		}

	}

	/**
	 * Copy DOMPDF fonts to wordpress tmp folder
	 */
	public function copy_fonts ( $path ) {
		$path = trailingslashit( $path );
		$dompdf_font_dir = WPO_WCPDF()->plugin_path() . "/vendor/dompdf/dompdf/lib/fonts/";

		// first try the easy way with glob!
		if ( function_exists('glob') ) {
			$files = glob($dompdf_font_dir."*.*");
			foreach($files as $file){
				if(!is_dir($file) && is_readable($file)) {
					$dest = $path . basename($file);
					copy($file, $dest);
				}
			}
		} else {
			// fallback method using font cache file (glob is disabled on some servers with disable_functions)
			$font_cache_file = $dompdf_font_dir . "dompdf_font_family_cache.php";
			$font_cache_dist_file = $dompdf_font_dir . "dompdf_font_family_cache.dist.php";
			$fonts = @require_once( $font_cache_file );
			$extensions = array( '.ttf', '.ufm', '.ufm.php', '.afm' );

			foreach ($fonts as $font_family => $filenames) {
				foreach ($filenames as $filename) {
					foreach ($extensions as $extension) {
						$file = $filename.$extension;
						if (file_exists($file)) {
							$dest = $path . basename($file);
							copy($file, $dest);
						}
					}
				}
			}

			// copy cache files separately
			copy($font_cache_file, $path.basename($font_cache_file));
			copy($font_cache_dist_file, $path.basename($font_cache_dist_file));
		}
	}

	public function disable_free_attachment( $attach, $order, $email_id, $document_type ) {
		// prevent fatal error for non-order objects
		if ( !method_exists( $order, 'get_total' ) ) {
			return false;
		}

		$document_settings = WPO_WCPDF()->settings->get_document_settings( $document_type );
		// echo '<pre>';var_dump($document_type);echo '</pre>';
		// error_log( var_export($document_settings,true) );

		// check order total & setting
		$order_total = $order->get_total();
		if ( $order_total == 0 && isset( $document_settings['disable_free'] ) ) {
			return false; 
		}

		return $attach;
	}
	
	/**
	 * Enable PHP error output
	 */
	public function enable_debug () {
		error_reporting( E_ALL );
		ini_set( 'display_errors', 1 );
	}

}

endif; // class_exists

return new Main();