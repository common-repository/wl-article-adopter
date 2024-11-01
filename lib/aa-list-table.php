<?php
/*
    This file is part of the WordPress plugin WL Article Adopter
    Copyright (C) 2016 Iver Odin Kvello

    WL Article Adopter is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    WL Article Adopter is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/


if ( ! class_exists( 'AA_Base_List_Table' ) ) {
	require_once('aa-base-list-table.php');
}
class AAListTable extends AA_Base_List_Table {
   // Used to call the remote db
   var $articleadopter = null;
   var $lastres = null;


   public function __construct($articleadopter=null, $args = array() ) {
     $args['plural'] = __('Articles');
     $args['singular'] = __('Articles');
     $this->articleadopter = $articleadopter;
     return parent::__construct($args);
   }

  private function remote_get_items($args=array()) {
   $args['context'] = 'view'; //  To get stuff like last modified. Also content gets sent currently.
   $res =  $this->articleadopter->make_api_call('GET','wp-json/wp/v2/shared_article',$args);
   $this->lastres = $res;
   return $res;
  }

  
  function get_columns(){
    $columns = array(
      'cb'        => '<input type="checkbox" />',
      'title' => __('Title'),
      'categories' => __('Categories'),
      'shared_article_original_author'    => __('Author'),
      'shared_article_original_type'      => __('Post Type'),
      'shared_article_library_name'      => __('Library'),
      'shared_article_tags'             => __('Tags'),
      'date'      => __('Date')
    );
    return $columns;
  }

  function get_sortable_columns() {
    $sortable_columns = array(
      'title'  => array('title',false),
      'date'  => array('date',false),
      'shared_article_library_name' => array('display_name',false)
    );
    return $sortable_columns;
  }
  
  function prepare_items() {

    $order = @$_REQUEST['order'];
    $orderby = @$_REQUEST['orderby'];
    $search = @$_REQUEST['s'];
    $cat = @$_REQUEST['cat'];
    $tag= @$_REQUEST['tagname'];

    $per_page = $this->get_items_per_page('articles_per_page',10);
    $current_page = $this->get_pagenum();
 
    $args = array('per_page'=>$per_page,'page'=>$current_page);
    if (!empty($search)) {
     $args['search'] = $search;
    }
    if ($orderby) {
     $args['orderby'] = $orderby; 
    }
    if ($order) {
     $args['order'] = $order; 
    }
    if ($cat) {
     $args['cat']=$cat;
    }
    if ($tag) {
     $args['tagname']=$tag;
    }
  
    $admin_notices = '';
    $data = $this->remote_get_items($args);
    if (is_wp_error($data)) {
     $admin_notices .= "<div class='notice error-notice dismissable'><p>".esc_html($data->get_error_message())."</p></div>";
    } else  {

     $total_items = $data['meta']['X-WP-Total'];
     $total_pages = $data['meta']['X-WP-Total-Pages'];

 
     $this->set_pagination_args(array('total_items'=>$total_items,'per_page'=> $per_page,'total_pages'=>$total_pages));
     $this->_column_headers = $this->get_column_info();

     $this->items = $data['content'];
    }
    if (!empty($admin_notices)) {
     add_action('admin_notices',function () use ($admin_notices) { echo $admin_notices; });
    }
  }
  
  function display($args=array()) {
   parent::display($args);
  }

  function get_bulk_actions() {
    $actions = array(
      'unsubscribe'    => __('Unsubscribe','wlaa-plugin'),
      'subscribe'    => __('Subscribe','wlaa-plugin')
    );
    return $actions;
  }


  function column_title($item) {

    $titledata = $item['title'];
    $title = '';
    if (is_array($titledata)) {
     $title = $titledata['rendered'];
    } else {
     $title = $titledata;
    }

    $options = $this->articleadopter->options();
    $lib = $options['username'];
    $actions = array();
    $issub = $this->articleadopter->is_subscribed($item['id']);
    if ($lib != $item['shared_article_library']) {
      if ($issub) {
       $actions['unsubscribe']= sprintf('<a href="?page=%s&action=%s&article[]=%s">Unsubscribe</a>',$_REQUEST['page'],'unsubscribe',$item['id']);
      } else {
       $actions['subscribe']  = sprintf('<a href="?page=%s&action=%s&article[]=%s">Subscribe</a>',$_REQUEST['page'],'subscribe',$item['id']);
      }
    } else {
      $actions['edit'] = sprintf("<a href='%s'>%s</a>", admin_url(sprintf("post.php?post=%d&action=edit",$item['shared_article_original_id'])), __('Edit'));
    }

    $origurl = trim(esc_url($item['shared_article_original_url']));
    if (!empty($origurl)) {
     $actions['show'] = sprintf("<a target='_blank' href='%s'>%s</a>", $origurl, __('Show'));
    }

    if ($issub) {
       return sprintf('<strong>%1$s</strong> %2$s', $title, $this->row_actions($actions) );
    } else {
       return sprintf('%1$s %2$s', $title, $this->row_actions($actions) );
    }
 }

 function column_cb($item) {
  $options = $this->articleadopter->options();
  $lib = $options['username'];
  if ($lib != $item['shared_article_library']) {
   return sprintf(
             '<input type="checkbox" name="article[]" value="%s" />', $item['id']
         );    
  } else {
    return sprintf(
             '<input type="checkbox" disabled name="article[]" value="%s" />', $item['id']
    );    

  }
 }
 
  function column_default( $item, $column_name ) {
    switch( $column_name ) { 
      case 'categories':
       if (is_array($item['categories']) && !empty($item['categories'])) {
        $categories = array();
        foreach($item['categories'] as $c) {
         $l = "<a href='" . $_SERVER['PHP_SELF'] . "?page=article-database&cat=$c'>";
         $l .= $this->articleadopter->categories[$c]['name'];
         $l .= "</a>";
         $categories[] = $l;
        }
        return join(', ',$categories);
       } else {
        return "-";
       }
       break;
       
      case 'shared_article_tags':
       $tags = array_map(function ($t) {return "<a href='".$_SERVER['PHP_SELF'] . "?page=article-database&tagname=$t'>$t</a>"; },$item['shared_article_tags']);
       return join(",",$tags);
      case 'shared_article_original_type':
        return __($item['shared_article_original_type']);
      case 'date':
        return date('j M Y H:i:s',strtotime($item['date'])); 
      default:
        $d = $item[$column_name];
        if (is_array($d) && isset($d['rendered'])) return $d['rendered'];
        if (is_array($d)) return $d[0];
        return $d;
    }
  }
  
}


?>
