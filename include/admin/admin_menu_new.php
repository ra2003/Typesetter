<?php
defined('is_running') or die('Not an entry point...');


defined('gp_max_menu_level') OR define('gp_max_menu_level',6);

includeFile('admin/admin_menu_tools.php');
includeFile('tool/SectionContent.php');
common::LoadComponents('sortable');


class admin_menu_new extends admin_menu_tools{

	var $cookie_settings		= array();
	var $hidden_levels			= array();
	var $search_page			= 0;
	var $search_max_per_page	= 20;
	var $query_string;

	var $avail_menus			= array();
	var $curr_menu_id;
	var $curr_menu_array		= false;
	var $is_alt_menu			= false;
	var $max_level_index		= 3;

	var $main_menu_count;
	var $list_displays			= array('search'=>true, 'all'=>true, 'hidden'=>true, 'nomenus'=>true );

	var $section_types;


	function __construct(){
		global $langmessage,$page,$config;


		$this->section_types			= section_content::GetTypes();

		$page->ajaxReplace				= array();

		$page->css_admin[]				= '/include/css/admin_menu_new.css';

		$page->head_js[]				= '/include/thirdparty/js/nestedSortable.js';
		$page->head_js[]				= '/include/thirdparty/js/jquery_cookie.js';
		$page->head_js[]				= '/include/js/admin_menu_new.js';

		$this->max_level_index			= max(3,gp_max_menu_level-1);
		$page->head_script				.= 'var max_level_index = '.$this->max_level_index.';';


		$this->avail_menus['gpmenu']	= $langmessage['Main Menu'].' / '.$langmessage['site_map'];
		$this->avail_menus['all']		= $langmessage['All Pages'];
		$this->avail_menus['hidden']	= $langmessage['Not In Main Menu'];
		$this->avail_menus['nomenus']	= $langmessage['Not In Any Menus'];
		$this->avail_menus['search']	= $langmessage['search pages'];

		if( isset($config['menus']) ){
			foreach($config['menus'] as $id => $menu_label){
				$this->avail_menus[$id] = $menu_label;
			}
		}

		//early commands
		$cmd = common::GetCommand();
		switch($cmd){
			case 'altmenu_create':
				$this->AltMenu_Create();
			break;

			case 'rm_menu':
				$this->AltMenu_Remove();
			break;
			case 'alt_menu_rename':
				$this->AltMenu_Rename();
			break;

		}


		//read cookie settings
		if( isset($_COOKIE['gp_menu_prefs']) ){
			parse_str( $_COOKIE['gp_menu_prefs'] , $this->cookie_settings );
		}

		$this->SetMenuID();
		$this->SetMenuArray();
		$this->SetCollapseSettings();
		$this->SetQueryInfo();

		$cmd_after = gpPlugin::Filter('MenuCommand',array($cmd));
		if( $cmd !== $cmd_after ){
			$cmd = $cmd_after;
			if( $cmd === 'return' ){
				return;
			}
		}

		switch($cmd){

			case 'rename_menu_prompt':
				$this->RenameMenuPrompt();
			return;

			//menu creation
			case 'newmenu':
				$this->NewMenu();
			return;

			case 'homepage_select':
				$this->HomepageSelect();
			return;
			case 'homepage_save':
				$this->HomepageSave();
			return;

			case 'ToggleVisibility':
				$this->ToggleVisibility();
			break;

			//rename
			case 'renameform':
				$this->RenameForm(); //will die()
			return;

			case 'renameit':
				$this->RenameFile();
			break;

			case 'hide':
				$this->Hide();
			break;

			case 'drag':
				$this->SaveDrag();
			break;

			case 'trash_page';
			case 'trash':
				$this->MoveToTrash($cmd);
			break;

			case 'add_hidden':
				$this->AddHidden();
			return;
			case 'new_hidden':
				$this->NewHiddenFile();
			break;
			case 'new_redir':
				$this->NewHiddenFile_Redir();
			return;

			case 'CopyPage':
				$this->CopyPage();
			break;
			case 'copypage':
				$this->CopyForm();
			return;

			// Page Insertion
			case 'insert_before':
			case 'insert_after':
			case 'insert_child':
				$this->InsertDialog($cmd);
			return;

			case 'restore':
				$this->RestoreFromTrash();
			break;

			case 'insert_from_hidden';
				$this->InsertFromHidden();
			break;

			case 'new_file':
				$this->NewFile();
			break;

			//layout
			case 'layout':
			case 'uselayout':
			case 'restorelayout':
				includeFile('tool/Page_Layout.php');
				$page_layout = new page_layout($cmd,'Admin_Menu',$this->query_string);
				if( $page_layout->result() ){
					return;
				}
			break;


			//external links
			case 'new_external':
				$this->NewExternal();
			break;
			case 'edit_external':
				$this->EditExternal();
			return;
			case 'save_external':
				$this->SaveExternal();
			break;


		}

		$this->ShowForm($cmd);

	}

	function Link($href,$label,$query='',$attr='',$nonce_action=false){
		$query = $this->MenuQuery($query);
		return common::Link($href,$label,$query,$attr,$nonce_action);
	}

	function GetUrl($href,$query='',$ampersands=true){
		$query = $this->MenuQuery($query);
		return common::GetUrl($href,$query,$ampersands);
	}

	function MenuQuery($query=''){
		if( !empty($query) ){
			$query .= '&';
		}
		$query .= 'menu='.$this->curr_menu_id;
		if( strpos($query,'page=') !== false ){
			//do nothing
		}elseif( $this->search_page > 0 ){
			$query .= '&page='.$this->search_page;
		}

		//for searches
		if( !empty($_REQUEST['q']) ){
			$query .= '&q='.urlencode($_REQUEST['q']);
		}

		return $query;
	}

	function SetQueryInfo(){

		//search page
		if( isset($_REQUEST['page']) && is_numeric($_REQUEST['page']) ){
			$this->search_page = (int)$_REQUEST['page'];
		}

		//browse query string
		$this->query_string = $this->MenuQuery();
	}

	function SetCollapseSettings(){
		$gp_menu_collapse =& $_COOKIE['gp_menu_hide'];

		$search = '#'.$this->curr_menu_id.'=[';
		$pos = strpos($gp_menu_collapse,$search);
		if( $pos === false ){
			return;
		}

		$gp_menu_collapse = substr($gp_menu_collapse,$pos+strlen($search));
		$pos = strpos($gp_menu_collapse,']');
		if( $pos === false ){
			return;
		}
		$gp_menu_collapse = substr($gp_menu_collapse,0,$pos);
		$gp_menu_collapse = trim($gp_menu_collapse,',');
		$this->hidden_levels = explode(',',$gp_menu_collapse);
		$this->hidden_levels = array_flip($this->hidden_levels);
	}



	//which menu, not the same order as used for $_REQUEST
	function SetMenuID(){

		if( isset($this->curr_menu_id) ){
			return;
		}

		if( isset($_POST['menu']) ){
			$this->curr_menu_id = $_POST['menu'];
		}elseif( isset($_GET['menu']) ){
			$this->curr_menu_id = $_GET['menu'];
		}elseif( isset($this->cookie_settings['gp_menu_select']) ){
			$this->curr_menu_id = $this->cookie_settings['gp_menu_select'];
		}

		if( !isset($this->curr_menu_id) || !isset($this->avail_menus[$this->curr_menu_id]) ){
			$this->curr_menu_id = 'gpmenu';
		}

	}

	function SetMenuArray(){
		global $gp_menu;

		if( isset($this->list_displays[$this->curr_menu_id]) ){
			return;
		}

		//set curr_menu_array
		if( $this->curr_menu_id == 'gpmenu' ){
			$this->curr_menu_array =& $gp_menu;
			$this->is_main_menu = true;
			return;
		}

		$this->curr_menu_array = gpOutput::GetMenuArray($this->curr_menu_id);
		$this->is_alt_menu = true;
	}


	function SaveMenu($menu_and_pages=false){
		global $dataDir;

		if( $this->is_main_menu ){
			return admin_tools::SavePagesPHP();
		}

		if( $this->curr_menu_array === false ){
			return false;
		}

		if( $menu_and_pages && !admin_tools::SavePagesPHP() ){
			return false;
		}

		$menu_file = $dataDir.'/data/_menus/'.$this->curr_menu_id.'.php';
		return gpFiles::SaveData($menu_file,'menu',$this->curr_menu_array);
	}




	/**
	 * Primary Display
	 *
	 *
	 */
	function ShowForm(){
		global $langmessage, $page, $config;


		$replace_id = '';
		$menu_output = false;
		ob_start();

		if( isset($this->list_displays[$this->curr_menu_id]) ){
			$this->SearchDisplay();
			$replace_id = '#gp_menu_available';
		}else{
			$menu_output = true;
			$this->OutputMenu();
			$replace_id = '#admin_menu';
		}

		$content = ob_get_clean();


		// json response
		if( isset($_REQUEST['gpreq']) && ($_REQUEST['gpreq'] == 'json') ){
			$this->GetMenus();
			$page->ajaxReplace[] = array('gp_menu_prep','','');
			$page->ajaxReplace[] = array('inner',$replace_id,$content);
			$page->ajaxReplace[] = array('gp_menu_refresh','','');
			return;
		}


		// search form
		echo '<form action="'.common::GetUrl('Admin_Menu').'" method="post" id="page_search">';
		$_REQUEST += array('q'=>'');
		echo '<input type="text" name="q" size="15" value="'.htmlspecialchars($_REQUEST['q']).'" class="gptext gpinput title-autocomplete" /> ';
		echo '<input type="submit" name="cmd" value="'.$langmessage['search pages'].'" class="gpbutton" />';
		echo '<input type="hidden" name="menu" value="search" />';
		echo '</form>';


		$menus = $this->GetAvailMenus('menu');
		$lists = $this->GetAvailMenus('display');


		//heading
		echo '<form action="'.common::GetUrl('Admin_Menu').'" method="post" id="gp_menu_select_form">';
		echo '<input type="hidden" name="curr_menu" id="gp_curr_menu" value="'.$this->curr_menu_id.'" />';

		echo '<h2 class="first-child">';
		echo $langmessage['file_manager'].' &#187;  ';
		echo '<select id="gp_menu_select" name="gp_menu_select" class="gpselect">';

		echo '<optgroup label="'.$langmessage['Menus'].'">';
			foreach($menus as $menu_id => $menu_label){
				if( $menu_id == $this->curr_menu_id ){
					echo '<option value="'.$menu_id.'" selected="selected">';
				}else{
					echo '<option value="'.$menu_id.'">';
				}
				echo $menu_label.'</option>';
			}
		echo '</optgroup>';
		echo '<optgroup label="'.$langmessage['Lists'].'">';
			foreach($lists as $menu_id => $menu_label){

				if( $menu_id == $this->curr_menu_id ){
					echo '<option value="'.$menu_id.'" selected="selected">';
				}elseif( $menu_id == 'search' ){
					continue;
				}else{
					echo '<option value="'.$menu_id.'">';
				}
				echo $menu_label.'</option>';
			}
		echo '</optgroup>';
		echo '</select>';
		echo '</h2>';

		echo '</form>';


		//homepage
		echo '<div class="homepage_setting">';
		$this->HomepageDisplay();
		echo '</div>';
		gp_edit::PrepAutoComplete();





		echo '<div id="admin_menu_div">';

		if( $menu_output ){
			echo '<ul id="admin_menu" class="sortable_menu">';
			echo $content;
			echo '</ul><div id="admin_menu_tools" ></div>';

			echo '<div id="menu_info" style="display:none">';
			$this->MenuSkeleton();
			echo '</div>';

			echo '<div id="menu_info_extern" style="display:none">';
			$this->MenuSkeletonExtern();
			echo '</div>';

		}else{
			echo '<div id="gp_menu_available">';
			echo $content;
			echo '</div>';
		}

		echo '</div>';


		echo '<div class="admin_footnote">';

		echo '<div>';
		echo '<b>'.$langmessage['Menus'].'</b>';
		foreach($menus as $menu_id => $menu_label){
			if( $menu_id == $this->curr_menu_id ){
				echo '<span>'.$menu_label.'</span>';
			}else{
				echo '<span>'.common::Link('Admin_Menu',$menu_label,'menu='.$menu_id, array('data-cmd'=>'cnreq')).'</span>';
			}

		}
		echo '<span>'.common::Link('Admin_Menu','+ '.$langmessage['Add New Menu'],'cmd=newmenu','data-cmd="gpabox"').'</span>';
		echo '</div>';

		echo '<div>';
		echo '<b>'.$langmessage['Lists'].'</b>';
		foreach($lists as $menu_id => $menu_label){
			if( $menu_id == $this->curr_menu_id ){
			}else{
			}
			echo '<span>'.common::Link('Admin_Menu',$menu_label,'menu='.$menu_id,array('data-cmd'=>'creq')).'</span>';
		}
		echo '</div>';


		//options for alternate menu
		if( $this->is_alt_menu ){
			echo '<div>';
			$label = $menus[$this->curr_menu_id];
			echo '<b>'.$label.'</b>';
			echo '<span>'.common::Link('Admin_Menu',$langmessage['rename'],'cmd=rename_menu_prompt&id='.$this->curr_menu_id,'data-cmd="gpabox"').'</span>';
			$title_attr = sprintf($langmessage['generic_delete_confirm'],'&quot;'.$label.'&quot;');
			echo '<span>'.common::Link('Admin_Menu',$langmessage['delete'],'cmd=rm_menu&id='.$this->curr_menu_id,array('data-cmd'=>'creq','class'=>'gpconfirm','title'=>$title_attr)).'</span>';

			echo '</div>';
		}


		echo '</div>';

		echo '<div class="gpclear"></div>';


	}

	function GetAvailMenus($get_type='menu'){

		$result = array();
		foreach($this->avail_menus as $menu_id => $menu_label){

			$menu_type = 'menu';
			if( isset($this->list_displays[$menu_id]) ){
				$menu_type = 'display';
			}

			if( $menu_type == $get_type ){
				$result[$menu_id] = $menu_label;
			}
		}
		return $result;
	}


	//we do the json here because we're replacing more than just the content
	function GetMenus(){
		global $page;
		ob_start();
		gpOutput::GetMenu();
		$content = ob_get_clean();
		$page->ajaxReplace[] = array('inner','#admin_menu_wrap',$content);
	}



	function OutputMenu(){
		global $langmessage, $gp_titles, $gpLayouts, $config;
		$menu_adjustments_made = false;

		if( $this->curr_menu_array === false ){
			msg($langmessage['OOPS'].' (Current menu not set)');
			return;
		}

		//get array of titles and levels
		$menu_keys = array();
		$menu_values = array();
		foreach($this->curr_menu_array as $key => $info){
			if( !isset($info['level']) ){
				break;
			}

			//remove deleted titles
			if( !isset($gp_titles[$key]) && !isset($info['url']) ){
				unset($this->curr_menu_array[$key]);
				$menu_adjustments_made = true;
				continue;
			}


			$menu_keys[] = $key;
			$menu_values[] = $info;
		}

		//if the menu is empty (because all the files in it were deleted elsewhere), recreate it with the home page
		if( count($menu_values) == 0 ){
			$this->curr_menu_array = $this->AltMenu_New();
			$menu_keys[] = key($this->curr_menu_array);
			$menu_values[] = current($this->curr_menu_array);
			$menu_adjustments_made = true;
		}


		$prev_layout = false;
		$curr_key = 0;

		$curr_level = $menu_values[$curr_key]['level'];
		$prev_level = 0;


		//for sites that don't start with level 0
		if( $curr_level > $prev_level ){
			$piece = '<li><div>&nbsp;</div><ul>';
			while( $curr_level > $prev_level ){
				echo $piece;
				$prev_level++;
			}
		}



		do{

			echo "\n";

			$class = '';
			$menu_value = $menu_values[$curr_key];
			$menu_key = $menu_keys[$curr_key];
			$curr_level = $menu_value['level'];


			$next_level = 0;
			if( isset($menu_values[$curr_key+1]) ){
				$next_level = $menu_values[$curr_key+1]['level'];
			}

			if( $next_level > $curr_level ){
				$class = 'haschildren';
			}
			if( isset($this->hidden_levels[$menu_key]) ){
				$class .= ' hidechildren';
			}
			if( $curr_level >= $this->max_level_index){
				$class .= ' no-nest';
			}

			$class = $this->VisibilityClass($class, $menu_key);


			//layout
			$style = '';
			if( $this->is_main_menu ){
				if( isset($gp_titles[$menu_key]['gpLayout'])
					&& isset($gpLayouts[$gp_titles[$menu_key]['gpLayout']]) ){
						$color = $gpLayouts[$gp_titles[$menu_key]['gpLayout']]['color'];
						$style = 'background-color:'.$color.';';
				}elseif( $curr_level == 0 ){
					//$color = $gpLayouts[$config['gpLayout']]['color'];
					//$style = 'border-color:'.$color;
				}
			}


			echo '<li class="'.$class.'" style="'.$style.'">';

			if( $curr_level == 0 ){
				$prev_layout = false;
			}

			$this->ShowLevel($menu_key,$menu_value,$prev_layout);

			if( !empty($gp_titles[$menu_key]['gpLayout']) ){
				$prev_layout = $gp_titles[$menu_key]['gpLayout'];
			}

			if( $next_level > $curr_level ){

				$piece = '<ul>';
				while( $next_level > $curr_level ){
					echo $piece;
					$curr_level++;
					$piece = '<li class="missing_title"><div>'
							.'<a href="#" class="gp_label" data-cmd="menu_info">'
							.$langmessage['page_deleted']
							.'</a>'
							.'<p><b>'.$langmessage['page_deleted'].'</b></p>'
							.'</div><ul>';
				}

			}elseif( $next_level < $curr_level ){

				while( $next_level < $curr_level ){
					echo '</li></ul>';
					$curr_level--;
				}
				echo '</li>';
			}elseif( $next_level == $curr_level ){
				echo '</li>';
			}

			$prev_level = $curr_level;

		}while( ++$curr_key && ($curr_key < count($menu_keys) ) );

		if( $menu_adjustments_made ){
			$this->SaveMenu(false);
		}
	}


	/**
	 * Get the css class representing the current page's visibility
	 *
	 */
	function VisibilityClass($class, $index){
		global $gp_menu, $gp_titles;

		if( $this->is_main_menu && isset($gp_titles[$index]['vis']) ){
			$class .= ' private-list';
			return $class;
		}

		$parents = common::Parents($index,$gp_menu);
		foreach($parents as $parent_index){
			if( isset($gp_titles[$parent_index]['vis']) ){
				$class .= ' private-inherited';
				break;
			}
		}

		return $class;
	}



	function ShowLevel($menu_key,$menu_value,$prev_layout){
		global $gp_titles, $gpLayouts;

		$layout			= admin_menu_tools::CurrentLayout($menu_key);
		$layout_info	= $gpLayouts[$layout];

		echo '<div id="gp_menu_key_'.$menu_key.'">';

		$style = '';
		$class = 'expand_img';
		if( !empty($gp_titles[$menu_key]['gpLayout']) ){
			$style = 'style="background-color:'.$layout_info['color'].';"';
			$class .= ' haslayout';
		}

		echo '<a href="#" class="'.$class.'" data-cmd="expand_img" '.$style.'></a>';

		if( isset($gp_titles[$menu_key]) ){
			$this->ShowLevel_Title($menu_key,$menu_value,$layout_info);
		}elseif( isset($menu_value['url']) ){
			$this->ShowLevel_External($menu_key,$menu_value);
		}
		echo '</div>';
	}


	/**
	 * Show a menu entry if it's an external link
	 *
	 */
	function ShowLevel_External($menu_key,$menu_value){

		$data = array(
				'key'		=>	$menu_key
				,'url'		=>	$menu_value['url']
				,'title'	=>	$menu_value['url']
				,'level'	=>	$menu_value['level']
				);

		if( strlen($data['title']) > 30 ){
			$data['title'] = substr($data['title'],0,30).'...';
		}

		$this->MenuLink($data,'external');
		echo common::LabelSpecialChars($menu_value['label']);
		echo '</a>';
	}

	function MenuSkeletonExtern(){
		global $langmessage;

		echo '<b>'.$langmessage['Target URL'].'</b>';
		echo '<span>';
		$img = '<img alt="" />';
		echo '<a href="[url]" target="_blank">[title]</a>';
		echo '</span>';

		echo '<b>'.$langmessage['options'].'</b>';
		echo '<span>';

		$img = '<span class="menu_icon page_edit_icon"></span>';
		echo $this->Link('Admin_Menu',$img.$langmessage['edit'],'cmd=edit_external&key=[key]',array('title'=>$langmessage['edit'],'data-cmd'=>'gpabox'));

		$img = '<span class="menu_icon cut_list_icon"></span>';
		echo $this->Link('Admin_Menu',$img.$langmessage['rm_from_menu'],'cmd=hide&index=[key]',array('title'=>$langmessage['rm_from_menu'],'data-cmd'=>'postlink','class'=>'gpconfirm'));

		echo '</span>';

		$this->InsertLinks();
	}


	/**
	 * Show a menu entry if it's an internal page
	 *
	 */
	function ShowLevel_Title($menu_key,$menu_value,$layout_info){
		global $langmessage, $gp_titles;


		$title						= common::IndexToTitle($menu_key);
		$label						= common::GetLabel($title);
		$isSpecialLink				= common::SpecialOrAdmin($title);



		//get the data for this title
		$data = array(
					'key'			=>	$menu_key
					,'url'			=>	common::GetUrl($title)
					,'level'		=>	$menu_value['level']
					,'title'		=>	$title
					,'special'		=>	$isSpecialLink
					,'has_layout'	=>	!empty($gp_titles[$menu_key]['gpLayout'])
					,'layout_color'	=>	$layout_info['color']
					,'layout_label'	=>	$layout_info['label']
					,'types'		=>	$gp_titles[$menu_key]['type']
					,'opts'			=> ''
					);


		if( !$isSpecialLink ){
			$file = gpFiles::PageFile($title);
			$stats = @stat($file);
			if( $stats ){
				$data += array(
						'size'		=>	admin_tools::FormatBytes($stats['size'])
						,'mtime'	=>	common::date($langmessage['strftime_datetime'],$stats['mtime'])
						);
			}
		}

		ob_start();
		gpPlugin::Action('MenuPageOptions',array($title,$menu_key,$menu_value,$layout_info));
		$menu_options = ob_get_clean();
		if( $menu_options ){
			$data['opts'] = $menu_options;
		}

		$this->MenuLink($data);
		echo common::LabelSpecialChars($label);
		echo '</a>';
	}


	/**
	 * Output Sortable Menu Link and data about the title or external link
	 *
	 */
	function MenuLink($data, $class = ''){

		$class	= 'gp_label sort '.$class;
		$json	= common::JsonEncode($data);

		echo '<a class="'.$class.'" data-cmd="menu_info" data-arg="'.str_replace('&','&amp;',$data['key']).'" data-json=\''.htmlspecialchars($json,ENT_QUOTES & ~ENT_COMPAT).'\'>';
	}


	/**
	 * Output html for the menu editing options displayed for selected titles
	 *
	 */
	function MenuSkeleton(){
		global $langmessage;

		//page options
		echo '<b>'.$langmessage['page_options'].'</b>';

		echo '<span>';

		$img	= '<span class="menu_icon icon_page"></span>';
		echo '<a href="[url]" class="view_edit_link not_multiple">'.$img.htmlspecialchars($langmessage['view/edit_page']).'</a>';

		$img	= '<span class="menu_icon page_edit_icon"></span>';
		$attrs	= array('title'=>$langmessage['rename/details'],'data-cmd'=>'gpajax','class'=>'not_multiple');
		echo $this->Link('Admin_Menu',$img.$langmessage['rename/details'],'cmd=renameform&index=[key]',$attrs);


		$img	= '<span class="menu_icon icon_vis"></span>';
		$q		= 'cmd=ToggleVisibility&index=[key]';
		$label	= $langmessage['Visibility'].': '.$langmessage['Private'];
		$attrs	= array('title'=>$label,'data-cmd'=>'gpajax','class'=>'vis_private');
		echo $this->Link('Admin_Menu',$img.$label,$q,$attrs);

		$label	= $langmessage['Visibility'].': '.$langmessage['Public'];
		$attrs	= array('title'=>$label,'data-cmd'=>'gpajax','class'=>'vis_public not_multiple');
		$q		.= '&visibility=private';
		echo $this->Link('Admin_Menu',$img.$label,$q,$attrs);


		$img	= '<span class="menu_icon copy_icon"></span>';
		$attrs	= array('title'=>$langmessage['Copy'],'data-cmd'=>'gpabox','class'=>'not_multiple');
		echo $this->Link('Admin_Menu',$img.$langmessage['Copy'],'cmd=copypage&index=[key]',$attrs);

		if( admin_tools::HasPermission('Admin_User') ){
			$img	= '<span class="menu_icon icon_user"></span>';
			$attrs	= array('title'=>$langmessage['permissions'],'data-cmd'=>'gpabox');
			echo $this->Link('Admin_Users',$img.$langmessage['permissions'],'cmd=file_permissions&index=[key]',$attrs);
		}

		$img	= '<span class="menu_icon cut_list_icon"></span>';
		$attrs	= array('title'=>$langmessage['rm_from_menu'],'data-cmd'=>'postlink','class'=>'gpconfirm');
		echo $this->Link('Admin_Menu',$img.$langmessage['rm_from_menu'],'cmd=hide&index=[key]',$attrs);

		$img	= '<span class="menu_icon bin_icon"></span>';
		$attrs	= array('title'=>$langmessage['delete_page'],'data-cmd'=>'postlink','class'=>'gpconfirm not_special');
		echo $this->Link('Admin_Menu',$img.$langmessage['delete'],'cmd=trash&index=[key]',$attrs);

		echo '[opts]'; //replaced with the contents of gpPlugin::Action('MenuPageOptions',array($title,$menu_key,$menu_value,$layout_info));

		echo '</span>';


		//layout
		if( $this->is_main_menu ){
			echo '<div class="not_multiple">';
			echo '<b>'.$langmessage['layout'].'</b>';
			echo '<span>';

			//has_layout
			$img = '<span class="layout_icon"></span>';
			echo $this->Link('Admin_Menu',$img.'[layout_label]','cmd=layout&index=[key]',' title="'.$langmessage['layout'].'" data-cmd="gpabox" class="has_layout"');

			$img = '<span class="menu_icon undo_icon"></span>';
			echo $this->Link('Admin_Menu',$img.$langmessage['restore'],'cmd=restorelayout&index=[key]',array('data-cmd'=>'postlink','title'=>$langmessage['restore'],'class'=>'has_layout'),'restore');

			//no_layout
			$img = '<span class="layout_icon"></span>';
			echo $this->Link('Admin_Menu',$img.'[layout_label]','cmd=layout&index=[key]',' title="'.$langmessage['layout'].'" data-cmd="gpabox" class="no_layout"');
			echo '</span>';
			echo '</div>';
		}

		$this->InsertLinks();


		//file stats
		echo '<div>';
		echo '<b>'.$langmessage['Page Info'].'</b>';
		echo '<span>';
		echo '<a class="not_multiple">'.$langmessage['Slug/URL'].': [title]</a>';
		echo '<a class="not_multiple">'.$langmessage['Content Type'].': [types]</a>';
		echo '<a class="not_special only_multiple">'.sprintf($langmessage['%s Pages'],'[files]').'</a>';
		echo '<a class="not_special">'.$langmessage['File Size'].': [size]</a>';
		echo '<a class="not_special not_multiple">'.$langmessage['Modified'].': [mtime]</a>';
		echo '<a class="not_multiple">Data Index: [key]</a>';
		echo '</span>';
		echo '</div>';

	}


	/**
	 * Output Insert links displayed with page options
	 *
	 */
	function InsertLinks(){
		global $langmessage;

		echo '<div class="not_multiple">';
		echo '<b>'.$langmessage['insert_into_menu'].'</b>';
		echo '<span>';

		$img = '<span class="menu_icon insert_before_icon"></span>';
		$query = 'cmd=insert_before&insert_where=[key]';
		echo $this->Link('Admin_Menu',$img.$langmessage['insert_before'],$query,array('title'=>$langmessage['insert_before'],'data-cmd'=>'gpabox'));


		$img = '<span class="menu_icon insert_after_icon"></span>';
		$query = 'cmd=insert_after&insert_where=[key]';
		echo $this->Link('Admin_Menu',$img.$langmessage['insert_after'],$query,array('title'=>$langmessage['insert_after'],'data-cmd'=>'gpabox'));


		$img = '<span class="menu_icon insert_after_icon"></span>';
		$query = 'cmd=insert_child&insert_where=[key]';
		echo $this->Link('Admin_Menu',$img.$langmessage['insert_child'],$query,array('title'=>$langmessage['insert_child'],'data-cmd'=>'gpabox','class'=>'insert_child'));
		echo '</span>';
		echo '</div>';
	}

	/**
	 * Get a list of titles matching the search criteria
	 *
	 */
	function GetSearchList(){
		global $gp_index;


		$key =& $_REQUEST['q'];

		if( empty($key) ){
			return array();
		}

		$key = strtolower($key);
		$show_list = array();
		foreach($gp_index as $title => $index ){

			if( strpos(strtolower($title),$key) !== false ){
				$show_list[$index] = $title;
				continue;
			}

			$label = common::GetLabelIndex($index);
			if( strpos(strtolower($label),$key) !== false ){
				$show_list[$index] = $title;
				continue;
			}
		}
		return $show_list;
	}

	function SearchDisplay(){
		global $langmessage, $gpLayouts, $gp_index, $gp_menu;

		$Inherit_Info = admin_menu_tools::Inheritance_Info();

		switch($this->curr_menu_id){
			case 'search':
				$show_list = $this->GetSearchList();
			break;
			case 'all':
				$show_list = array_keys($gp_index);
			break;
			case 'hidden':
				$show_list = $this->GetAvailable();
			break;
			case 'nomenus':
				$show_list = $this->GetNoMenus();
			break;

		}

		$show_list = array_values($show_list); //to reset the keys
		$show_list = array_reverse($show_list); //show newest first
		$max = count($show_list);
		while( ($this->search_page * $this->search_max_per_page) > $max ){
			$this->search_page--;
		}
		$start = $this->search_page*$this->search_max_per_page;
		$stop = min( ($this->search_page+1)*$this->search_max_per_page, $max);


		ob_start();
		echo '<div class="gp_search_links">';
		echo '<span class="showing">';
		echo sprintf($langmessage['SHOWING'],($start+1),$stop,$max);
		echo '</span>';

		echo '<span>';

		if( ($start !== 0) || ($stop < $max) ){
			for( $i = 0; ($i*$this->search_max_per_page) < $max; $i++ ){
				$class = '';
				if( $i == $this->search_page ){
					$class = ' class="current"';
				}
				echo $this->Link('Admin_Menu',($i+1),'page='.$i,'data-cmd="gpajax"'.$class);
			}
		}

		echo $this->Link('Admin_Menu',$langmessage['create_new_file'],'cmd=add_hidden',array('title'=>$langmessage['create_new_file'],'data-cmd'=>'gpabox'));
		echo '</span>';
		echo '</div>';
		$links = ob_get_clean();

		echo $links;

		echo '<table class="bordered striped">';
		echo '<thead>';
		echo '<tr><th>';
		echo $langmessage['file_name'];
		echo '</th><th>';
		echo $langmessage['Content Type'];
		echo '</th><th>';
		echo $langmessage['Child Pages'];
		echo '</th><th>';
		echo $langmessage['File Size'];
		echo '</th><th>';
		echo $langmessage['Modified'];
		echo '</th></tr>';
		echo '</thead>';


		echo '<tbody>';

		if( count($show_list) > 0 ){
			for( $i = $start; $i < $stop; $i++ ){
				$title = $show_list[$i];
				$this->SearchDisplayRow($title);
			}
		}

		echo '</tbody>';
		echo '</table>';

		if( count($show_list) == 0 ){
			echo '<p>';
			echo $langmessage['Empty'];
			echo '</p>';
		}

		echo '<br/>';
		echo $links;
	}


	/**
	 * Display row
	 *
	 */
	function SearchDisplayRow($title){
		global $langmessage, $gpLayouts, $gp_index, $gp_menu, $gp_titles;

		$title_index		= $gp_index[$title];
		$is_special			= common::SpecialOrAdmin($title);
		$file				= gpFiles::PageFile($title);
		$stats				= @stat($file);
		$mtime				= false;
		$size				= false;
		$layout				= admin_menu_tools::CurrentLayout($title_index);
		$layout_info		= $gpLayouts[$layout];


		if( $stats ){
			$mtime = $stats['mtime'];
			$size = $stats['size'];
		}


		echo '<tr><td>';

		$label = common::GetLabel($title);
		echo common::Link($title,common::LabelSpecialChars($label));


		//area only display on mouseover
		echo '<div><div>';//style="position:absolute;bottom:0;left:10px;right:10px;"

		echo $this->Link('Admin_Menu',$langmessage['rename/details'],'cmd=renameform&index='.urlencode($title_index),array('title'=>$langmessage['rename/details'],'data-cmd'=>'gpajax'));


		$q		= 'cmd=ToggleVisibility&index='.urlencode($title_index);
		if( isset($gp_titles[$title_index]['vis']) ){
			$label	= $langmessage['Visibility'].': '.$langmessage['Private'];
		}else{
			$label	= $langmessage['Visibility'].': '.$langmessage['Public'];
			$q		.= '&visibility=private';
		}

		$attrs	= array('title'=>$label,'data-cmd'=>'gpajax');
		echo $this->Link('Admin_Menu',$label,$q,$attrs);


		echo $this->Link('Admin_Menu',$langmessage['Copy'],'cmd=copypage&index='.urlencode($title_index),array('title'=>$langmessage['Copy'],'data-cmd'=>'gpabox'));

		echo '<span>';
		echo $langmessage['layout'].': ';
		echo $this->Link('Admin_Menu',$layout_info['label'],'cmd=layout&index='.urlencode($title_index),array('title'=>$langmessage['layout'],'data-cmd'=>'gpabox'));
		echo '</span>';

		if( !$is_special ){
			echo $this->Link('Admin_Menu',$langmessage['delete'],'cmd=trash&index='.urlencode($title_index),array('title'=>$langmessage['delete_page'],'data-cmd'=>'postlink','class'=>'gpconfirm'));
		}

		gpPlugin::Action('MenuPageOptions',array($title,$title_index,false,$layout_info));

		//stats
		if( gpdebug ){
			echo '<span>Data Index: '.$title_index.'</span>';
		}
		echo '</div>&nbsp;</div>';

		//types
		echo '</td><td>';
		$this->TitleTypes($title_index);

		//children
		echo '</td><td>';
		if( isset($Inherit_Info[$title_index]) && isset($Inherit_Info[$title_index]['children']) ){
			echo $Inherit_Info[$title_index]['children'];
		}elseif( isset($gp_menu[$title_index]) ){
			echo '0';
		}else{
			echo $langmessage['Not In Main Menu'];
		}

		//size
		echo '</td><td>';
		if( $size ){
			echo admin_tools::FormatBytes($size);
		}

		//modified
		echo '</td><td>';
		if( $mtime ){
			echo common::date($langmessage['strftime_datetime'],$mtime);
		}

		echo '</td></tr>';
	}


	/**
	 * List section types
	 *
	 */
	function TitleTypes($title_index){
		global $gp_titles;

		$types		= explode(',',$gp_titles[$title_index]['type']);
		$types		= array_filter($types);
		$types		= array_unique($types);

		foreach($types as $i => $type){
			if( isset($this->section_types[$type]) && isset($this->section_types[$type]['label']) ){
				$types[$i] = $this->section_types[$type]['label'];
			}
		}

		echo implode(', ',$types);
	}


	/**
	 * Get an array of titles that is not represented in any of the menus
	 *
	 */
	function GetNoMenus(){
		global $gp_index;


		//first get all titles in a menu
		$menus = $this->GetAvailMenus('menu');
		$all_keys = array();
		foreach($menus as $menu_id => $label){
			$menu_array = gpOutput::GetMenuArray($menu_id);
			$keys = array_keys($menu_array);
			$all_keys = array_merge($all_keys,$keys);
		}
		$all_keys = array_unique($all_keys);

		//then check $gp_index agains $all_keys
		foreach( $gp_index as $title => $index ){
			if( in_array($index, $all_keys) ){
				continue;
			}
			$avail[] = $title;
		}
		return $avail;
	}

	/**
	 * Get a list of pages that are not in the main menu
	 * @return array
	 */
	public function GetAvailable(){
		global $gp_index, $gp_menu;

		$avail = array();
		foreach( $gp_index as $title => $index ){
			if( !isset($gp_menu[$index]) ){
				$avail[$index] = $title;
			}
		}
		return $avail;
	}

	/**
	 * Get a list of pages that are not in the current menu array
	 * @return array
	 */
	protected function GetAvail_Current(){
		global $gp_index;

		if( $this->is_main_menu ){
			return $this->GetAvailable();
		}

		foreach( $gp_index as $title => $index ){
			if( !isset($this->curr_menu_array[$index]) ){
				$avail[$index] = $title;
			}
		}
		return $avail;
	}


	/**
	 * Save changes to the current menu array after a drag event occurs
	 * @return bool
	 */
	function SaveDrag(){
		global $langmessage;

		$this->CacheSettings();
		if( $this->curr_menu_array === false ){
			msg($langmessage['OOPS'].'(1)');
			return false;
		}

		$key = $_POST['drag_key'];
		if( !isset($this->curr_menu_array[$key]) ){
			msg($langmessage['OOPS'].' (Unknown menu key)');
			return false;
		}


		$moved = $this->RmMoved($key);
		if( !$moved ){
			msg($langmessage['OOPS'].'(3)');
			return false;
		}


		// if prev (sibling) set
		$inserted = true;
		if( !empty($_POST['prev']) ){

			$inserted = $this->MenuInsert_After( $moved, $_POST['prev']);

		// if parent is set
		}elseif( !empty($_POST['parent']) ){

			$inserted = $this->MenuInsert_Child( $moved, $_POST['parent']);

		// if no siblings, no parent then it's the root
		}else{
			$inserted = $this->MenuInsert_Before( $moved, false);

		}

		if( !$inserted ){
			$this->RestoreSettings();
			msg($langmessage['OOPS'].'(4)');
			return;
		}

		if( !$this->SaveMenu(false) ){
			$this->RestoreSettings();
			common::AjaxWarning();
			return false;
		}

	}


	/*
	 * Get portion of menu that was moved
	 */
	function RmMoved($key){
		if( !isset($this->curr_menu_array[$key]) ){
			return false;
		}

		$old_level = false;
		$moved = array();

		foreach($this->curr_menu_array as $menu_key => $info){

			if( !isset($info['level']) ){
				break;
			}
			$level = $info['level'];

			if( $old_level === false ){

				if( $menu_key != $key ){
					continue;
				}

				$old_level = $level;
				$moved[$menu_key] = $info;
				unset($this->curr_menu_array[$menu_key]);
				continue;
			}

			if( $level <= $old_level ){
				break;
			}

			$moved[$menu_key] = $info;
			unset($this->curr_menu_array[$menu_key]);
		}
		return $moved;
	}



	/**
	 * Move To Trash
	 * Hide special pages
	 *
	 */
	function MoveToTrash($cmd){
		global $gp_titles, $gp_index, $langmessage, $gp_menu, $config, $dataDir;

		includeFile('admin/admin_trash.php');
		$this->CacheSettings();

		$_POST			+= array('index'=>'');
		$indexes		= explode(',',$_POST['index']);
		$trash_data		= array();
		$delete_files	= array();


		foreach($indexes as $index){

			$title	= common::IndexToTitle($index);

			// Create file in trash
			if( $title ){
				if( !admin_trash::MoveToTrash_File($title,$index,$trash_data) ){
					msg($langmessage['OOPS'].' (Not Moved)');
					$this->RestoreSettings();
					return false;
				}
			}


			// Remove from menu
			if( isset($gp_menu[$index]) ){

				if( count($gp_menu) == 1 ){
					continue;
				}

				if( !$this->RmFromMenu($index,false) ){
					msg($langmessage['OOPS']);
					$this->RestoreSettings();
					return false;
				}
			}

			unset($gp_titles[$index]);
			unset($gp_index[$title]);
		}


		$this->ResetHomepage();


		if( !admin_tools::SaveAllConfig() ){
			$this->RestoreSettings();
			return false;
		}

		$link = common::GetUrl('Admin_Trash');
		msg(sprintf($langmessage['MOVED_TO_TRASH'],$link));


		gpPlugin::Action('MenuPageTrashed',array($indexes));

		return true;
	}


	/**
	 * Make sure the homepage has a value
	 *
	 */
	public function ResetHomepage(){
		global $config, $gp_menu, $gp_titles;

		if( !isset($gp_titles[$config['homepath_key']]) ){
			$config['homepath_key'] = key($gp_menu);
			$config['homepath']		= common::IndexToTitle($config['homepath_key']);
		}
	}


	/**
	 * Remove key from curr_menu_array
	 * Adjust children levels if necessary
	 *
	 */
	protected function RmFromMenu($search_key,$curr_menu=true){
		global $gp_menu;

		if( $curr_menu ){
			$keys = array_keys($this->curr_menu_array);
			$values = array_values($this->curr_menu_array);
		}else{
			$keys = array_keys($gp_menu);
			$values = array_values($gp_menu);
		}

		$insert_key = array_search($search_key,$keys);
		if( ($insert_key === null) || ($insert_key === false) ){
			return false;
		}

		$curr_info = $values[$insert_key];
		$curr_level = $curr_info['level'];

		unset($keys[$insert_key]);
		$keys = array_values($keys);

		unset($values[$insert_key]);
		$values = array_values($values);


		//adjust levels of children
		$prev_level = -1;
		if( isset($values[$insert_key-1]) ){
			$prev_level = $values[$insert_key-1]['level'];
		}
		$moved_one = true;
		do{
			$moved_one = false;
			if( isset($values[$insert_key]) ){
				$curr_level = $values[$insert_key]['level'];
				if( ($prev_level+1) < $curr_level ){
					$values[$insert_key]['level']--;
					$prev_level = $values[$insert_key]['level'];
					$moved_one = true;
					$insert_key++;
				}
			}
		}while($moved_one);

		//shouldn't happen
		if( count($keys) == 0 ){
			return false;
		}

		//rebuild
		if( $curr_menu ){
			$this->curr_menu_array = array_combine($keys, $values);
		}else{
			$gp_menu = array_combine($keys, $values);
		}

		return true;
	}



	/**
	 * Rename
	 *
	 */
	public function RenameForm(){
		global $langmessage, $gp_index;

		includeFile('tool/Page_Rename.php');

		//prepare variables
		$title =& $_REQUEST['index'];
		$action = $this->GetUrl('Admin_Menu');
		gp_rename::RenameForm( $_REQUEST['index'], $action );
	}

	public function RenameFile(){
		global $langmessage, $gp_index;

		includeFile('tool/Page_Rename.php');


		//prepare variables
		$title =& $_REQUEST['title'];
		if( !isset($gp_index[$title]) ){
			msg($langmessage['OOPS'].' (R0)');
			return false;
		}

		gp_rename::RenameFile($title);
	}


	/**
	 * Toggle Page Visibility
	 *
	 */
	public function ToggleVisibility(){
		$_REQUEST += array('index'=>'','visibility'=>'');
		\gp\tool\Visibility::Toggle($_REQUEST['index'], $_REQUEST['visibility']);
	}


	/**
	 * Remove from the menu
	 *
	 */
	public function Hide(){
		global $langmessage;

		if( $this->curr_menu_array === false ){
			msg($langmessage['OOPS'].'(1)');
			return false;
		}

		$this->CacheSettings();

		$_POST		+= array('index'=>'');
		$indexes 	= explode(',',$_POST['index']);

		foreach($indexes as $index ){

			if( count($this->curr_menu_array) == 1 ){
				break;
			}

			if( !isset($this->curr_menu_array[$index]) ){
				msg($langmessage['OOPS'].'(3)');
				return false;
			}

			if( !$this->RmFromMenu($index) ){
				msg($langmessage['OOPS'].'(4)');
				$this->RestoreSettings();
				return false;
			}
		}

		if( $this->SaveMenu(false) ){
			return true;
		}

		msg($langmessage['OOPS'].'(5)');
		$this->RestoreSettings();
		return false;
	}

	/**
	 * Display a user form for adding a new page that won't be immediately added to a menu
	 *
	 */
	public function AddHidden(){
		global $langmessage, $page, $gp_index;

		includeFile('tool/editing_page.php');

		$title = '';
		if( isset($_REQUEST['title']) ){
			$title = $_REQUEST['title'];
		}
		echo '<div class="inline_box">';

		echo '<div class="layout_links" style="float:right">';
		echo '<a href="#gp_new_copy" data-cmd="tabs" class="selected">'. $langmessage['Copy'] .'</a>';
		echo '<a href="#gp_new_type" data-cmd="tabs">'. $langmessage['Content Type'] .'</a>';
		echo '</div>';


		echo '<h3>'.$langmessage['new_file'].'</h3>';


		echo '<form action="'.$this->GetUrl('Admin_Menu').'" method="post">';
		echo '<table class="bordered full_width">';

		echo '<tr><th colspan="2">'.$langmessage['options'].'</th></tr>';

		//title
		echo '<tr><td>';
		echo $langmessage['label'];
		echo '</td><td>';
		echo '<input type="text" name="title" maxlength="100" size="50" value="'.htmlspecialchars($title).'" class="gpinput full_width" required/>';
		echo '</td></tr>';

		//copy
		echo '<tbody id="gp_new_copy">';
		echo '<tr><td>';
		echo $langmessage['Copy'];
		echo '</td><td>';

		$this->ScrollList($gp_index);

		//copy buttons
		echo '<p>';
		echo '<button type="submit" name="cmd" value="CopyPage" class="gpsubmit gpvalidate" data-cmd="gppost">'.$langmessage['create_new_file'].'</button>';
		echo '<button class="admin_box_close gpcancel">'.$langmessage['cancel'].'</button>';
		echo '<input type="hidden" name="redir" value="redir"/> ';
		echo '</p>';


		echo '</td></tr>';
		echo '</tbody>';


		//content type
		echo '<tr id="gp_new_type" style="display:none"><td>';
		echo str_replace(' ','&nbsp;',$langmessage['Content Type']);
		echo '</td><td>';
		echo '<div id="new_section_links">';
		editing_page::NewSections(true);
		echo '</div>';


		//create buttons
		echo '<p>';
		if( isset($_GET['redir']) ){
			echo '<input type="hidden" name="cmd" value="new_redir" />';
		}else{
			echo '<input type="hidden" name="cmd" value="new_hidden" />';
		}
		echo '<input type="submit" name="aaa" value="'.$langmessage['create_new_file'].'" class="gpsubmit gpvalidate" data-cmd="gppost"/> ';
		echo '<input type="submit" value="'.$langmessage['cancel'].'" class="admin_box_close gpcancel" /> ';
		echo '</p>';


		echo '</td></tr>';
		echo '</table>';


		echo '</form>';
		echo '</div>';
	}


	/**
	 * Create a scrollable title list
	 *
	 * @param array $list
	 * @param string $name
	 * @pearm string $type
	 * @param bool $index_as_value
	 */
	public function ScrollList($list, $name = 'from_title', $type = 'radio', $index_as_value = false ){
		global $langmessage;

		$list_out = array();
		foreach($list as $title => $index){
			ob_start();
			echo '<label>';
			if( $index_as_value ){
				echo '<input type="'.$type.'" name="'.$name.'" value="'.htmlspecialchars($index).'" />';
			}else{
				echo '<input type="'.$type.'" name="'.$name.'" value="'.htmlspecialchars($title).'" />';
			}
			echo '<span>';
			$label = common::GetLabel($title);
			echo common::LabelSpecialChars($label);
			echo '<span class="slug">';
			echo '/'.$title;
			echo '</span>';
			echo '</span>';
			echo '</label>';

			$list_out[$title] = ob_get_clean();
		}

		uksort($list_out,'strnatcasecmp');
		echo '<div class="gpui-scrolllist">';
		echo '<input type="text" name="search" value="" class="gpsearch" placeholder="'.$langmessage['Search'].'" autocomplete="off" />';
		echo implode('',$list_out);
		echo '</div>';
	}


	/**
	 * Display the dialog for inserting pages into a menu
	 *
	 */
	public function InsertDialog($cmd){
		global $langmessage, $page, $gp_index;

		includeFile('admin/admin_trash.php');

		//create format of each tab
		ob_start();
		echo '<div id="%s" class="%s">';
		echo '<form action="'.common::GetUrl('Admin_Menu').'" method="post">';
		echo '<input type="hidden" name="insert_where" value="'.htmlspecialchars($_GET['insert_where']).'" />';
		echo '<input type="hidden" name="insert_how" value="'.htmlspecialchars($cmd).'" />';
		echo '<table class="bordered full_width">';
		echo '<thead><tr><th>&nbsp;</th></tr></thead>';
		echo '</table>';
		$format_top = ob_get_clean();

		ob_start();
		echo '<p>';
		echo '<button type="submit" name="cmd" value="%s" class="gpsubmit" data-cmd="gppost">%s</button>';
		echo '<button class="admin_box_close gpcancel">'.$langmessage['cancel'].'</button>';
		echo '</p>';
		echo '</form>';
		echo '</div>';
		$format_bottom = ob_get_clean();



		echo '<div class="inline_box">';

			//tabs
			echo '<div class="layout_links">';
			echo ' <a href="#gp_Insert_Copy" data-cmd="tabs" class="selected">'. $langmessage['Copy'] .'</a>';
			echo ' <a href="#gp_Insert_New" data-cmd="tabs">'. $langmessage['new_file'] .'</a>';
			echo ' <a href="#gp_Insert_Hidden" data-cmd="tabs">'. $langmessage['Available'] .'</a>';
			echo ' <a href="#gp_Insert_External" data-cmd="tabs">'. $langmessage['External Link'] .'</a>';
			echo ' <a href="#gp_Insert_Deleted" data-cmd="tabs">'. $langmessage['trash'] .'</a>';
			echo '</div>';


			// Copy
			echo sprintf($format_top,'gp_Insert_Copy','');
			echo '<table class="bordered full_width">';
			echo '<tr><td>';
			echo $langmessage['label'];
			echo '</td><td>';
			echo '<input type="text" name="title" maxlength="100" size="50" value="" class="gpinput full_width" required/>';
			echo '</td></tr>';
			echo '<tr><td>';
			echo $langmessage['Copy'];
			echo '</td><td>';
			$this->ScrollList($gp_index);
			echo '</td></tr>';
			echo '</table>';
			echo sprintf($format_bottom,'CopyPage',$langmessage['Copy']);


			// Insert New
			echo sprintf($format_top,'gp_Insert_New','nodisplay');
			echo '<table class="bordered full_width">';
			echo '<tr><td>';
			echo $langmessage['label'];
			echo '</td><td>';
			echo '<input type="text" name="title" maxlength="100" value="" size="50" class="gpinput full_width" required />';
			echo '</td></tr>';

			echo '<tr><td>';
			echo $langmessage['Content Type'];
			echo '</td><td>';
			includeFile('tool/editing_page.php');
			echo '<div id="new_section_links">';
			editing_page::NewSections(true);
			echo '</div>';
			echo '</td></tr>';
			echo '</table>';
			echo sprintf($format_bottom,'new_file',$langmessage['create_new_file']);


			// Insert Hidden
			$avail = $this->GetAvail_Current();

			if( $avail ){
				echo sprintf($format_top,'gp_Insert_Hidden','nodisplay');
				$avail = array_flip($avail);
				$this->ScrollList($avail,'keys[]','checkbox',true);
				echo sprintf($format_bottom,'insert_from_hidden',$langmessage['insert_into_menu']);
			}



			// Insert Deleted / Restore from trash
			$trashtitles = admin_trash::TrashFiles();
			if( $trashtitles ){
				echo sprintf($format_top,'gp_Insert_Deleted','nodisplay');

				echo '<div class="gpui-scrolllist">';
				echo '<input type="text" name="search" value="" class="gpsearch" placeholder="'.$langmessage['Search'].'" autocomplete="off" />';
				foreach($trashtitles as $title => $info){
					echo '<label>';
					echo '<input type="checkbox" name="titles[]" value="'.htmlspecialchars($title).'" />';
					echo '<span>';
					echo $info['label'];
					echo '<span class="slug">';
					if( isset($info['title']) ){
						echo '/'.$info['title'];
					}else{
						echo '/'.$title;
					}
					echo '</span>';
					echo '</span>';
					echo '</label>';
				}
				echo '</div>';
				echo sprintf($format_bottom,'restore',$langmessage['restore_from_trash']);
			}


			//Insert External
			echo '<div id="gp_Insert_External" class="nodisplay">';
			$args['insert_how']		= $cmd;
			$args['insert_where']	= $_GET['insert_where'];
			$this->ExternalForm('new_external',$langmessage['insert_into_menu'],$args);
			echo '</div>';


		echo '</div>';

	}

	/**
	 * Insert pages into the current menu from existing pages that aren't in the menu
	 *
	 */
	public function InsertFromHidden(){
		global $langmessage, $gp_index;

		if( $this->curr_menu_array === false ){
			msg($langmessage['OOPS'].' (Menu not set)');
			return false;
		}

		$this->CacheSettings();

		//get list of titles from submitted indexes
		$titles = array();
		if( isset($_POST['keys']) ){
			foreach($_POST['keys'] as $index){
				if( $title = common::IndexToTitle($index) ){
					$titles[$index]['level'] = 0;
				}
			}
		}

		if( count($titles) == 0 ){
			msg($langmessage['OOPS'].' (Nothing selected)');
			$this->RestoreSettings();
			return false;
		}

		if( !$this->SavePages($titles) ){
			$this->RestoreSettings();
			return false;
		}

	}


	/**
	 * Add titles to the current menu from the trash
	 *
	 */
	public function RestoreFromTrash(){
		global $langmessage, $gp_index;


		if( $this->curr_menu_array === false ){
			msg($langmessage['OOPS']);
			return false;
		}

		if( !isset($_POST['titles']) ){
			msg($langmessage['OOPS'].' (Nothing Selected)');
			return false;
		}

		$this->CacheSettings();
		includeFile('admin/admin_trash.php');

		$titles_lower	= array_change_key_case($gp_index,CASE_LOWER);
		$titles			= array();
		$menu			= admin_trash::RestoreTitles($_POST['titles']);


		if( !$menu ){
			msg($langmessage['OOPS']);
			$this->RestoreSettings();
			return false;
		}


		if( !$this->SavePages($menu) ){
			$this->RestoreSettings();
			return false;
		}

		admin_trash::ModTrashData(null,$titles);
	}


	public function NewHiddenFile_Redir(){
		global $page;

		$new_index = $this->NewHiddenFile();
		if( $new_index === false ){
			return;
		}

		$title = common::IndexToTitle($new_index);

		//redirect to title
		$url = common::AbsoluteUrl($title,'',true,false);
		$page->ajaxReplace[] = array('location',$url,0);
	}


	public function NewHiddenFile(){
		global $langmessage;

		$this->CacheSettings();

		$new_index = $this->CreateNew();
		if( $new_index === false ){
			return false;
		}


		if( !admin_tools::SavePagesPHP() ){
			msg($langmessage['OOPS']);
			$this->RestoreSettings();
			return false;
		}
		msg($langmessage['SAVED']);
		$this->search_page = 0; //take user back to first page where the new page will be displayed
		return $new_index;
	}

	public function NewFile(){
		global $langmessage;
		$this->CacheSettings();


		if( $this->curr_menu_array === false ){
			msg($langmessage['OOPS'].'(0)');
			return false;
		}

		if( !isset($this->curr_menu_array[$_POST['insert_where']]) ){
			msg($langmessage['OOPS'].'(1)');
			return false;
		}


		$new_index = $this->CreateNew();
		if( $new_index === false ){
			return false;
		}

		$insert = array();
		$insert[$new_index] = array();

		if( !$this->SavePages($insert) ){
			$this->RestoreSettings();
			return false;
		}
	}


	/**
	 * Create a new page from a user post
	 *
	 */
	public function CreateNew(){
		global $gp_index, $gp_titles, $langmessage, $gpAdmin;
		includeFile('tool/editing_page.php');
		includeFile('tool/editing.php');


		//check title
		$title		= $_POST['title'];
		$title		= admin_tools::CheckPostedNewPage($title,$message);
		if( $title === false ){
			msg($message);
			return false;
		}


		//multiple section types
		$type		= $_POST['content_type'];
		if( strpos($type,'{') === 0 ){
			$types = json_decode($type,true);
			if( $types ){

				$types								+= array('wrapper_class'=>'gpRow');
				$content							= array();

				//wrapper section
				$section							= gp_edit::DefaultContent('wrapper_section');
				$section['contains_sections']		= count($types['types']);
				$section['attributes']['class']		= $types['wrapper_class'];
				$content[]							= $section;


				//nested sections
				foreach($types['types'] as $type){

					if( strpos($type,'.') ){
						list($type,$class)			= explode('.',$type,2);
					}else{
						$class						= '';
					}

					$section						= gp_edit::DefaultContent($type);
					$section['attributes']['class']	.= ' '.$class;
					$content[]						= $section;
				}
			}

		//single section type
		}else{
			$content	= gp_edit::DefaultContent($type, $_POST['title']);
			if( $content['content'] === false ){
				return false;
			}
		}


		//add to $gp_index first!
		$index							= common::NewFileIndex();
		$gp_index[$title]				= $index;

		if( !gpFiles::NewTitle($title,$content,$type) ){
			msg($langmessage['OOPS'].' (cn1)');
			unset($gp_index[$title]);
			return false;
		}

		//add to gp_titles
		$new_titles						= array();
		$new_titles[$index]['label']	= admin_tools::PostedLabel($_POST['title']);
		$new_titles[$index]['type']		= $type;
		$gp_titles						+= $new_titles;


		//add to users editing
		if( $gpAdmin['editing'] != 'all' ){
			$gpAdmin['editing'] = rtrim($gpAdmin['editing'],',').','.$index.',';


			$users		= gpFiles::Get('_site/users');
			$users[$gpAdmin['username']]['editing'] = $gpAdmin['editing'];
			gpFiles::SaveData('_site/users','users',$users);

		}


		return $index;
	}


	/**
	 * Save pages
	 * Insert titles into the current menu if needed
	 *
	 * @param array $titles
	 * @return bool
	 */
	protected function SavePages($titles){
		global $langmessage;

		//menu modification
		if( isset($_POST['insert_where']) && isset($_POST['insert_how']) ){
			$success = false;
			switch($_POST['insert_how']){
				case 'insert_before':
				$success = $this->MenuInsert_Before($titles,$_POST['insert_where']);
				break;

				case 'insert_after':
				$success = $this->MenuInsert_After($titles,$_POST['insert_where']);
				break;

				case 'insert_child':
				$success = $this->MenuInsert_After($titles,$_POST['insert_where'],1);
				break;
			}

			if( !$success ){
				msg($langmessage['OOPS'].' (Insert Failed)');
				return false;
			}

			if( !$this->SaveMenu(true) ){
				msg($langmessage['OOPS'].' (Menu Not Saved)');
				return false;
			}

			return true;
		}


		if( !admin_tools::SavePagesPHP() ){
			msg($langmessage['OOPS'].' (Page index not saved)');
			return false;
		}

		return true;
	}



	/**
	 * Insert titles into menu
	 *
	 */
	protected function MenuInsert_Before($titles,$sibling){

		$old_level = $this->GetRootLevel($titles);

		//root install
		if( $sibling === false ){
			$level_adjustment = 0 - $old_level;
			$titles = $this->AdjustMovedLevel($titles,$level_adjustment);
			$this->curr_menu_array = $titles + $this->curr_menu_array;
			return true;
		}


		//before sibling
		if( !isset($this->curr_menu_array[$sibling]) || !isset($this->curr_menu_array[$sibling]['level']) ){
			return false;
		}

		$sibling_level = $this->curr_menu_array[$sibling]['level'];
		$level_adjustment = $sibling_level - $old_level;
		$titles = $this->AdjustMovedLevel($titles,$level_adjustment);

		$new_menu = array();
		foreach($this->curr_menu_array as $menu_key => $menu_info ){

			if( $menu_key == $sibling ){
				foreach($titles as $titles_key => $titles_info){
					$new_menu[$titles_key] = $titles_info;
				}
			}
			$new_menu[$menu_key] = $menu_info;
		}
		$this->curr_menu_array = $new_menu;
		return true;
	}

	/*
	 * Insert $titles into $menu as siblings of $sibling
	 * Place
	 *
	 */
	protected function MenuInsert_After($titles,$sibling,$level_adjustment=0){

		if( !isset($this->curr_menu_array[$sibling]) || !isset($this->curr_menu_array[$sibling]['level']) ){
			return false;
		}

		$sibling_level = $this->curr_menu_array[$sibling]['level'];

		//level adjustment
		$old_level = $this->GetRootLevel($titles);
		$level_adjustment += $sibling_level - $old_level;
		$titles = $this->AdjustMovedLevel($titles,$level_adjustment);


		// rebuild menu
		//	insert $titles after sibling and it's children
		$new_menu = array();
		$found_sibling = false;
		foreach($this->curr_menu_array as $menu_key => $menu_info){

			$menu_level = 0;
			if( isset($menu_info['level']) ){
				$menu_level = $menu_info['level'];
			}

			if( $found_sibling && ($menu_level <= $sibling_level) ){
				foreach($titles as $titles_key => $titles_info){
					$new_menu[$titles_key] = $titles_info;
				}
				$found_sibling = false; //prevent multiple insertions
			}

			$new_menu[$menu_key] = $menu_info;

			if( $menu_key == $sibling ){
				$found_sibling = true;
			}
		}

		//if it's added to the end
		if( $found_sibling ){
			foreach($titles as $titles_key => $titles_info){
				$new_menu[$titles_key] = $titles_info;
			}
		}
		$this->curr_menu_array = $new_menu;

		return true;
	}

	/*
	 * Insert $titles into $menu as children of $parent
	 *
	 */
	protected function MenuInsert_Child($titles,$parent){

		if( !isset($this->curr_menu_array[$parent]) || !isset($this->curr_menu_array[$parent]['level']) ){
			return false;
		}

		$parent_level = $this->curr_menu_array[$parent]['level'];


		//level adjustment
		$old_level = $this->GetRootLevel($titles);
		$level_adjustment = $parent_level - $old_level + 1;
		$titles = $this->AdjustMovedLevel($titles,$level_adjustment);

		//rebuild menu
		//	insert $titles after parent
		$new_menu = array();
		foreach($this->curr_menu_array as $menu_title => $menu_info){
			$new_menu[$menu_title] = $menu_info;

			if( $menu_title == $parent ){
				foreach($titles as $titles_title => $titles_info){
					$new_menu[$titles_title] = $titles_info;
				}
			}
		}

		$this->curr_menu_array = $new_menu;
		return true;
	}

	protected function AdjustMovedLevel($titles,$level_adjustment){

		foreach($titles as $title => $info){
			$level = 0;
			if( isset($info['level']) ){
				$level = $info['level'];
			}
			$titles[$title]['level'] = min($this->max_level_index,$level + $level_adjustment);
		}
		return $titles;
	}

	protected function GetRootLevel($menu){
		reset($menu);
		$info = current($menu);
		if( isset($info['level']) ){
			return $info['level'];
		}
		return 0;
	}


	/**
	 * Is the menu an alternate menu
	 *
	 */
	protected function IsAltMenu($id){
		global $config;
		return isset($config['menus'][$id]);
	}


	/**
	 * Rename a menu
	 *
	 */
	protected function AltMenu_Rename(){
		global $langmessage,$config;

		$menu_id =& $_POST['id'];

		if( !$this->IsAltMenu($menu_id) ){
			msg($langmessage['OOPS']);
			return;
		}

		$menu_name = $this->AltMenu_NewName();
		if( !$menu_name ){
			return;
		}

		$config['menus'][$menu_id] = $menu_name;
		if( !admin_tools::SaveConfig() ){
			msg($langmessage['OOPS']);
		}else{
			$this->avail_menus[$menu_id] = $menu_name;
		}
	}


	/**
	 * Display a form for editing the name of an alternate menu
	 *
	 */
	public function RenameMenuPrompt(){
		global $langmessage;

		$menu_id =& $_GET['id'];

		if( !$this->IsAltMenu($menu_id) ){
			echo '<div class="inline_box">';
			echo $langmessage['OOPS'];
			echo '</div>';
			return;
		}

		$menu_name = $this->avail_menus[$menu_id];

		echo '<div class="inline_box">';
		echo '<form action="'.common::GetUrl('Admin_Menu').'" method="post">';
		echo '<input type="hidden" name="cmd" value="alt_menu_rename" />';
		echo '<input type="hidden" name="id" value="'.htmlspecialchars($menu_id).'" />';

		echo '<h3>';
		echo $langmessage['rename'];
		echo '</h3>';

		echo '<p>';
		echo $langmessage['label'];
		echo ' &nbsp; ';
		echo '<input type="text" name="menu_name" value="'.htmlspecialchars($menu_name).'" class="gpinput" />';
		echo '</p>';


		echo '<p>';
		echo '<input type="submit" name="aa" value="'.htmlspecialchars($langmessage['continue']).'" class="gpsubmit" />';
		echo ' <input type="submit" value="'.htmlspecialchars($langmessage['cancel']).'" class="admin_box_close gpcancel"/> ';
		echo '</p>';

		echo '</form>';
		echo '</div>';

	}

	/**
	 * Display a form for creating a new menu
	 *
	 */
	public function NewMenu(){
		global $langmessage;

		echo '<div class="inline_box">';
		echo '<form action="'.common::GetUrl('Admin_Menu').'" method="post">';
		echo '<input type="hidden" name="cmd" value="altmenu_create" />';

		echo '<h3>';
		echo $langmessage['Add New Menu'];
		echo '</h3>';

		echo '<p>';
		echo $langmessage['label'];
		echo ' &nbsp; ';
		echo '<input type="text" name="menu_name" class="gpinput" />';
		echo '</p>';

		echo '<p>';

		echo '<input type="submit" name="aa" value="'.htmlspecialchars($langmessage['continue']).'" class="gpsubmit" />';
		echo ' <input type="submit" value="'.htmlspecialchars($langmessage['cancel']).'" class="admin_box_close gpcancel" /> ';
		echo '</p>';

		echo '</form>';
		echo '</div>';

	}


	/**
	 * Create an alternate menu
	 *
	 */
	public function AltMenu_Create(){
		global $config, $langmessage, $dataDir;

		$menu_name = $this->AltMenu_NewName();
		if( !$menu_name ){
			return;
		}

		$new_menu = $this->AltMenu_New();

		//get next index
		$index = 0;
		if( isset($config['menus']) && is_array($config['menus']) ){
			foreach($config['menus'] as $id => $label){
				$id = substr($id,1);
				$index = max($index,$id);
			}
		}
		$index++;
		$id = 'm'.$index;

		$menu_file = $dataDir.'/data/_menus/'.$id.'.php';
		if( !gpFiles::SaveData($menu_file,'menu',$new_menu) ){
			msg($langmessage['OOPS'].' (Menu Not Saved)');
			return false;
		}

		$config['menus'][$id] = $menu_name;
		if( !admin_tools::SaveConfig() ){
			msg($langmessage['OOPS'].' (Config Not Saved)');
		}else{
			$this->avail_menus[$id] = $menu_name;
			$this->curr_menu_id = $id;
		}
	}


	/**
	 * Generate menu data with a single file
	 *
	 */
	public function AltMenu_New(){
		global $gp_menu, $gp_titles;

		if( count($gp_menu) ){
			reset($gp_menu);
			$first_index = key($gp_menu);
		}elseif( count($gp_titles ) ){
			reset($gp_titles);
			$first_index = key($gp_titles);
		}

		$new_menu[$first_index] = array('level'=>0);
		return $new_menu;
	}

	/**
	 * Check the posted name of a menu
	 *
	 */
	public function AltMenu_NewName(){
		global $langmessage;

		$menu_name = gp_edit::CleanTitle($_POST['menu_name'],' ');
		if( empty($menu_name) ){
			msg($langmessage['OOPS'].' (Empty Name)');
			return false;
		}

		if( array_search($menu_name,$this->avail_menus) !== false ){
			msg($langmessage['OOPS'].' (Name Exists)');
			return false;
		}

		return $menu_name;
	}


	/**
	 * Remove an alternate menu from the configuration and delete the data file
	 *
	 */
	public function AltMenu_Remove(){
		global $langmessage,$config,$dataDir;

		$menu_id =& $_POST['id'];
		if( !$this->IsAltMenu($menu_id) ){
			msg($langmessage['OOPS']);
			return;
		}

		$menu_file = $dataDir.'/data/_menus/'.$menu_id.'.php';

		unset($config['menus'][$menu_id]);
		unset($this->avail_menus[$menu_id]);
		if( !admin_tools::SaveConfig() ){
			msg($langmessage['OOPS']);
		}

		msg($langmessage['SAVED']);

		//delete menu file
		$menu_file = $dataDir.'/data/_menus/'.$menu_id.'.php';
		if( gpFiles::Exists($menu_file) ){
			unlink($menu_file);
		}
	}



	/*
	 * External Links
	 *
	 *
	 */
	function ExternalForm($cmd,$submit,$args){
		global $langmessage;

		//these aren't all required for each usage of ExternalForm()
		$args += array(
					'url'=>'http://',
					'label'=>'',
					'title_attr'=>'',
					'insert_how'=>'',
					'insert_where'=>'',
					'key'=>''
					);


		echo '<form action="'.$this->GetUrl('Admin_Menu').'" method="post">';
		echo '<input type="hidden" name="insert_how" value="'.htmlspecialchars($args['insert_how']).'" />';
		echo '<input type="hidden" name="insert_where" value="'.htmlspecialchars($args['insert_where']).'" />';
		echo '<input type="hidden" name="key" value="'.htmlspecialchars($args['key']).'" />';

		echo '<table class="bordered full_width">';

		echo '<tr>';
			echo '<th>&nbsp;</th>';
			echo '<th>&nbsp;</th>';
			echo '</tr>';

		echo '<tr>';
			echo '<td>'.$langmessage['Target URL'].'</td>';
			echo '<td>';
			echo '<input type="text" name="url" value="'.$args['url'].'" class="gpinput"/>';
			echo '</td>';
			echo '</tr>';

		echo '<tr>';
			echo '<td>'.$langmessage['label'].'</td>';
			echo '<td>';
			echo '<input type="text" name="label" value="'.common::LabelSpecialChars($args['label']).'" class="gpinput"/>';
			echo '</td>';
			echo '</tr>';

		echo '<tr>';
			echo '<td>'.$langmessage['title attribute'].'</td>';
			echo '<td>';
			echo '<input type="text" name="title_attr" value="'.$args['title_attr'].'" class="gpinput"/>';
			echo '</td>';
			echo '</tr>';

		echo '<tr>';
			echo '<td>'.$langmessage['New_Window'].'</td>';
			echo '<td>';
			if( isset($args['new_win']) ){
				echo '<input type="checkbox" name="new_win" value="new_win" checked="checked" />';
			}else{
				echo '<input type="checkbox" name="new_win" value="new_win" />';
			}
			echo '</td>';
			echo '</tr>';


		echo '</table>';

		echo '<p>';

		echo '<input type="hidden" name="cmd" value="'.htmlspecialchars($cmd).'" />';
		echo '<input type="submit" name="" value="'.$submit.'" class="gpsubmit" data-cmd="gppost"/> ';
		echo '<input type="submit" value="'.$langmessage['cancel'].'" class="admin_box_close gpcancel" /> ';
		echo '</p>';

		echo '</form>';
	}


	/**
	 * Edit an external link entry in the current menu
	 *
	 */
	function EditExternal(){
		global $langmessage;

		$key =& $_GET['key'];
		if( !isset($this->curr_menu_array[$key]) ){
			msg($langmessage['OOPS'].' (Current menu not set)');
			return false;
		}

		$info = $this->curr_menu_array[$key];
		$info['key'] = $key;

		echo '<div class="inline_box">';

		echo '<h3>'.$langmessage['External Link'].'</h3>';

		$this->ExternalForm('save_external',$langmessage['save'],$info);

		echo '</div>';
	}


	/**
	 * Save changes to an external link entry in the current menu
	 *
	 */
	function SaveExternal(){
		global $langmessage;

		$key =& $_POST['key'];
		if( !isset($this->curr_menu_array[$key]) ){
			msg($langmessage['OOPS'].' (Current menu not set)');
			return false;
		}
		$level = $this->curr_menu_array[$key]['level'];

		$array = $this->ExternalPost();
		if( !$array ){
			msg($langmessage['OOPS'].' (1)');
			return;
		}

		$this->CacheSettings();

		$array['level'] = $level;
		$this->curr_menu_array[$key] = $array;

		if( !$this->SaveMenu(false) ){
			msg($langmessage['OOPS'].' (Menu Not Saved)');
			$this->RestoreSettings();
			return false;
		}

	}


	/**
	 * Save a new external link in the current menu
	 *
	 */
	function NewExternal(){
		global $langmessage;

		$this->CacheSettings();
		$array = $this->ExternalPost();

		if( !$array ){
			msg($langmessage['OOPS'].' (Invalid Request)');
			return;
		}

		$key			= $this->NewExternalKey();
		$insert[$key]	= $array;

		if( !$this->SavePages($insert) ){
			$this->RestoreSettings();
			return false;
		}
	}


	/**
	 * Check the values of a post with external link values
	 *
	 */
	function ExternalPost(){

		$array = array();
		if( empty($_POST['url']) || $_POST['url'] == 'http://' ){
			return false;
		}
		$array['url'] = htmlspecialchars($_POST['url']);

		if( !empty($_POST['label']) ){
			$array['label'] = admin_tools::PostedLabel($_POST['label']);
		}
		if( !empty($_POST['title_attr']) ){
			$array['title_attr'] = htmlspecialchars($_POST['title_attr']);
		}
		if( isset($_POST['new_win']) && $_POST['new_win'] == 'new_win' ){
			$array['new_win'] = true;
		}
		return $array;
	}

	function NewExternalKey(){

		$num_index = 0;
		do{
			$new_key = '_'.base_convert($num_index,10,36);
			$num_index++;
		}while( isset($this->curr_menu_array[$new_key]) );

		return $new_key;
	}

	/**
	 * Display a form for copying a page
	 *
	 */
	function CopyForm(){
		global $langmessage, $gp_index, $page;


		$index = $_REQUEST['index'];
		$from_title = common::IndexToTitle($index);

		if( !$from_title ){
			msg($langmessage['OOPS_TITLE']);
			return false;
		}

		$from_label = common::GetLabel($from_title);
		$from_label = common::LabelSpecialChars($from_label);

		echo '<div class="inline_box">';
		echo '<form method="post" action="'.common::GetUrl('Admin_Menu').'">';
		if( isset($_REQUEST['redir']) ){
			echo '<input type="hidden" name="redir" value="redir"/> ';
		}
		echo '<input type="hidden" name="from_title" value="'.htmlspecialchars($from_title).'"/> ';
		echo '<table class="bordered full_width" id="gp_rename_table">';

		echo '<thead><tr><th colspan="2">';
		echo $langmessage['Copy'];
		echo '</th></tr></thead>';

		echo '<tr class="line_row"><td>';
		echo $langmessage['from'];
		echo '</td><td>';
		echo $from_label;
		echo '</td></tr>';

		echo '<tr><td>';
		echo $langmessage['to'];
		echo '</td><td>';
		echo '<input type="text" name="title" maxlength="100" size="50" value="'.$from_label.'" class="gpinput" />';
		echo '</td></tr>';

		echo '</table>';

		echo '<p>';
		echo '<input type="hidden" name="cmd" value="CopyPage"/> ';
		echo '<input type="submit" name="" value="'.$langmessage['continue'].'" class="gpsubmit" data-cmd="gppost"/>';
		echo '<input type="button" class="admin_box_close gpcancel" name="" value="'.$langmessage['cancel'].'" />';
		echo '</p>';

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Perform a page copy
	 *
	 */
	function CopyPage(){
		global $gp_index, $gp_titles, $page, $langmessage;

		$this->CacheSettings();

		//existing page info
		$from_title = $_POST['from_title'];
		if( !isset($gp_index[$from_title]) ){
			msg($langmessage['OOPS_TITLE']);
			return false;
		}
		$from_index		= $gp_index[$from_title];
		$info			= $gp_titles[$from_index];


		//check the new title
		$title			= $_POST['title'];
		$title			= admin_tools::CheckPostedNewPage($title,$message);
		if( $title === false ){
			msg($message);
			return false;
		}

		//get the existing content
		$from_file		= gpFiles::PageFile($from_title);
		$contents		= file_get_contents($from_file);


		//add to $gp_index first!
		$index				= common::NewFileIndex();
		$gp_index[$title]	= $index;
		$file = gpFiles::PageFile($title);

		if( !gpFiles::Save($file,$contents) ){
			msg($langmessage['OOPS'].' (File not saved)');
			return false;
		}

		//add to gp_titles
		$new_titles						= array();
		$new_titles[$index]['label']	= admin_tools::PostedLabel($_POST['title']);
		$new_titles[$index]['type']		= $info['type'];
		$gp_titles						+= $new_titles;


		//add to menu
		$insert = array();
		$insert[$index] = array();

		if( !$this->SavePages($insert) ){
			$this->RestoreSettings();
			return false;
		}


		msg($langmessage['SAVED']);
		if( isset($_REQUEST['redir']) ){
			$url = common::AbsoluteUrl($title,'',true,false);
			$page->ajaxReplace[] = array('location',$url,0);
		}

		return true;
	}


	/**
	 * Display a form for selecting the homepage
	 *
	 */
	public function HomepageSelect(){
		global $langmessage;

		echo '<div class="inline_box">';
		echo '<form action="'.common::GetUrl('Admin_Menu').'" method="post">';
		echo '<input type="hidden" name="cmd" value="homepage_save" />';

		echo '<h3><i class="gpicon_home"></i>';
		echo $langmessage['Homepage'];
		echo '</h3>';

		echo '<p class="homepage_setting">';
		echo '<input type="text" class="title-autocomplete gpinput" name="homepage" />';
		echo '</p>';


		echo '<p>';
		echo '<input type="submit" name="aa" value="'.htmlspecialchars($langmessage['save']).'" class="gpsubmit" data-cmd="gppost" />';
		echo ' <input type="submit" value="'.htmlspecialchars($langmessage['cancel']).'" class="admin_box_close gpcancel" /> ';
		echo '</p>';

		echo '</form>';
		echo '</div>';

	}


	/**
	 * Display the current homepage setting
	 *
	 */
	public function HomepageDisplay(){
		global $langmessage, $config;

		$label = common::GetLabelIndex($config['homepath_key']);

		echo '<span class="gpicon_home"></span>';
		echo $langmessage['Homepage'].': ';
		echo common::Link('Admin_Menu',$label,'cmd=homepage_select','data-cmd="gpabox"');
	}


	/**
	 * Save the posted page as the homepage
	 *
	 */
	function HomepageSave(){
		global $langmessage, $config, $gp_index, $gp_titles, $page;

		$homepage = $_POST['homepage'];
		$homepage_key = false;
		if( isset($gp_index[$homepage]) ){
			$homepage_key = $gp_index[$homepage];
		}else{

			foreach($gp_titles as $index => $title){
				if( $title['label'] === $homepage ){
					$homepage_key = $index;
					break;
				}
			}

			if( !$homepage_key ){
				msg($langmessage['OOPS']);
				return;
			}
		}

		$config['homepath_key'] = $homepage_key;
		$config['homepath']		= common::IndexToTitle($config['homepath_key']);
		if( !admin_tools::SaveConfig() ){
			msg($langmessage['OOPS']);
			return;
		}

		//update the display
		ob_start();
		$this->HomepageDisplay();
		$content = ob_get_clean();

		$page->ajaxReplace[] = array('inner','.homepage_setting',$content);
	}

}
