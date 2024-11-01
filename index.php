<?php 
/*
Plugin Name: WL Article Adopter
Version: 0.15
Description: The client for Shared Article Repository plugin, a database of shared articles for participating websites
Author: Iver Odin Kvello
    This file is part of the WordPress plugin Article Adopter
    Copyright (C) 2016 Iver Odin Kvello

    Article Adopter is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Article Adopter is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 Text Domain: wlaa-plugin
 Domain Path: /languages/
*/


require_once(dirname(__FILE__) . '/lib/aa-list-table.php');


class ArticleAdopter {
  public $dbversion=0.1;
  public $categories=null;
 
  function __construct() {
  }

  public function options() {
    // No caching here, use WPs own.
    $options = get_option('article-adopter_options');
    return $options;
  }

  // HOOKS

  public function admin_init () {
   register_setting('article-adopter_options','article-adopter_options', array($this,'validate'));
   $options = $this->options();
   if (!$options['connected']) {
    add_action('admin_notices', function () { echo"<div class='notice notice-warning is-dismissible aa-not-connected'><p>"; _e('Article Adopter: You are not currently connected to the article database server.','wlaa-plugin'); echo "</p></div>"; });
   }
   add_action( 'admin_footer', array($this,'admin_footer'));
   add_action( 'wp_ajax_aa_connect', array($this,'ajax_connect_to_server'));
   add_action( 'save_post', array($this,'save_post_trash'), 10, 2 );

   // If the cron-job isn't actually running, ensure that it does so. It was left out of some versions. IOK 2017-02-09
   // This could/should be REMOVED in a later version
   if ($options['connected']) {
    $schedule = wp_get_schedule('update_shared_articles');
    if (!$schedule) {
      wp_schedule_event(time(),'tenminutes','update_shared_articles');
    } else {
      // Noop - everything is OK
    }
   }

   if (isset($options['last_updated'])) {
    $since = time() - intval($options['last_updated']);
    // WP-Cron hasn't ran for 1 hour - possibly due to caching. Update articles.
    if ($since > (3600*1)) {
      $this->logmessage(__("WP-Cron hasn't updated articles for an hour. Updating now.",'wlaa-plugin'));
      $this->get_updates();
    }
   }

  }

  

  public function admin_menu () {
    add_options_page('WL Article Adopter',__('WL Article Adopter'), 'manage_options', 'article-adopter_options',array($this,'toolpage'));
    $adhook = add_menu_page('Article Database',__('Article Database','wlaa-plugin'),'manage_options','article-database',array($this,'article_list'),'dashicons-admin-page"',21);

    // Certain tags go in the 'article database' menu
    $menutags = get_terms(array('taxonomy' => 'shared_article_tag', 'hide_empty' => false, 'order'=>'ASC', 'orderby'=>'name', 'meta_key'=>'in_menu', 'meta_value'=>true));
    foreach($menutags as $tag) {
     add_submenu_page('article-database',$tag->name,$tag->name,'manage_options','article-database&tagname='.$tag->name,'__return_null',"dashicons-admin-page");
    }


    add_submenu_page('article-database','Shared Article Tags',__('Tags'),'manage_options','shared_article_tags_list',array($this,'tag_list'),"dashicons-admin-page");

    add_action("load-$adhook", array($this,'add_ad_screen_options'));
    add_action('load-post.php',array($this,'load_page_post_edit'));
    add_action('load-page.php',array($this,'load_page_post_edit'));

    add_action('load-edit.php', array($this,'post_listing_page'));

  }

  // Synchronize external tag list with the internal one, taking care not to overwrite data.
  private function synchronize_shared_artice_tags () {
   $last = get_option('_wlaa_tag_sync_stamp');
   if ($last && $last > time()-3600) {
    // Only synchronize tags intermittently when new posts haven't arrived.
    return false;
   }
   update_option('_wlaa_tag_sync_stamp',time(),'no');
    
   // Serverside, these are just normal post_tags.
   $res = $this->make_api_call('GET','wp-json/wp/v2/tags/');
   if (is_wp_error($res)) {
    $this->logmessage(__("Could not get tags from shared article server: ",'wlaa-plugin') . $res-->get_error_message());
    return false;
   }
   foreach($res['content'] as $tag) { 
    $args = array();
    if (@$tag['description']) {
     $args['description'] = $tag['description'];
    }

    $exist = term_exists($tag['slug'],'shared_article_tag');
  
    if ($exists) {
      wp_update_term($exists[0],'shared_article_tag',$args);
    } else {
     $args['slug'] = $tag['slug'];
      $newterm = wp_insert_term($tag['name'],'shared_article_tag',$args);
      if (!is_wp_error($newterm)) {
       update_term_meta($newterm[0],"subscribed",false);
       update_term_meta($newterm[0],"in_menu",false);
      }
    }
   }
   $this->logmessage(sprintf(__("Updated shared article tags at %s",'wlaa-plugin'),date("Y-m-d H:i:s")));
   return true;
  }

  public function tag_list() {


   $newsubs = 0;
   // Single actions
   if (isset($_REQUEST['action']) && isset($_REQUEST['id']) && $_REQUEST['action'] && $_REQUEST['id']) {
     $passednonce = $_REQUEST['tagaction'];
     if (!wp_verify_nonce($passednonce,'tagaction')) {
       die("Nonce not valid");
     }

     $id = $_REQUEST['id'];
     $action  = $_REQUEST['action'];
     switch($action) {
      case 'in_menu':
        update_term_meta($id,'in_menu',true);
        break;
      case 'no_menu':
        update_term_meta($id,'in_menu',false);
        break;
      case 'subscribe':
          update_term_meta($id,'subscribed',true);
          $newsubs=1;
          break;
      case 'unsubscribe':
          update_term_meta($id,'subscribed',false);
          break;
     }
   }
   // Multi-actions
   $checked = array();
   if (!empty($_POST) && isset($_POST['action2']) && $_POST['action2']) {
     foreach($_POST['shared_tags'] as $id) {
       $checked[$id]= true;
       switch($_POST['action2']) {
        case 'bulksubscribe':
          update_term_meta($id,'subscribed',true);
          $newsubs=1;
          break;
        case 'bulkunsubscribe':
          update_term_meta($id,'subscribed',false);
          break;
       }
     } 
   }

   if ($newsubs) {
     $this->get_tag_subscriptions();
   }

   // Fetch shared tags from server, map them locally
    $this->synchronize_shared_artice_tags();
    // search,or name_like, description_like, meta_key, meta_value

    $paged = array_key_exists('paged',$_REQUEST) ? max(1,$_REQUEST['paged']) : 1;
    $perpage = 25;
    $order = 'ASC'; 
    $orderby = 'name';
    $orderbymeta = 0;
    $search = '';
 
    if (isset($_REQUEST['orderby'])) {
     $orderby = sanitize_sql_orderby($_REQUEST['orderby']);
    }
    if (isset($_REQUEST['order']) && ($_REQUEST['order'] == 'ASC' ||  $_REQUEST['order'] == 'DESC')) {
     $order = $_REQUEST['order'];
    }
    if ($orderby == 'in_menu' || $orderby == 'subscribed') {
     $orderbymeta = $orderby;
     $orderby= 'meta_value';
    }
    if (isset($_REQUEST['s']) && $_REQUEST['s']) {
     $search = $_REQUEST['s'];
    }

    // Search if request['s'],
    // sorting: legg til klassene sortable, sorted, asc, desc
    // og en <a href="" med full søk i header, og to spans, den ene med navnet, den andre med
    // <span class="sorting-indicator">

    $args = array('taxonomy' => 'shared_article_tag', 'hide_empty' => false);
    if ($search) {
     $args['search'] = $search;
    }

    $big=99999999999;
    $total = wp_count_terms('shared_article_tag',$args);

    $orderargs = array('order'=>$order, 'orderby'=>$orderby);
    if ($orderbymeta) {
     $orderargs['meta_key'] = $orderbymeta;
    } 
    $getargs = array('number'=>$perpage,'offset'=>((1-$paged)*$perpage));
    $tags = get_terms(array_merge($args,$getargs,$orderargs));

    $nonce = wp_create_nonce('tagaction');


?>
<div class="wrap">
<h1><?php _e('Shared Article Tags'); ?></h1>
<form method="post" action="<?php echo admin_url('admin.php?page=shared_article_tags_list'); ?>" >
  <input type="hidden" name="page" value="shared_article_tags_list">
  <p class="search-box">
	<label class="screen-reader-text" for="-search-input"><?php _e('Search'); ?>:</label>
	<input type="search" id="-search-input" name="s" value="<?php echo htmlspecialchars($search); ?>">
	<input type="submit" id="search-submit" class="button" value="<?php _e('Search'); ?>"></p>

 <input type="hidden" id="_wpnonce" name="tagaction" value="<?php echo $nonce; ?>">

<div class="tablenav top">
 <div class="alignleft actions bulkactions">
  <label for="bulk-action-selector-top" class="screen-reader-text"><?php _e('Choose bulk action'); ?></label>
   <select name="action2" id="bulk-action-selector-top">
    <option value="-1"><?php _e('Bulk Actions'); ?></option>
    <option value="bulksubscribe"><?php echo __('Subscribe','wlaa-plugin'); ?></option>
    <option value="bulkunsubscribe"><?php echo __('Unsubscribe','wlaa-plugin'); ?></option>
   </select>
   <input type="submit" id="doaction2" class="button action" value="<?php _e( 'Apply' ); ?>">
 </div>

<div class="tablenav-pages">
 <span class="pagination-links">
<?php
 $url = admin_url('admin.php');
 $args = $_REQUEST;
 unset($args['id']);
 unset($args['action']);
 unset($args['tagaction']);
 unset($args['action2']);
 foreach($args as $key=>$value) {
  if (preg_match("/^shared_tags/",$key)) { 
   unset($args[$key]);
  }
 }

 if ($search) {
   $args['s'] = $search;
 } else {
   unset($args['s']);
 }

 $thispage = "$url?".http_build_query($args);
 unset($args['order']);
 unset($args['orderby']);
 unset($args['paged']);
 $orderlink = "$url?".http_build_query($args);
 $otherorder = $order == 'ASC' ? 'DESC' : 'ASC';

add_filter( 'paginate_links', function( $link ) use ($search) {
    $link =  remove_query_arg( 'tagaction', $link );
    $link =  remove_query_arg( 'action', $link );
    $link =  remove_query_arg( 'id', $link );
    if ($search)  {
     $link = add_query_arg('s',$search,$link);
    } else {
     $link = remove_query_arg('s',$link);
    }
    return $link;
} );
 echo paginate_links(
array(
	'base' => admin_url('admin.php') . '%_%',
	'format' => '?paged=%#%',
	'current' => max( 1, $paged),
	'total' => ceil($total/$perpage))
);?>
</div>
</div>

<table class="wp-list-table widefat fixed striped" cellspacing="0">
    <thead>
    <tr>
            <th id="cb" class="manage-column column-cb check-column" scope="col"></th> 
            <th id="tagnamecolumn" class="manage-column column-tagnamecolumn sortable desc" scope="col">
             <a href="<?php echo $orderlink . "&orderby=name&order=$otherorder" ; ?>"><span><?php _e('Tag','wlaa-plugin'); ?></span><span class="sorting-indicator"></span></a>
            </th>
            <th id="subscribedcolumn" class="manage-column column-subscribedcolumn sortable desc" scope="col">
             <a href="<?php echo $orderlink . "&orderby=subscribed&order=$otherorder"; ?>"><span><?php _e('Subscribed','wlaa-plugin'); ?></span><span class="sorting-indicator"></span></a>
            </th>
            <th id="menucolumn" class="manage-column column-menucolumn sortable desc" scope="col">
             <a href="<?php echo $orderlink . "&orderby=in_menu&order=$otherorder"; ?>"><span><?php _e('Menu','wlaa-plugin'); ?></span><span class="sorting-indicator"></span></a>
            </th>
<!--            <th id="descriptioncolumn" class="manage-column column-descriptioncolumn" scope="col"><?php _e('Description'); ?></th>  -->

    </tr>
    </thead>

    <tfoot>
    <tr>

            <th class="manage-column column-cb check-column" scope="col"></th> 
            <th class="manage-column column-tagnamecolumn sortable desc" scope="col">
             <a href="<?php echo $orderlink . "&orderby=name&order=$otherorder" ; ?>"><span><?php _e('Tag','wlaa-plugin'); ?></span><span class="sorting-indicator"></span></a>
            </th>
            <th class="manage-column column-subscribedcolumn sortable desc" scope="col">
             <a href="<?php echo $orderlink . "&orderby=subscribed&order=$otherorder"; ?>"><span><?php _e('Subscribed','wlaa-plugin'); ?></span><span class="sorting-indicator"></span></a>
            </th>
            <th class="manage-column column-menucolumn sortable desc" scope="col">
             <a href="<?php echo $orderlink . "&orderby=in_menu&order=$otherorder"; ?>"><span><?php _e('Menu','wlaa-plugin'); ?></span><span class="sorting-indicator"></span></a>
            </th>
<!--            <th class="manage-column column-descriptioncolumn" scope="col"><?php _e('Description'); ?></th>  -->


    </tr>
    </tfoot>
    <tbody>



<?php $alternate=1; ?>
<?php foreach ($tags as $tag): 
  $in_menu = get_term_meta($tag->term_id,'in_menu',true);
  $subscribed = get_term_meta($tag->term_id,'subscribed',true);
?>
        <tr class="<?php if ($alternate) echo 'alternate'; $alternate=!$alternate; ?>">
            <th class="check-column" scope="row"><input <?php if (@$checked[$tag->term_id]) echo 'checked'; ?> name="shared_tags[]" value="<?php echo $tag->term_id; ?>"  type="checkbox"></th>
            <td class="column-tagnamecolumn">
  <a href="<?php echo admin_url('admin.php?page=article-database&tagname='.$tag->name);?>">
    <?php if ($subscribed) echo "<b>"; ?>
    <?php echo htmlspecialchars($tag->name); ?>
    <?php if ($subscribed) echo "</b>"; ?>
  </a>
                <div class="row-actions">
<?php if ($subscribed): ?>
<span><a href="<?php echo ($thispage . '&id='.$tag->term_id.'&action=unsubscribe&tagaction='.$nonce);?>">Unsubscribe</a> |</span>
<?php else: ?>
<span><a href="<?php echo ($thispage . '&id='.$tag->term_id.'&action=subscribe&tagaction='.$nonce);?>">Subscribe</a> |</span>
<?php endif; ?>
<?php if ($in_menu): ?>

<span><a href="<?php echo ($thispage . '&id='.$tag->term_id.'&action=no_menu&tagaction='.$nonce);?>">Don't show in menu</a> |</span>

<?php else: ?>

<span><a href="<?php echo ($thispage . '&id='.$tag->term_id.'&action=in_menu&tagaction='.$nonce);?>">Show in menu</a> </span>

<?php endif; ?>
                </div>
            </td>
            <td class="column-subscribedcolumn"><?php if ($subscribed) _e("Yes",'wlaa-plugin'); else _e("No", 'wlaa-plugin'); ?></td>
            <td class="column-menucolumn"><?php if ($in_menu) _e("Yes",'wlaa-plugin'); else _e("No", 'wlaa-plugin'); ?></td>
<!--            <td class="column-descriptioncolumn"><?php echo htmlspecialchars($tag->description); ?></td> -->
        </tr>
<?php endforeach; ?>
    </tbody>
</table>

<div class="tablenav bottom">
 <div class="alignleft actions bulkactions">
  <label for="bulk-action-selector-bottom" class="screen-reader-text"><?php _e('Choose bulk action'); ?></label>
   <select name="action2" id="bulk-action-selector-bottom">
    <option value="-1"><?php _e('Bulk Actions'); ?></option>
    <option value="bulksubscribe"><?php echo __('Subscribe','wlaa-plugin'); ?></option>
    <option value="bulkunsubscribe"><?php echo __('Unsubscribe','wlaa-plugin'); ?></option>
   </select>
   <input type="submit" id="doaction2" class="button action" value="<?php _e( 'Apply' ); ?>">
</div>
</div>
</form>

</div>
</div>
<?php
  }

  public function init () {
    $options = $this->options();

    $this->taxonomy_init();
 
    // Metadata for shared articles.
    register_meta('post','shared_article_data',null,null); // ... sanitize callback, auth-callback, args
    register_meta('page','shared_article_data',null,null); // ... sanitize callback, auth-callback, args

    // This means the pages are actually exported/exportable. Changing it will communicate with the library. 
    register_meta('page','shared_article_exported',null,null);
    register_meta('post','shared_article_exported',null,null);
    // the remote id, so we can delete/undelete articles using the trash
    register_meta('post','shared_article_exported_as',null,null);
    register_meta('page','shared_article_exported_as',null,null);

    foreach(array('ID','description','user_login','user_nicename','user_email','user_url','user_registered','displayname','user_status') as $field) {
     $fun = $this->make_the_author_meta_filter($field);
     add_filter('get_the_author_'.$field,$fun, 10,3);
    }
    add_filter('the_author',array($this,'the_author'), 10,1);
    add_filter('the_modified_author',array($this,'the_author'), 10,1);
    add_filter('author_link',array($this,'author_link'), 10,1);
    add_filter('the_author_posts_link',array($this,'author_link'), 10,1);
    add_action('before_delete_post', array($this,'before_delete_post'), 10, 1 );

    if (!empty($options['footertext'])) {
     add_filter('the_content',array($this,'the_content'),10,1);
    }
  }

  // Filters for the back end
  public function load_page_post_edit (){
    add_action('add_meta_boxes',array($this,'metaboxes'));
    add_action( 'save_post', array($this,'save_post_export'), 999, 2 );
  }
  public function post_listing_page () {
    add_filter('the_title', function ($title,$postid) {
          $sh = get_post_meta($postid,'shared_article_data',true);
          if ($sh) { return $title . ' ' . __('(imported)');}
          return $title;
          },
      10,2);
  }
  

  /* Filters for the front end  */
  public function the_content($content) {
   global $post;
   $sh = get_post_meta($post->ID,'shared_article_data',true);
   if (empty($sh)) return $content;
   $origin = $sh['library'];
   if ($sh['url']) {
    $origin = '<a href="'.esc_url($sh['url']).'">' . esc_html($origin) . '</a>';
   }

   $options = $this->options();
   $template = $options['footertext'];
   $footertext = preg_replace("!ORIGIN!",$origin,$template);

   return $content . "<div class='originallibrary'>$footertext</div>";
  }

  public function the_author($displayname) {
   global $post;
   if (empty($post)) return $displayname;
   $sh = get_post_meta($post->ID,'shared_article_data',true);
   if (empty($sh)) return $displayname; 
   return $sh['author'];
  }
  public function author_link($link) {
   global $post;
   if (empty($post)) return $link;
   $sh = get_post_meta($post->ID,'shared_article_data',true);
   if (empty($sh)) return $link;
   $origurl = $sh['url'];
   if (empty($origurl)) {
    $origurl='';
   }
   $link = "<a href='$origurl'>{$sh['library']}</a>";
   return $link;
  }
  public function make_the_author_meta_filter ($field) {
   return function ($value,$user_id,$original_user_id) use ($field) {
    global $post;
    if (empty($post)) return $value;
    $sh = get_post_meta($post->ID,'shared_article_data',true);
    if (empty($sh)) return $value;
    switch ($field) {
      case 'displayname':
      case 'user_nicename':
       return $sh['author'];
      case 'registered': 
       return false;
      case 'user_email':
      case 'email':
       return "";
      case 'description':
       return "";
      default:
       return null;
    }
   };
 }
 
  public function metaboxes () {
   global $post;
    // Add this only if the article has nonempty shared_article_data 
   if (!empty($post))  {
     $sh = get_post_meta($post->ID,'shared_article_data',true);
    if (!empty($sh)) {
      add_meta_box('subscription',__("Shared Article",'wlaa-plugin'), array($this,'subscription_metabox'),array('post','page'),'side','high');
    }
    // Add this only if the article does not have shared_article_data. 
    if (empty($sh)) {
      add_thickbox();
      add_meta_box('shared_article_export',__('Share this article','wlaa-plugin'),array($this,'share_article_metabox'),array('post','page'),'side','high');  
    }
   }
  }

  // Not really a lock-lock but use the database to avoid
  // running get_updates simultaneously. Should handle error-timeouts and so forth,
  // and is not dependent on flock (ie NFS) or mysql version. 
  public function get_update_lock() {
   global $wpdb;
   // First, ensure the lock options exist
   add_option('_wlaa_lock','','','no');
   add_option('_wlaa_lock_updated',time(),'','no');
   $lockid = uniqid(sha1(serialize($_SERVER)), 1);

   $q = "update {$wpdb->options} set option_value='%s' where option_name='_wlaa_lock' and option_value=''";

   $result = $wpdb->query($wpdb->prepare($q,$lockid));

   if ($result>0) {
    // We got the lock! Now update the timestamp.
    update_option('_wlaa_lock_updated',time(),'no');
    $wpdb->update($wpdb->options,array('option_value'=>time()),array('option_name'=>'_wlaa_lock_updated'));
    return true;
   }
   // We *didnt*, but perhaps the previous locker forgot to update the timestamp, so clear it if it is old.
   $this->logmessage(__("Update already in progress.",'wlaa-plugin'));
   $when = get_option('_wlaa_lock_updated');
   $since = time() - $when;
   if ($since>3700) {
    $this->logmessage(__("Previous update is taking too long time ($since) - releasing the lock.",'wlaa-plugin'));
    $this->release_update_lock();
   }
  }

  // Release the lock.
  public function release_update_lock() {
   global $wpdb;
   $result = $wpdb->query("update {$wpdb->options} set option_value='' where option_name='_wlaa_lock'");
   return $result; 
  }

  public function get_updates() {
   if (!$this->get_update_lock()) {
    return false;
   }
   $options = $this->options();
   if (!$options['connected']) {
     $this->logmessage(__("Could not get updates: Not connected",'wlaa-plugin'));
     $this->release_update_lock();
     return false;
   }
   $when = intval($options['last_updated']);
   $limit = 50;

   $this->logmessage(sprintf(__("Getting updates. Last update was %s -- will fetch up to %d new articles and updates. ",'wlaa-plugin'),
                            date('Y-m-d H:i:s',$when),$limit));


   // First fetch all new articles that we follow by tag. The total counts agaist the limit IOK 2017-04-20
   $i = $this->get_tag_subscriptions();
   if (!$i) $i = 0;

   // This returns all articles that we are subscribed to (as registered on the remote)
   $res = $this->make_api_call('GET','wp-json/shared-article-repository/v2/subscription/',array('since'=>$when));;
   if (is_wp_error($res)) {
    // This will probably be done in cron, so note the error and move on.
    $this->logmessage(__("Error getting updates from shared article server: ",'wlaa-plugin') . $res->get_error_message());
    $this->release_update_lock();
    return false;
   }
   $articlelist = $res['content'];
   $total = $res['meta']['X-WP-Total'];
   $newstamp = $when;
   // Now, potentially the list of updated articles could be huge, so to avoid timeouts, we will only update
   // a subset of them. The list is sorted on date ascending, so oldest updated first.
   $tz = date_default_timezone_get();
   date_default_timezone_set('UTC');
   foreach ($articlelist as $data) {
    $i++;
    $remote = $data['id'];
    $modified = $data['modified_gmt'];
    $ares = $this->update_subscribed_article($remote);
    if (is_wp_error($ares)) {
     // An unknown error while updating is a problem; we don't know what this means,
     // but chances are its temporary, so stop updating.
     $this->logmessage(sprintf(__("Error while updating article with remote id %d - stopping the update. ",'wlaa-plugin'), 
                              $remote) 
                       . $ares->get_error_message());
     break;
    }
    $newstamp = strtotime($modified); 

    // Avoid spending too much time in one period.
    if ($i>$limit) break;
   }
   date_default_timezone_set($tz);

   // Note the last-updated date; if we didn't exhaust the list of articles, we'll do it later. If we updatted everything,
   // use todays' date.
   if ($i==$total) {
    $newstamp=time();
   }
   $this->logmessage(sprintf(__("%d articles updated at %s",'wlaa-plugin'), $i, date("Y-m-d H:i:s", $newstamp)));
 
   $options['last_updated'] = $newstamp;
   update_option('article-adopter_options',$options,false);
   $this->release_update_lock();
   return $i;
  }

  // Get new subscriptions by tag.
  public function get_tag_subscriptions () {
    $i = 0;
    $subscribedtags = get_terms(array('taxonomy' => 'shared_article_tag', 'hide_empty' => false, 'order'=>'ASC', 'orderby'=>'name', 'meta_key'=>'subscribed', 'meta_value'=>true));
    foreach($subscribedtags as $tag) {
      $synched = get_term_meta($tag->term_id,'synched',true);
      $args = array('tagname'=>$tag->name);
      if ($synched) {
       $args['after'] = $synched;
      } 
      $this->logmessage(sprintf(__("Tag %s synched to %s", 'wlaa-plugin'), $tag->name,$synched));
      // A number of articles - default 10 at a time.
      $articles = $this->make_api_call('GET','wp-json/wp/v2/shared_article',$args);
      if (is_wp_error($articles)) {
       $this->logmessage(sprintf(__("Error under subscription to tag %s:",'wlaa-plugin'),$tag->name) . $articles->get_error_message());
       break;
      }
      // Subscribe to all articles we find
      foreach ($articles['content'] as  $article) {
        $date = $article['date_gmt'];
        $remoteid = $article['id']; 
        if (!$this->is_subscribed($remoteid)) {
         $res = $this->subscribe($remoteid);
         if (!$res) {
          $this->logmessage(sprintf(__("Error under subscription to tag %s: No response",'wlaa-plugin'),$tag->name));
         } elseif (is_wp_error($res)) {
          $this->logmessage(sprintf(__("Error under subscription to tag %s id %d:",'wlaa-plugin'),$tag->name,$remoteid) . $res->get_error_message());
         } else {
          $i++;
          update_term_meta($tag->term_id,"synched",$date);
         }
        } 
      }

    } 
    // Count total number of new articles subscribed to by tag.
    return $i;
  }


  public function get_remote($remoteid,$context='view') {
   $remoteid = intval($remoteid);
   if (!$remoteid) return false;
   $endpoint = "wp-json/wp/v2/shared_article/$remoteid";
   $remote = $this->make_api_call('GET',$endpoint,array('context'=>$context));
   if (!$remote || is_wp_error($remote)) return $remote;
   $content = @$remote['content'];
   if (empty($content)) return false;
   return $content; 
  }

  public function is_subscribed($remoteid) {
   global $wpdb;
   $table_name = $wpdb->prefix . "shared_article_subscriptions"; 
   $query = sprintf("select local from `$table_name`  where remote=%d limit 1",$remoteid);
   $res = $wpdb->get_results($query,'ARRAY_A');
   if (!empty($res)) return $res[0]['local'];
   return null;
  }

  public function update_subscribed_article ($remoteid) {
    $local = $this->is_subscribed($remoteid);
    if (!$local) return true; // Update of not-subscribed article succeeds
    $result = $this->subscribe($remoteid,true);
    if (!is_wp_error($result)) return $local;

    $errordata = (array) @$result->get_error_data('rest_error'); 
    switch ($errordata['status']) {
     case 404:
       // delete the article and to the necessary bookkeeping
       $this->unsubscribe($remoteid);
       return false;
       break;
     default:
       return $result;
    }
  }

  public function subscribe($remoteid,$onlyupdate=false) {
   $local = $this->is_subscribed($remoteid);
   // IOK 2016-03-05 Just a safety in case table and metadata has diverged. Errorhandling could be added to restore integirty.
   if ($onlyupdate && !$local) return true; 
 
   $remote = $this->get_remote($remoteid);

   if (!$remote || is_wp_error($remote)) return $remote;


   // Ok so we have the remote article data.  
   $posttype = $remote['shared_article_original_type'];
   if ($posttype != 'post' && $posttype != 'page') return new WP_Error("subscription","Unknown post type $posttype");

   $options = $this->options();
   $localuser = $options['local_author'];
   if (!$localuser) $localuser=1;  // Fallback, just guess.
 
   $postdata = array(); 
   if ($local) {
    $postdata = get_post($local,'ARRAY_A');
   }
   $postdata['post_type'] = $posttype;
   $postdata['post_author'] = $localuser;
   $postdata['post_date'] = $remote['date'];
   $postdata['post_date_gmt'] = $remote['date_gmt'];
   $postdata['post_content'] = $remote['content']['rendered'];
   if (isset($remote['excerpt'])) {
    $postdata['post_excerpt'] = $remote['excerpt']['rendered'];
   }
   $postdata['post_status'] = 'publish';
   $postdata['post_title'] = $remote['title']['rendered'];
   $postdata['post_modified'] = $remote['modified'];
   $postdata['post_modified_gmt'] = $remote['modified_gmt'];
   $postdata['post_guid'] = $remote['guid']['rendered'];
   if (!@empty($remote['shared_article_tags'])) {
    $postdata['tags_input'] = $remote['shared_article_tags'];
   }

   $result = wp_insert_post ($postdata, 'wperror');

   if (is_wp_error($result)) return $result;
   if ($local) {
    $this->logmessage("Updated post '{$postdata['post_title']}' id $result");
   } else {
    $this->logmessage("Subscribed to post '{$postdata['post_title']}' id $result");
   }
   
   $this->add_subscription_metadata($result,$remote);

   if (!empty($remote['shared_article_featured_image'])) {
    $url = $remote['shared_article_featured_image'];
    $this->attach_image($result,$url);
   } else {
    delete_post_thumbnail($result);
   }

   // It's ok if this fails though, so ignore errors.
   $sub = $this->make_api_call('POST', "wp-json/shared-article-repository/v2/subscription/$remoteid");
   return $result;
  }

  // Helper for uploading featured images
  public function upload_tmpnam() {
   $dirinfo = wp_upload_dir();
   $base = $dirinfo['basedir'] . "/wl-aa-media/";
   if (!is_dir($base)) {
    wp_mkdir_p($base);
   }
   if (!is_dir($base)) {
    $this->logmessage(__("Could not create temporary upload directory for importing featured images of shared articles",'wlaa-plugin'));
    return false;
   }
   return wp_tempnam('aa',$base);
  }

  public function logdir() {
   $dirinfo = wp_upload_dir();
   $logdir = $dirinfo['basedir'] . "/wl-aa-log/";
   return $logdir;
  }
  public function logfile() {
   return $this->logdir() . "log.txt";
  }

  // Try to log to a uploads directory. If it fails, log to main error log.
  public function logmessage($message) {
   $message = date("Y-m-d H:i:s") . ": " . sanitize_text_field($message);
   $logpath = $this->logdir();
   $logfile = $this->logfile();
   if (!is_dir($logpath)) {
    wp_mkdir_p($logpath);
   }
   if (!is_dir($logpath)) {
    error_log($message);
    return;
   }
   touch($logfile);
   if (!file_exists($logfile)) {
    error_log($message);
    return;
   }
   // Preserve just 256k of file, copying the old one
   $size = filesize($logfile);
   if ($size>(1024*1024)*0.25) {
    rename($logfile,$logfile . ".old");
   }
   file_put_contents($logfile,$message."\n",FILE_APPEND|LOCK_EX);
  }

  // Print logfiles to screen. 
  public function printlog() {
   $logpath = $dirinfo['basedir'] . "/wl-aa-log/";
   $logfile = $this->logfile();
   $oldfile = $logfile . ".old";

   if (file_exists($oldfile)) {
    readfile($oldfile);
   } 
   if (file_exists($logfile)) {
    readfile($logfile);
   } 
  }
  // Get logdata as a string
  public function getlog () {
   ob_start();
   $this->printlog();
   return ob_get_clean();
  }

  // Attach a featured image locally as a thumbnail
  public function attach_image($postid,$url) {
    if (!$postid || is_wp_error($postid)) return false;
    if (!$url) return false;
 
    $parsed = parse_url($url);
    $filename = sanitize_file_name(basename($parsed['path']));
 
    if (!$filename) {
     $this->logmessage(sprintf(__("Attaching featured image to post %d failed; invalid filename '%s'",'wlaa-plugin'),
                               $postid, $filename));
     return false;
    }
 
    if (!$parsed) {
     $this->logmessage(sprintf(__("Attaching featured image to post %d failed; input not an url",'wlaa-plugin'), $postid ));
     return false;
    }
 
    if (has_post_thumbnail($postid)) {
      $featured = wp_get_attachment_url(get_post_thumbnail_id($postid)); 
      $fp = parse_url($featured);
      if (basename($fp['path']) == $filename) {
        return false; // Assume no change
      }
    }
 
    $tmp = $this->upload_tmpnam();
    if (!$tmp) return false; 
 
    file_put_contents($tmp, fopen($url,'r'));
    if (!file_exists($tmp)) {
     $this->logmessage(sprintf(__("Couldn't download featured image to post %d",'wlaa-plugin'),$postid));
    }
    $validate = wp_check_filetype_and_ext($tmp,$filename);
    if (!preg_match("!^image/!",$validate['type'])) {
     $this->logmessage(sprintf(__("Can't attach featured image to post: %s not an image",'wlaa-plugin'),$filename));
     unlink($tmp); 
     return;
    }
 
    $uploaddir = wp_upload_dir();
    $path = $uploaddir['path'];
    if (empty($path)) {
     $this->logmessage(sprintf(__("Attaching featured image '%s' to post: Could not get upload directory",'wlaa-plugin'),$filename));
     unlink($tmp);
     return false;
    }
    $path = $path . "/" . $filename;
    $exists = 0;
    if (file_exists($path)) {
     // We'll still attach it though, just in case we have some issue with undeleted things
     $this->logmessage(sprintf(__("Attaching featured image %s to post: File exists",'wlaa-plugin'),$path));
     unlink ($tmp);
     $exists = 1;
    } else {
     rename($tmp,$path);
    }
  
    $aid = 0; 
    if (!$exists) { 
      $attachment = array('post_mime_type' => $validate['type'], 
                       'post_title' => $filename,
                       'post_content' => '',
                       'post_status' => 'inherit');
      $aid = wp_insert_attachment($attachment,$path,$postid);
      // Ensure this is loaded, just in case
      require_once( ABSPATH . 'wp-admin/includes/image.php' );
      $adata = wp_generate_attachment_metadata( $aid, $path);
      wp_update_attachment_metadata($aid,$adata);
    } else {

    }
    $aid = $this->get_attachment_id_by_path($filename);
    if ($aid) {
     set_post_thumbnail($postid,$aid);
    }
  }

  public function get_attachment_id_by_path($filename) {
    global $wpdb;
    $uploaddir = wp_upload_dir();
    $prefix = preg_replace("!^/!","",$uploaddir['subdir']);
    $key = "$prefix/$filename";
    $query = $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND  meta_value = %s LIMIT 1",$key);
    $posts = $wpdb->get_results($query, ARRAY_A);
    if (!empty($posts)) {
      $post = $posts[0];
      return $post['post_id'];
    }
    return 0;
  }

  public function unsubscribe ($remoteid) {
   $local = $this->is_subscribed($remoteid);
   if (!$local) return new WP_Error('unsubscribe',__('Not subscribed','wlaa-plugin'));
   // Not calling wp_delete_attachment here because we may have linked to the image .
   // IOK 2016-05-20
   delete_post_thumbnail($local);
   wp_delete_post($local,true);
   $this->remove_subscription_metadata($local);
   $this->logmessage(sprintf(__("Unsubscribing to post %d",'wlaa-plugin'),$local));
   // It's ok if this fails though, so ignore errors.
   $sub = $this->make_api_call('DELETE', "wp-json/shared-article-repository/v2/subscription/$remoteid");
   return true;
  } 

  public function add_subscription_metadata($postid,$remote) {
   global $wpdb;
   $table_name = $wpdb->prefix . "shared_article_subscriptions"; 
   $sh = array();
   $sh['id'] = $remote['id'];
   $sh['author'] = $remote['shared_article_original_author'];
   $sh['library'] = $remote['shared_article_library_name'];
   $sh['url'] = $remote['shared_article_original_url'];
   update_post_meta($postid,'shared_article_data',$sh);

   $updated = date("Y-m-d H:i:s");
   $wpdb->replace($table_name,array('local'=>$postid,'remote'=>$remote['id'],'updated'=>$updated),array('%d','%d','%s'));
  }
  public function remove_subscription_metadata($postid){
   global $wpdb;
   $table_name = $wpdb->prefix . "shared_article_subscriptions"; 
   delete_post_meta($postid,'shared_article_data');
   delete_post_meta($postid,'shared_article_subscribers');
   $wpdb->delete($table_name,array('local'=>$postid),array('%d'));
  }


  // Ensure deleteing/trashing articles also unexports/unsubscribes them.
  public function  before_delete_post($postid) {
     $wasexported = (boolean) get_post_meta($postid,'shared_article_exported',true);
     if ($wasexported) {
       $this->unshare_article(get_post($postid));
       return $postid;
     }

     $sh = get_post_meta($postid,'shared_article_data',true);
     if (!empty($sh))  {
       // We can't call unsubscribe directly, because that would call delete post recursively.
       $remoteid = $sh['id'];
       $this->remove_subscription_metadata($postid);
       $sub = $this->make_api_call('DELETE', "wp-json/shared-article-repository/v2/subscription/$remoteid");
       return $postid;
     }
     return $postid;
  }

  public function save_post_trash($postid,$post) {
     if ($post->post_status != 'trash') return $postid;
     $wasexported = (boolean) get_post_meta($postid,'shared_article_exported',true);
     unset($_REQUEST['shared_article_exported']);
     if ($wasexported) {
      $this->logmessage(sprintf(__("Unsharing trashed article %d '%s' ",'wlaa-plugin'),$postid,$post->post_title));
      $ok = $this->unshare_article($post);
     } else {
      // This was acually an imported article, so we should unsubscribe at once, which will actually delete it.
      // This means we can't recover it from the trash - we *could* by zapping the content etc, but it's easier to drop it 
      // right now. IOK 2016-03-04
      $sh = get_post_meta($postid,'shared_article_data',true);
      if (!empty($sh))  {
       $this->unsubscribe($sh['id']);
       return $postid;
      }
     }
     return $postid;
  }

  public function save_post_export($postid,$post) {

   // Verify the export nonce
   if (!isset( $_POST['shared_article_export_nonce'] ) || !wp_verify_nonce( $_POST['shared_article_export_nonce'], basename( __FILE__ ) ) ) return $postid; 
   $posttype = get_post_type_object($post->post_type);
   if (!current_user_can($posttype->cap->edit_post,$postid)) return $postid;

   // ensure we don't run on revisions in particular
    if ($post->post_type != 'page' && $post->post_type != 'post') {
     return false;
   }
   // and just to be explicit
    if ($post->post_type == 'revision'){
     return false;
   }

   // also, we want stuff to be published
   if ($post->post_status!= 'publish') {
     return false;
   }

   // IOK FIXME *only* do stuff if we are actually connected  
   if (isset($_REQUEST['shared_article_exported'])) {
     $exported = (boolean) $_REQUEST['shared_article_exported'];
     $wasexported = (boolean) get_post_meta($postid,'shared_article_exported',true);
      
     $ok = false;
     if ($exported) {
         if ($wasexported) {
          $this->logmessage(sprintf(__("Updating export of shared article %d '%s'",'wlaa-plugin'), $post->ID,$post->post_title));
         } else {
          $this->logmessage(sprintf(__("Sharing article %d '%s'",'wlaa-plugin'), $post->ID,$post->post_title));
         }
         $ok = $this->share_article($post);
     } elseif ($wasexported) {
         $this->logmessage(sprintf(__("Unsharing article %d '%s'",'wlaa-plugin'), $post->ID,$post->post_title));
         $ok = $this->unshare_article($post);
     }
 
    // if not ok, an error occured during the update at the remote; continue, but preferrably signal the user.

   }

   return $postid;
  }

  public function share_article($post) {
    $postdata = array();

    $remoteid = get_post_meta($post->ID,'shared_article_exported_as',true);

    $endpoint = 'wp-json/wp/v2/shared_article';
    if ($remoteid) {
     $endpoint .= "/$remoteid";
    }

    $author = get_the_author_meta('display_name',$post->post_author);
    $type = $post->post_type;
    $id = $post->ID;

    $tags = array();
    foreach(wp_get_post_tags($id) as $tag) {
     $tags[] = $tag->name;
    }

    $featured = '';
    if (has_post_thumbnail($post->ID)) {
     $featured = wp_get_attachment_url( get_post_thumbnail_id($post->ID) ); 
    } else {
     // The rest API resists deleting metadata from the database in normal cases,
     // so this must be made explicit; and I'm doing it in a stupid way because I want to. IOK 2016-05-20
     $featured = 'WANG_CHUNG_DELETE_ME';
    }

    // The rest API is quite wobbly when parsing dates, so send only utc
//    $postdata['date'] = $post->post_date;
    $postdata['date_gmt'] = $post->post_date_gmt;
    $postdata['content'] = do_shortcode($post->post_content);

    $postdata['status'] = 'publish';
    $postdata['title'] = $post->post_title;
    $postdata['excerpt'] = $post->post_excerpt;
    $postdata['ping_status'] = 'closed';
    $postdata['comment_status'] = 'closed';
    $postdata['shared_article_original_author'] = $author;
    $postdata['shared_article_original_url'] = get_permalink($post);
    $postdata['shared_article_original_type'] = $type;
    $postdata['shared_article_original_id'] = $id;
    $postdata['shared_article_featured_image'] = $featured;
    $postdata['shared_article_tags'] = $tags; // We can't use tags directly, because IDs are returned.

    $remote= $this->make_api_call('POST',$endpoint,$postdata);

    if (is_wp_error($remote)) {
     // There  was an error, so we can't change the 'exported'-status of the article.
     // It might not be fatal though. We probably *should* signal the user, but at least, 
     // we can't update the articles status. But then we need to add messages to session so we 
     // can display them in the update screen.
     $this->logmessage(__("Problems updating remote of shared article: ",'wlaa-plugin') . $remote->get_error_message());
     return false;
    }
    $remotepost = $remote['content'];
    update_post_meta($post->ID,'shared_article_exported_as',$remotepost['id']);
    update_post_meta($post->ID,'shared_article_exported',true);
    return true;
  }

  // This will place the remote article in the trash can; it should *not* delete them as that
  // will make it hard for subscribers to remove the article.
  public function unshare_article($post) {
    $postdata = array();
    $remoteid = get_post_meta($post->ID,'shared_article_exported_as',true);
    if (!$remoteid) return false;

    $endpoint = "wp-json/wp/v2/shared_article/$remoteid";

    // Important that we do *not* actually delete the article.
    $args = array('force'=>false);
    $remote = $this->make_api_call('DELETE',$endpoint,$args);


    if (is_wp_error($remote)) {
     // There  was an error, so we can't change the 'exported'-status of the article.
     // It might not be fatal though. We probably *should* signal the user, but at least, 
     // we can't update the articles status. But then we need to add messages to session so we 
     // can display them in the update screen.
     $errordata = (array) @$remote->get_error_data('rest_error'); 
     if ($errordata['status'] ){
      $this->logmessage(__("Shared article already unexported.",'wlaa-plugin'));
      update_post_meta($post->ID,'shared_article_exported',false);
      return true;
     }
     $this->logmessage(__("Problems deleting shared article: ",'wlaa-plugin') . $remote->get_error_message());
     return false;
    }
    update_post_meta($post->ID,'shared_article_exported',false);
    return true;
  }


  public function subscription_metabox ()  {
    global $post;
    $sh = get_post_meta($post->ID,'shared_article_data',true);
    $msg = sprintf(__('This article is originally by %s from %s . Edits will be overwritten by data from the repository.', 'wlaa-plugin'),
                   $sh['author'],$sh['library']);
                  
    print "<p>$msg</p>";
    $options = $this->options();
    $connected = $options['connected'];
    if (!$connected) {
     print "<p><b>" . __("You are not currently connected to the library, so synchronization will not happen.",'wlaa-plugin') . "</p>";
    }
  }
  public function share_article_metabox() {
    global $post;
    $exported = get_post_meta($post->ID,'shared_article_exported',true);
    $as = get_post_meta($post->ID,'shared_article_exported_as',true);
    $subscribers = 0;


    $selected = '';
    if ($exported) $selected="checked='checked'";
 
    $options = $this->options();
    $connected = $options['connected'];

    if ($exported) {
     $subscribers = intval(get_post_meta($post->ID,'shared_article_subscribers',true));
     if ($connected) {
       if ($as) {
        $res = $this->make_api_call('GET','wp-json/shared-article-repository/v2/subscriptions/'.$as);
        if (is_wp_error($res)) {
          $this->logmessage(sprintf(__("Problems getting subscription count of article %d: ",'wlaa-plugin'),$post->ID) . $res->get_error_message());
        } else {
          $subscribers = intval($res['content']);
          update_post_meta($post->ID,'shared_article_subscribers',$subscribers);
        }
      }
     }
    }

    $disabled = !$connected ? " disabled='disabled' " : "";
    wp_nonce_field( basename( __FILE__ ), 'shared_article_export_nonce' );

?>
<div id="share-article-copyright-warning" style="display:none;">
    <h2><?php _e('Are you sure?','wlaa-plugin'); ?></h2>
 <p><?php _e('Before sharing, please ensure that you own the copyright or have permission to share the material in the article, including any featured image. The responsibilty for ensuring this lies entirely on the actor sharing the article.','wlaa-plugin');?></p>
<p>
<a onclick="document.getElementById('share-checkbox').checked=true;tb_remove();return false" class="button button-primary"> <?php _e('Yes','wlaa-plugin');?> </a>
<a onclick="tb_remove();return false" class="button button-secondary"> <?php _e('No','wlaa-plugin'); ?> </a>
</p>
</div>
 <input type=hidden name='_exported_as' value='<?php echo $as; ?>'>
 <p><?php  _e("Selecting the checkbox below will export this article to the shared article database, enabling other cooperating sites to 'adopt' it",'wlaa-plugin'); ?></p>
 <p> <input type=hidden <?php echo $disabled; ?> name=shared_article_exported value=0> <input id=share-checkbox name=shared_article_exported <?php echo $disabled; ?> <?php echo $selected; ?> type=checkbox><?php _e('Share this article','wlaa-plugin'); ?></p>
<p><b><?php _e('Please ensure you own the copyright or have permission to share all content in this article','wlaa-plugin'); ?></b></p>
<?php  if (has_post_thumbnail($post->ID)) : ?>
<p>
 <strong>
  <?php _e("Since this post has an attached featured image, this too will be shared and downloaded by clients if shared",'wlaa-plugin'); ?>
</strong>
</p>
<?php endif; ?>
<?php if ($exported) : ?>
<p>
 <?php echo sprintf(__("This post has %d subscribers","wlaa-plugin"), $subscribers); ?>
</p>
<?php endif; ?>
<style>
 /* thickbox is of course also buggy and will ignore the size parameters as set below. IOK 2017-04-11 */
 #TB_window { height: auto !important};
</style>
<script>
 jQuery('#share-checkbox').change(function () {
  if (jQuery(this).prop('checked')) {
   jQuery(this).prop('checked',false);
   tb_show('<?php _e('Sharing the article','wlaa-plugin'); ?>','#TB_inline?foo=4&height=400&inlineId=share-article-copyright-warning');
  }
 });
</script>
<?php
  }


  public function remote_categories () {
   // Per invocation, call just once
   if ($this->categories) return $this->categories;
   $stored = get_transient('aa_remote_categories');
   if (!$stored) {
    $stored = array();
    $res = $this->make_api_call('GET','wp-json/wp/v2/categories',array('per_page'=>100,'hide_empty'=>true));
    if (is_array($res['content'])) {
     foreach($res['content'] as $entry) {
      $stored[$entry['id']] = $entry;
     }
    }
   }
   $this->categories = $stored;
   // Store for one hour.
   set_transient('aa_remote_categories',$stored,60*5);
   return $stored;
  }

  public function article_list () {
    if (!is_admin() || !current_user_can('manage_options')) {
      die("Insufficient privileges");
  }
  $list = new AAListTable($this);

  $action = $list->current_action();
  $results = array();
  if ($action) {
   switch ($action) {
    case 'subscribe':
     $articles = $_REQUEST['article'];
     foreach($articles as $a) {
      $results[$a] = $this->subscribe($a);
     } 
     break;
    case 'unsubscribe':
     $articles = $_REQUEST['article'];
     foreach($articles as $a) {
      $results[$a] = $this->unsubscribe($a);
     } 
     break;
   default:
     break;
   }
  }

  // Feed the plugins with translatable text.
  $a = __('subscribe');
  $b = __('unsubscribe');

  $admin_notices = '';
  foreach($results as $a=>$result) {
   if (!$result || is_wp_error($result)) {
     $msg = sprintf(__("Article with id %d couldn't be '%s'-ed due to an error",'wlaa-plugin'),$a,__($action,'wlaa-plugin'));
     $admin_notices .= "<div class='error error-notice'>$msg :";
     if (is_wp_error($result)) $notices .= esc_html($result->get_error_message());
     $admin_notices .= "</div>";
   }
  }
  if (!empty($admin_notices)) {
    add_action('admin_notices',function () use ($admin_notices) { echo $admin_notices; });
  }

  $cats = $this->remote_categories();
  $current_cat = @$_REQUEST['cat'];
 
  $list->prepare_items();

 ?>
<div class='wrap'>
 <div id='icon-users' class='icon32'></div>
 <h2><?php _e('Article Database','wlaa-plugin'); ?></h2>
 <?php do_action('admin_notices'); ?>

<?php if ($_GET['tagname']): ?>
<div>
 <p>
 <?php echo sprintf(__("Showing articles tagged '%s . '",'wlaa-plugin'),"<b>".htmlspecialchars($_GET['tagname']))."</b>"; ?>
 <a href="<?php echo $_SERVER['PHP_SELF']; ?>?page=article-database"><?php _e('Show all');?></a>
 </p>
</div>
<?php endif; ?>

 <form method="post">
  <input type="hidden" name="page" value="article-database" />
  <?php $list->search_box(__('search', 'wlaa-plugin'),'wlaa'); ?>


<div style='float:right; margin-right:30px;'>
   <select name='cat' id='cat' class='postform'>
    <option value='0' class='level-0'><?php _e('All categories','wlaa-plugin');?></option>
   <?php foreach($cats as $k=>$cat): ?>
    <?php if (!$cat['count']) next; ?>
    <option <?php if ($current_cat == $k) echo ' selected '; ?> class='level-0' value='<?php echo esc_attr($k); ?>'><?php echo esc_html($cat['name']); ?></option>
   <?php endforeach; ?>
  </select>
<input type="submit" name="filter_action" id="post-query-submit" class="button" value="<?php _e('Filter'); ?>">
</div>

<div style="float:right;margin-right:30px"> <a class='button' href="<?php echo $_SERVER['PHP_SELF']; ?>?page=article-database"><?php _e('All');?></a> </div>

 <?php $list->display(); ?>
 </form>

</div>
<?php
  }

  public function add_ad_screen_options  () {
     $list = new AAListTable($this); // Magically generates column data

     $option = 'per_page';
     $args = array('label' => __('Articles per page'),
                  'default' => 10,
                  'option'  => 'articles_per_page');
     add_screen_option($option,$args);
  }
  public function set_ad_screen_options($status,$option,$value) {
   return $value;
  }

  // This is to be run by the every-ten-minutes cronjob.
  public function update_shared_articles () {
    $this->logmessage(__("Getting updates from wp-cron.",'wlaa-plugin'));
    $this->get_updates();
  }
 
  // Update every tenth minute
  public function cron_schedules ($schedules) {
   $schedules['tenminutes'] = 
     array(
      'display' => __( 'Every ten minutes','wlaa-plugin'),
      'interval' => 600,
     );
     return $schedules;
  }

  public function activate () {
    $userid=0;
    load_plugin_textdomain('wlaa-plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    $users= get_users(array('role'=>'administrator','orderby'=>'ID','order'=>'ASC'));
    if (!empty($users)) $userid=$user->user_id;

    $default = array('connected'=>false,'pubkey'=>null,'username'=>null,'server'=>null,'last_update'=>0,'local_author'=>$userid,'footertext'=>'');
    add_option('article-adopter_options',$default,false);
    $this->db_tables();
    wp_schedule_event(time(),'tenminutes','update_shared_articles');
  }

  public function db_tables() {
   global $wpdb;
   $options = $this->options();
   if ($options['dbversion']==$this->dbversion) return false;

   $charset_collate = $wpdb->get_charset_collate();
   $table_name = $wpdb->prefix . "shared_article_subscriptions"; 

   $sql = "CREATE TABLE $table_name (
  local bigint(20) NOT NULL,
  remote bigint(20) NOT NULL,
  updated datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
  UNIQUE KEY local (local),
  UNIQUE KEY remote (remote)
) $charset_collate;";

   require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
   dbDelta( $sql );
   $options['dbversion']=$this->dbversion;
   update_option('article-adopter_options',$options,false);
   return true; 
  }

  public function deactivate () {
   global $wpdb;
   load_plugin_textdomain('wlaa-plugin',false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
   wp_clear_scheduled_hook('update_shared_articles');
    // De-export all articles, unsubscribe all subscribed articles.
   global $wpdb;
   $q = "select post_id from {$wpdb->postmeta} where meta_key='shared_article_exported' and meta_value=1";
   $res = $wpdb->get_results($q,ARRAY_N);
   if (!is_wp_error($res)) {
    foreach($res as $entry) {
     $p = get_post($entry[0]);
     $this->unshare_article($p);
    }
   }

   $table_name = $wpdb->prefix . "shared_article_subscriptions"; 
   $q = "select remote from {$table_name}";
   $res = $wpdb->get_results($q,ARRAY_N);
   if (!is_wp_error($res)) {
    foreach($res as $entry) {
     $remoteid = get_post($entry[0]);
     $this->unsubscribe($remoteid);
    }
   }

   $table_name = $wpdb->prefix . "shared_article_subscriptions"; 
   $q = "select remote from {$table_name}";
   $res = $wpdb->get_results($q,ARRAY_N);
   if (!is_wp_error($res)) {
    foreach($res as $entry) {
     $remoteid = $entry[0];
     $this->unsubscribe($remoteid);
    }
   }

  }
  public function uninstall() {
   global $wpdb;
   load_plugin_textdomain('wlaa-plugin',false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
   delete_option('article-adopter_options');
   $table_name = $wpdb->prefix . "shared_article_subscriptions"; 
   $sql = "DROP TABLE $table_name";
   $wpdb->query($sql);
  }

  public function plugins_loaded() {
   load_plugin_textdomain('wlaa-plugin',false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
  }

 private function taxonomy_init() {
  $this->shared_article_tag_taxonomy();
 }

 /*  Taxonomies */

 // This taxonomy shadows the 'post_tag' taxonomy, but contains the tags actually on
 // the database server. These aren't actually neccessary to attach to posts, but 
 // they should hold metadata about which are to be displayed in the Article Database menu,
 // and which are to be autosubscribed to. IOK 2017-04-12
 private function shared_article_tag_taxonomy() {
        $labels = array(
                'name'                       => __('Shared Article Tag','wlaa-plugin'),
                'singular_name'              => __('Shared Article Tag','wlaa-plugin'),
                'menu_name'                  => __('Shared Article Tag','wlaa-plugin')
        );
        $capabilities = array(
                'manage_terms'               => 'manage_categories',
                'edit_terms'                 => 'manage_categories',
                // Doing this won't actually help as they will be synched from the server,
                // but hey.
                'delete_terms'               => 'manage_categories',
                // Don't allow these terms to be assigned to objects - they actually refer to no objects at all. 
                // IOK 2016-02-18
                'assign_terms'               => 'nobody_can_do_this'
        );
        $args = array(
                'labels'                     => $labels,
                'hierarchical'               => false,
                'public'                     => false,
                'show_ui'                    => false,
                'show_admin_column'          => false,
                'show_in_menu'               => false,
                'show_in_nav_menus'          => false,
                'show_tagcloud'              => false,
                'capabilities'               => $capabilities
        );
        // Actually, the tax should refer to the post_tag taxonomy instead of posts.
        register_taxonomy( 'shared_article_tag', array( 'post','page' ), $args );
 }
 /* End taxonomies */

  // Options screen and handling
  public function validate ($input) {
   $current =  $this->options();
   $valid = $current; // Is a copy in php
   foreach($input as $k=>$v) {
     switch ($k) {
      case 'reset_last_updated':
       $t = strtotime($v);
       $this->logmessage(sprintf(__("Resetting last updated to '%s' ",'wlaa-plugin'), date("Y-m-d H:i:s",$t)));
       $valid['last_updated'] = $t;
       unset($valid['reset_last_updated']);
       break;
      case 'server':
       $v = esc_url_raw($v);
       $components = @parse_url($v);
       if (!empty($components)) {
        $valid[$k] = $v;
       } 
       break;
      case 'username':
       $v=preg_replace("![^a-zA-Z0-9-_]!","",$v); 
       $valid[$k] = $v;
      case 'local_author':
       $id = intval($v);
       $user = get_userdata($id);
       if (!empty($user)) {
        $valid[$k] = $id;
       }
       break;
      default:
      $valid[$k] = $v;
     }
    }
   if (!$valid['pubkey'] || $valid['username'] != $current['username'] || $valid['server'] != $current['server']) {
    $valid['connected'] = false;
   }
   return $valid;
  }

  public function toolpage () {
    if (!is_admin() || !current_user_can('manage_options')) {
      die(__("Insufficient privileges"));
  }
  add_thickbox();
  $options = $this->options();

  if (isset($_GET['tab'])) {
   $tab = $_GET['tab'];
  } else {
   $tab = 'tab-options';
  }

  if (!$options['pubkey'] && $options['username'] && $options['server']) {
   list($pubkey,$privkey) = $this->generate_keys();
   $options['pubkey'] = $pubkey;
   update_option('article-adopter_options',$options,false);
   update_option('article-adopter_privkey',$privkey,false);
  }

  $connected = $options['connected'];

  if (isset($_REQUEST['settings-updated']) && $_REQUEST['settings-updated']) {
   if ($connected) {
    $this->logmessage(__("Getting updates on options save",'wlaa-plugin'));
    $updatedno = $this->get_updates();
    $options = $this->options(); // Because this changes last_updated at least. IOK 2017-04-10
  
    if ($updatedno) {
     add_action('admin_notices',function () use ($updatedno) {
       $msg = sprintf(__("Updated %d articles",'wlaa-plugin'),$updatedno);
       echo "<div class='notice notice-info is-dismissible'><p>$msg</p></div>"; 
     });
    }
   }
  }

?>
<style>
 .tab { display: none}
 .tab.active { display:inherit}
</style>
<script>
 jQuery(document).ready(function () {

function getquery() {
 q = window.location.search;
 if (q=='') return {};
 q = q.substring(1);
 var result = {};
 var vars = q.split('&');
 for (var i=0; i<vars.length; i++) {
  var pair = vars[i].split('=');
  result[pair[0]] = pair[1];
 }
 return result;
}
function setquery(name,value) {
 var params = getquery();
 params[name]=value;
 return '?' + jQuery.param(params);
}


  jQuery('.nav-tab-wrapper a').click(function (e) {
   e.preventDefault();
   jQuery('.nav-tab-wrapper a').removeClass('nav-tab-active');
   jQuery('.tab.active').removeClass('active');
   jQuery(this).addClass('nav-tab-active');
   jQuery('.'+jQuery(this).data('tab')).addClass('active');
   jQuery('#logbox').scrollTop(jQuery('#logbox').prop('scrollHeight'));
   if (history.pushState) {
    var newurl = window.location.protocol + "//" + window.location.host + window.location.pathname + setquery('tab',jQuery(this).data('tab'));
    window.history.pushState({path:newurl},'',newurl);
   }

  });
 });
</script>
<div class='wrap'>
 <h2><?php _e('Article Adopter','wlaa-plugin'); ?></h2>



<?php do_action('admin_notices'); ?>


<h2 class="nav-tab-wrapper">
    <a href="#" class="nav-tab <?php if ($tab == 'tab-options') echo 'nav-tab-active'; ?>" data-tab="tab-options"><?php _e('Options','wlaa-plugin'); ?></a>
    <a href="#" class="nav-tab <?php if ($tab == 'tab-log') echo 'nav-tab-active'; ?>" data-tab="tab-log"><?php _e('Log','wlaa-plugin'); ?></a>
</h2>


<div class="tab tab-options <?php if ($tab=='tab-options') echo ' active '; ?>">
<form action='options.php' method='post'>
<?php settings_fields('article-adopter_options'); ?>
 <table class="form-table" style="width:100%">
   <tr>
    <td><?php _e('Server','wlaa-plugin'); ?></td><td><input id='server' style="width:20em" name='article-adopter_options[server]' type="url" value="<?php echo esc_url($options['server']); ?>" /></td>
    <td><?php _e('URL to article repository','wlaa-plugin'); ?></td>
   </tr>
   <tr>
    <td><?php _e('Username','wlaa-plugin'); ?></td><td><input id='username' style="width:20em" name='article-adopter_options[username]' pattern="[A-Za-z0-9-_]+" type="text" value="<?php echo esc_attr($options['username']); ?>" /></td>
    <td><?php _e('Username at article repository','wlaa-plugin'); ?></td>
   </tr>
   <tr>

   <tr>
    <td><?php _e('Import to local user','wlaa-plugin'); ?></td>
    <td> 
    <?php wp_dropdown_users(array('selected'=>$options['local_author'],'orderby'=>'ID','order'=>'asc','name'=>'article-adopter_options[local_author]','who'=>'authors')); ?>
    </td>
    <td><?php _e('This local user will be owner of all imported articles','wlaa-plugin'); ?></td>
   </tr>

   <tr>
    <td><?php _e('Text to add to the bottom of the articles','wlaa-plugin'); ?></td>
    <td> 
<input id='footertext' style="width:30em" name='article-adopter_options[footertext]' type="text" value="<?php echo esc_attr($options['footertext']); ?>" />
    </td>
    <td><?php _e('Add this text to the bottom of all articles adopted.','wlaa-plugin'); ?><br><?php _e('The text ORIGIN will be replaced with originating article','wlaa-plugin'); ?></td>
   </tr>


    <td><?php _e('Connection Key','wlaa-plugin'); ?></td><td><div><textarea readonly name='ignore' style="font-family: monospace" cols=75 id=pubkeyelement><?php if ($options['pubkey']) { echo $options['pubkey']; } else { _e(" Enter server and username to generate key",'wlaa-plugin');} ?></textarea></td>
    <td><?php _e('Copy this connection key and paste it in Library Data on the server'); ?></td>
   </tr>
 </table>
<p class="submit">
  <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
 </p>
</form>
<div>

<a class="button-secondary" id=connectkey><?php _e('Connect','wlaa-plugin'); ?></a>
<a class="thickbox button-secondary" href="#TB_inline?width=600&height=550&inlineId=modal-gen-key"><?php _e('Generate New Key','wlaa-plugin'); ?><a>
</div>


<div id="modal-gen-key" style="display:none;">
  <h2><?php _e('Generating a new key','wlaa-plugin'); ?></h2>
  <h3><?php _e('Are you certain?','wlaa-plugin'); ?></h3>
   <p><?php _e('Generating a new key will invalidate your connection; and you will have to register the new connection key at the server.','wlaa-plugin'); ?><p>
<p class="submit">
<form action='options.php' method='post'>
<?php settings_fields('article-adopter_options'); ?>
  <input type="hidden" name="article-adopter_options[pubkey]" value="">
  <input type="submit" class="button-secondary" id=generatekey value="<?php _e('OK, go ahead','wlaa-plugin'); ?>">
  <a href="#" onclick="tb_remove();" class="button-primary" id="close-gen-key"><?php _e('No'); ?></a>
</form>
</p>
</div>
</div> <!-- end tab -->
<div class="tab tab-log <?php if ($tab == 'tab-log')  echo ' active ';?>  ">
<h3>Log</h3>
<p>
<form action='options.php' method='post'>
<?php settings_fields('article-adopter_options'); ?>
<?php _e('Last update was','wlaa-plugin'); ?>  <input style="font-weight: bold;" type="datetime-local" step=1 
    name="article-adopter_options[reset_last_updated]" value="<?php echo date("Y-m-d\TH:i:s.0",$options['last_updated']); ?>">
 <input type="submit" class="button-primary" value="<?php _e('Reset last-updated timestamp and re-synchronize articles','wlaa-plugin'); ?>">
</form>
</p>
<p><?php _e('Logfile location:','wlaa-plugin'); ?> <?php echo $this->logfile(); ?></p>

<pre id="logbox" style="width:80%;padding: 10px;;min-height: 2em;max-height:50vh;overflow-y: auto; border: 1px solid black;background-color:white">
<?php $this->printLog(); ?>
</pre>
<script>
 jQuery('#logbox').scrollTop(jQuery('#logbox').prop('scrollHeight'));
</script>

</div>
<?php
  }

  // Ajax
  function ajax_connect_to_server() {
    check_ajax_referer('aa-ajax','nonce');
    if (!current_user_can('manage_options')) {
     wp_die(__("Insufficient privileges"));
    }
    $this->logmessage(__("Connecting to server",'wlaa-plugin'));

    $res = $this->make_api_call('GET','wp-json/wp/v2/shared_article',array(),'connect'); 
    $resp = array('msg'=>__('Could not connect - check configuration','wlaa-plugin'),'ok'=>0);
    if (!$res) {
     print json_encode($resp);
     exit();
    }
    if (is_wp_error($res)) {
      $resp['msg'] = __("Could not connect. Server responded",'wlaa-plugin') . "'" . $res->get_error_message() . "'"; 
      $this->logmessage($resp['msg']);
      print json_encode($resp);
      exit();
    }
    $resp['ok']=1;
    $resp['msg'] = __("You are now connected",'wlaa-plugin');;
    $this->logmessage(__("Connected.",'wlaa-plugin'));

    $options = $this->options();
    $options['connected'] = true;
    update_option('article-adopter_options',$options,false);
    print json_encode($resp);
    exit();
  }

  // end ajax

  // IOK 2016-02-19 This as well as the corresponding handling in the server should be replaced
  // with libsodium when possible.
  function generate_keys() {
    $home = get_home_url();
    $domain = parse_url($home,PHP_URL_HOST);
    if (!$domain) {
     // Can probably not happen but still.
     wp_die(__("Could not get domain from site home! Is it a valid URL?",'wlaa-plugin'),__("Could not create key",'wlaa-plugin'),array('back_link'=>true));
    }
    $res = openssl_pkey_new (array('commonName'=>$domain,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>4096));
    $privkey = null;
    openssl_pkey_export($res,$privkey);
    $pubkey = openssl_pkey_get_details($res);
    $pubkey = $pubkey['key'];
    return array($pubkey,$privkey);
  }

  // IOK 2016-02-19 This as well as the corresponding handling in the server should be replaced
  // with libsodium when possible.
  function make_api_call($method='POST',$endpoint="wp-json/wp/v2/shared_article",$callargs=array(),$connect=0) {
      global $http_response_headers;
      $options = $this->options();
  

      // If this ini-setting is off, the plugin won't work. IOK 2017-01-17 
      if (!ini_get('allow_url_fopen')) {
       return new WP_Error('rest_error', __('Could not connect - please set allow_url_fopen to true in php.ini on client','wlaa-plugin'),array('status'=>0));
       die(json_encode($resp));
      }

      if (!$connect && !$options['connected']) return false;

      $server = $options['server'];
      if(empty($server)) {
       $options['connected'] = false;
       update_option('article-adopter_options',$options,false);
       return false;
      }
      $url = trailingslashit($server) . $endpoint;
      $privkey = get_option('article-adopter_privkey');
      if (empty ($privkey)) {
       $options['connected'] = false;
       update_option('article-adopter_options',$options,false);
       return false;
      }

      $userid=$options['username'];
      if (empty ($userid)) {
       $options['connected'] = false;
       update_option('article-adopter_options',$options,false);
       return false;
      }
 
      $ts = time();
      $rand=bin2hex(openssl_random_pseudo_bytes(8));
      $defargs = array('_clientuserid'=>$userid,'_rand'=>$rand,'_ts'=>$ts);
      $args = array_merge($callargs,$defargs);

      $q = '';
      if (in_array($method,array('GET','DELETE','OPTIONS'))) {
       $q = http_build_query($args);
      } else {
       $q = json_encode($args);
      }
      $pkey = openssl_get_privatekey($privkey);
      if (!$pkey) {
       $options['connected'] = false;
       update_option('article-adopter_options',$options,false);
       return false;
      }

      $sig= '';
      $opts=null;
      openssl_sign($q,$sig,$pkey,OPENSSL_ALGO_SHA512);
      openssl_free_key($pkey);
      $sig = base64_encode($sig);
      $auth = base64_encode("$userid:$ts:$rand:$sig");
      if ($method=='GET' || $method=='DELETE' || $method=='OPTIONS') {
        $opts = array(
          'http'=>array(
            'method'=>$method,
             // Apache will normally strip auth headers. We pass both, to avoid getting cached
            'header'=> "User-agent: BROWSER-DESCRIPTION-HERE\r\n" . "Authorization: LIBDBSIGNED $auth\r\n" . "X-Authorization: LIBDBSIGNED $auth\r\n",
            'ignore_errors'=>true
         )
        );
        $url = $url."?$q";
      } elseif ($method=='POST' || $method=='PUT') {
        $opts = array(
         'http' => array(
             'header'  => "Content-type: application/json\r\n" ."Authorization: LIBDBSIGNED $auth\r\n" . "X-Authorization: LIBDBSIGNED $auth\r\n",
             'method'  => $method,
             'content' => $q,
             'ignore_errors'=>true
         ),
        );
      }


      $context = stream_context_create($opts);
      $content = file_get_contents($url, false, $context);
      $resp = $http_response_header[0];

      preg_match( "#HTTP/[0-9\.]+\s+([0-9]+)#",$resp, $out);
      $status = $out[1];
      if ($status>199 && $status<300) {
       $payload = json_decode($content,true);
       $meta = array();
       for($i=1;$i<count($http_response_header);$i++){
        list($k,$v) = explode(":",$http_response_header[$i]);
        $meta[trim($k)] = trim($v);
       }
       return array('meta'=>$meta,'content'=>$payload);
       return json_decode($content,true);
      } elseif ($status == '401') {
        // Forbidden, som we must disconnect.
        $options['connected'] = false;
        update_option('article-adopter_options',$options,false);
      }
      $dec = @json_decode($content,true);
      if (is_array($dec)) {
       return new WP_Error('rest_error',$dec['message'],array('status'=>$status));
      } else {
       return new WP_Error('rest_error',$content,array('status'=>$status));
      }
  }

  function admin_footer() {
   $nonce = wp_create_nonce('aa-ajax');
?>
<script type="text/javascript" >

function aa_add_notice(classes,msg) {
 var wrapper = jQuery('#wpbody-content .wrap');
 var header = wrapper.children(':header:first-child');
 var msgel = "<div class='notice " + classes + "'><p>"+msg+"</p></div>";
 if (header.length==1) {
  header.after(msgel);
 } else if (wrapper.length>0) {
  wrapper.prepend(msgel);
 }
 // Copied from non-resuable WP common.js
 jQuery( '.notice.is-dismissible' ).each( function() {
                        var jQueryel = jQuery( this ),
                                jQuerybutton = jQuery( '<button type="button" class="notice-dismiss"><span class="screen-reader-text"></span></button>' ),
                                btnText = commonL10n.dismiss || '';

                        // Ensure plain text
                        jQuerybutton.find( '.screen-reader-text' ).text( btnText );
                        jQuerybutton.on( 'click.wp-dismiss-notice', function( event ) {
                                event.preventDefault();
                                jQueryel.fadeTo( 100, 0, function() {
                                        jQueryel.slideUp( 100, function() {
                                                jQueryel.remove();
                                        });
                                });
                        });

                        jQueryel.append( jQuerybutton );
                });

}


 jQuery(document).ready(function($) {

  jQuery('#connectkey').click(function (e) {        
    e.preventDefault();
    var data = {
     'action': 'aa_connect',
     'nonce': '<?php echo $nonce; ?>'
    };
    jQuery.ajax({'url': ajaxurl,
     'dataType':'json',
     'data': data, 
     'error': function (xhr, stat,err) {
       jQuery('.aa-connected').hide();
       jQuery('.aa-error').hide();
       aa_add_notice('notice-error aa-error is-dismissible', "<?php _e("Error connecting: ",'wlaa-plugin'); ?>" + err);
     },
     'success': function(response) {
       jQuery('.aa-connected').hide();
       jQuery('.aa-error').hide();
       if (response['ok']) {
         jQuery('.aa-not-connected').hide();
         aa_add_notice('notice-info is-dismissible aa-connected','<?php _e('OK! You are now connected','wlaa-plugin'); ?>');
       } else {
         aa_add_notice('notice-error is-dismissible aa-error',response['msg']);
       }
     }
    });
  });
       

});


</script> 

<?php
  }
}

global $ArticleAdopter;
$ArticleAdopter = new ArticleAdopter();
register_activation_hook(__FILE__,array($ArticleAdopter,'activate'));
register_deactivation_hook(__FILE__,array($ArticleAdopter,'deactivate'));
register_uninstall_hook(__FILE__,array($ArticleAdopter,'uninstall'));

add_action('init',array($ArticleAdopter,'init'));
add_filter('cron_schedules',array($ArticleAdopter,'cron_schedules'));
add_filter('plugins_loaded',array($ArticleAdopter,'plugins_loaded'));
add_action('update_shared_articles',array($ArticleAdopter,'update_shared_articles'));

if (is_admin()) {
 add_action('admin_init',array($ArticleAdopter,'admin_init'));
 add_action('admin_menu',array($ArticleAdopter,'admin_menu'));
} else {
}


// Can't get this to work within the class it seems.
add_filter('set-screen-option','aa_ad_set_screen_options',10,3);
function aa_ad_set_screen_options ($status,$option,$value) {
   return $value;
}

?>
