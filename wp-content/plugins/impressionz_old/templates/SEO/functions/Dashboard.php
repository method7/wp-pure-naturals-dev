<?php

namespace IMP;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

use WP_Query;

/**
 * Plugin_Dashboard_Widgets
 *
 * This function is hooked into the 'wp_dashboard_setup' action below.
 */

new Plugin_Dashboard_Widgets;

class Plugin_Dashboard_Widgets{

  function __construct(){
   add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widgets' ) );
  }
  /**
   * Add a widget to the dashboard.
   *
   * This function is hooked into the 'wp_dashboard_setup' action below.
   */
  function add_dashboard_widgets() {
      wp_add_dashboard_widget(
          __NAMESPACE__.'_dashboard_widget' ,                          // Widget slug.
          esc_html__( 'Impressionz', 'cop' ), // Title.
           array( $this, 'dashboard_widget_render' )                    // Display function.
      );
  }

  function dashboard_widget_render(){ ?>
    <style>

    #IMP_dashboard_widget .inside{
     padding:0px;
     margin-top:0px;
     padding-bottom:12px;
    }
     #IMP_dashboard_widget table{
      border:0px !important;
     }
    </style>
   <?php
    /**
    *
    * WP Query
    *
    * @see https://developer.wordpress.org/reference/classes/wp_query/
    *
    **/
   	global $args;
   	$posts_query_args = array(
       'post_type' 	      => 'any' ,
       'posts_per_page'   =>  10,
       'order'            => 'DESC',
      	'orderby'          => 'meta_value_num',
       'meta_query' => array(
         'relation' =>
            'AND',
          		 array(
           			'key'       => 'imp_gsc_new_kw',
           			'orderby'   => 'meta_value_num',
              'order'     => 'DESC',
           		),
             array(
               'key'       => 'imp_gsc_new_kw',
               'value'     => 0,
               'compare'   => '>'
             ),
       )
   );
   //print_R($posts_query_args );
   global $posts_query;
   $posts_query = new WP_Query( $posts_query_args );

   echo '<table class="wp-list-table widefat fixed striped posts">';
   // Event Loop
   if ( $posts_query->have_posts() ) :
     while ( $posts_query->have_posts() ) : $posts_query->the_post();
     $post_id = get_the_ID();
     ?>
     <tr id="<?php echo get_post_type() ?>-<?php echo get_the_ID()?>" <?php post_class('')?> style=" border-bottom:1px solid #eaeaea;">
       <td class="" style="width:70%;">
        <a itemprop="url"  class="" href="<?php echo get_edit_post_link( get_the_ID() ) ?>"><?php the_title() ?></a>
       </td>
       <td class="" style="text-align:center;">
           <?php
            echo '<div id="count_keywords-'.$post_id.'">';
            echo (get_post_meta( get_the_ID() , 'imp_gsc_new_kw', true) > 0) ? '<span style="color:red;">'.get_post_meta(get_the_ID(), 'imp_gsc_new_kw', true).' new</span>' : '';
            echo '</div>'; ?>
       </td>
      </tr>
   <?php endwhile;
   else :
      echo '<p class="mt-4 mb-2">Nema rezultata.</p>';
      // no posts found
   endif;
   echo '</table>';
    echo '<div style="text-align:right;width:100%;font-size:0.8em;clear:both;margin-right:5px;margin-top:10px;">report generated by <a href="https://impressionz.io/?site='.site_url().'" rel="nofollow" style="text-decoration:none;font-size:1em;color:#0073aa;">Impressionz</a>&nbsp; &nbsp; &nbsp;</div>';
   /* Restore original Post Data */
   wp_reset_postdata();
  }
}
?>
