<?php

/*
* Post by email for joomla 1.5
* @version 1.3
* @author 3D Web Design <admin@3dwebdesign.org>
* @Copyright Copyright (C) 2010 3D Web Design
* @link http://3dwebdesign.org/
* @license GNU/GPL http://www.gnu.org/copyleft/gpl.html
*/

defined('_JEXEC') or die('Restricted access');

jimport( 'joomla.application.component.controller' );

require_once( JPATH_COMPONENT.DS.'inc'.DS.'xajax.inc.php');

function component_insalled($component){
	$db =& JFactory::getDBO();
	$db->setQuery('SELECT * from #__components WHERE link=\'option='.$component.'\'');
	if (!$db->query()) {
		echo $db->stderr();
		return false;
	}
	return $db->getNumRows();
}

function component_version($component)
{
	jimport( 'joomla.filesystem.file' );
	$xmlFile = JPATH_ADMINISTRATOR .DS. 'components'.DS.$component.DS.preg_replace('/^com_(.*)/','\1.xml',$component);
	if(JFile::exists($xmlFile)){
		if ($data = JApplicationHelper::parseXMLInstallFile($xmlFile)) {
			return $data['version'];
		}
	}
	return false;
}

class PostByEmailController extends JController {

	function __construct( $config = array())
	{
		$task = JRequest::getCmd('task');
		if ($task != 'cron') {
			$xajax = new xajax();
			$xajax->registerFunction( array('import', $this, 'import') );
			$xajax->registerFunction( array('importall', $this, 'importall') );
		}
		parent::__construct( $config );
		if ($task != 'cron')
			$xajax->processRequests();
	}
	
    	function emails()
	{
		global $mainframe;
		$db			=& JFactory::getDBO();
		$context			= 'com_post_by_email.emails';
		$option				= JRequest::getCmd( 'option' );
		$limit      = $mainframe->getUserStateFromRequest( $context.'viewlistlimit', 'limit', 10 ,'int');
		$limitstart = $mainframe->getUserStateFromRequest( $context.'view'.$option.'limitstart', 'limitstart', 0 ,'int');
		$search     = $mainframe->getUserStateFromRequest( "search{$option}", 'search', '' ,'word');
		$search				= JString::strtolower($search);
		$filter_order		= $mainframe->getUserStateFromRequest( $context.'filter_order',		'filter_order',		'',	'cmd' );
		$filter_order_Dir	= $mainframe->getUserStateFromRequest( $context.'filter_order_Dir',	'filter_order_Dir',	'',	'word' );
		if (!$filter_order) {
			$filter_order = 'f.id';
		}
		$order = ' ORDER BY '. $filter_order .' '. $filter_order_Dir .', f.id';
		$where = array();
		if ($search) {
			$where[] = '(LOWER( f.title ) LIKE '.$db->Quote( '%'.$db->getEscaped( $search, true ).'%', false ) .
			' OR f.id = ' . (int) $search . ')';
		}
		$where = (count($where) ? ' WHERE '.implode(' AND ', $where) : '');
		$db->setQuery( 'SELECT count(*) FROM #__post_by_email AS f'.
		' LEFT JOIN #__categories AS cc ON cc.id = f.catid' .
		' LEFT JOIN #__sections AS s ON s.id = f.sectionid' .
		$where );
		$total = $db->loadResult();
		if (!$db->query()) {
			JError::raiseError( 500, $db->getErrorMsg() );
			return false;
		}
		// Create the pagination object
		jimport('joomla.html.pagination');
		$pagination = new JPagination($total, $limitstart, $limit);
		$db->setQuery( 'SELECT f.*,cc.title AS cat_name, s.title AS section_name, u.name AS editor FROM #__post_by_email f'.
		' LEFT JOIN #__categories AS cc ON cc.id = f.catid' .
		' LEFT JOIN #__sections AS s ON s.id = f.sectionid' .
		' LEFT JOIN #__users AS u ON u.id = f.checked_out '.
		$where.
		$order ,$pagination->limitstart,$pagination->limit);
		$rows = $db->loadObjectList();
		// If there is a database query error, throw a HTTP 500 and exit
		if ($db->getErrorNum()) {
			JError::raiseError( 500, $db->stderr() );
			return false;
		}
		// table ordering
		$lists['order_Dir']	= $filter_order_Dir;
		$lists['order']		= $filter_order;
		// search filter
		$lists['search'] = $search;
		$view	= &$this->getView('default','html');
		$view->assignRef('rows', $rows);
		$view->assignRef('page', $pagination);
		$view->assignRef('search', $search);
		$view->assignRef('lists', $lists);
		$view->display();
	}
	function editContent($edit)
	{
		global $mainframe;
		// Initialize variables
		$db				= & JFactory::getDBO();
		$user			= & JFactory::getUser();
		$cid			= JRequest::getVar( 'cid', array(0), '', 'array' );
		JArrayHelper::toInteger($cid, array(0));
		$id				= JRequest::getVar( 'id', $cid[0], '', 'int' );
		$option			= JRequest::getCmd( 'option' );
		$nullDate		= $db->getNullDate();
		$contentSection	= '';
		$sectionid		= 0;
		$model = &$this->getModel('email');
		$email = $model->getData();
		$params = new JParameter($email->params);
		if ($model->isCheckedOut( $user->get('id') )) {
			$msg = JText::sprintf( 'DESCBEINGEDITTED', JText::_( 'The email' ), $email->title );
			$mainframe->redirect( 'index.php?option=com_post_by_email', $msg );
		}
		if ($id) {
			$sectionid = $email->sectionid;
		}
		if ( $sectionid == 0 ) {
			$where = "\n WHERE section NOT LIKE '%com_%'";
		} else {
			$where = "\n WHERE section = '$sectionid'";
		}
		// get the type name - which is a special category
		if ($email->sectionid){
			$query = "SELECT name"
			. "\n FROM #__sections"
			. "\n WHERE id = $email->sectionid"
			;
			$db->setQuery( $query );
			$section = $db->loadResult();
			$emailSection = $section;
		} else {
			$query = "SELECT name"
			. "\n FROM #__sections"
			;
			$db->setQuery( $query );
			$section = $db->loadResult();
			$emailSection = $section;
		}
		if ($id) {
			$model->checkout($user->get('id'));
			jimport('joomla.utilities.date');
			$createdate = new JDate($email->created);
			$email->created 		= $createdate->toUnix();
		} else {
			if ( !$sectionid && @$_POST['filter_sectionid'] ) {
				$sectionid = $_POST['filter_sectionid'];
			}
			if ( @$_POST['catid'] ) {
				$row->catid 	= $_POST['catid'];
				$category 	 = & JTable::getInstance('category');
				$category->load($row->catid);
				$sectionid = $category->section;
			} else {
				$row->catid 	= NULL;
			}
			$row->sectionid 	= $sectionid;
		}
		$javascript = "onchange=\"changeDynaList( 'catid', sectioncategories, document.adminForm.sectionid.options[document.adminForm.sectionid.selectedIndex].value, 0, 0);\"";
		$query = "SELECT s.id, s.title"
		. "\n FROM #__sections AS s"
		. "\n ORDER BY s.ordering";
		$db->setQuery( $query );
		if ( $sectionid == 0 ) {
			$sections[] = JHTML::_('select.option', '-1', '- '.JText::_('Select Section').' -', 'id', 'title');
			$sections = array_merge( $sections, $db->loadObjectList() );
			$lists['sectionid'] = JHTML::_('select.genericlist',  $sections, 'sectionid', 'class="inputbox" size="1" '.$javascript, 'id', 'title', intval($email->sectionid));
		} else {
			$sections = $db->loadObjectList();
			$lists['sectionid'] = JHTML::_('select.genericlist',  $sections, 'sectionid', 'class="inputbox" size="1" '.$javascript, 'id', 'title', intval($email->sectionid));
		}
		$sections = $db->loadObjectList();
		$sectioncategories 			= array();
		$sectioncategories[-1] 		= array();
		$sectioncategories[-1][] = JHTML::_('select.option', '-1', JText::_( 'Select Category' ), 'id', 'title');
		foreach($sections as $section) {
			$sectioncategories[$section->id] = array();
			$query = "SELECT id, title"
			. "\n FROM #__categories"
			. "\n WHERE section = '$section->id'"
			. "\n ORDER BY ordering"
			;
			$db->setQuery( $query );
			$rows2 = $db->loadObjectList();
			foreach($rows2 as $row2) {
				$sectioncategories[$section->id][] = JHTML::_('select.option', $row2->id, $row2->title, 'id', 'title');
			}
		}
		// get list of categories
		if ( !$email->catid && !$email->sectionid ) {
			$categories[] = JHTML::_('select.option', '-1', JText::_( 'Select Category' ), 'id', 'title');
			$lists['catid'] = JHTML::_('select.genericlist',  $categories, 'catid', 'class="inputbox" size="1"', 'id', 'title');} else {
				$query = "SELECT id, title"
				. "\n FROM #__categories"
				. $where
				. "\n ORDER BY ordering"
				;
				$db->setQuery( $query );
				$categories[] = JHTML::_('select.option', '-1', JText::_( 'Select Category' ), 'id', 'title');
				$categories 		= array_merge( $categories, $db->loadObjectList() );
				$lists['catid'] = JHTML::_('select.genericlist',  $categories, 'catid', 'class="inputbox" size="1"', 'id', 'title', intval($email->catid));
			}
		
		
				// creator
			$db->setQuery( "SELECT `id`, `username` FROM #__users" );
			$creators = $db->loadObjectList();
			foreach($creators as $creator)
			{
				$creator_options[] = JHTML::_('select.option', $creator->id, $creator->username, 'id', 'title');
			}
			$lists['creator'] = JHTML::_('select.genericlist',  $creator_options, 'created_by', 'class="inputbox" size="1"', 'id', 'title', !empty($email->created_by)?intval($email->created_by):$user->get('id'));
		
		// joomsocial wall
		if(component_insalled('com_community')){
			$component[] = JHTML::_('select.option', 'com_community', 'JoomSocial Wall App', 'id', 'title');
			$lists['joomsocial_cid'] = JHTML::_('select.genericlist',  $creator_options, 'params[joomsocial_cid]', 'class="inputbox" size="1"', 'id', 'title', $params->get('joomsocial_cid'));
		}
		
		// agora forums
		if(component_insalled('com_agora')){
			$component[] = JHTML::_('select.option', 'com_agora', 'Agora Forum', 'id', 'title');
			define('IN_AGORA','1');
			require_once( JPATH_SITE .DS.'components'.DS.'com_agora'.DS.'include'.DS.'utils.php' );
			require_once( JPATH_SITE .DS.'components'.DS.'com_agora'.DS.'include'.DS.'db.php' );
			require_once( JPATH_SITE .DS.'components'.DS.'com_agora'.DS.'include'.DS.'db.php5.php' );
			require_once( JPATH_SITE .DS.'components'.DS.'com_agora'.DS.'model'.DS.'model.php' );
			require_once( JPATH_SITE .DS.'components'.DS.'com_agora'.DS.'model'.DS.'board.php' );
			
			$agora = new BoardModel();
			$agora_forum_list = $agora->loadForumList(true);
			
			$agora_forums = array();
			
			foreach($agora_forum_list as $agora_category){
						
				$agora_forums[] = JHTML::_('select.optgroup', $agora_category['cat_name'], 'id', 'title');
							
				if(sizeof($agora_category[forums]) > 0){	
					foreach($agora_category[forums] as $agora_forum){
						$agora_forums[] = JHTML::_('select.option', $agora_forum['id'], $agora_forum['forum_name'], 'id', 'title');
					}
				}
			}
							
			$lists['agora_forum_id'] = JHTML::_('select.genericlist',  $agora_forums, 'params[agora_forum_id]', 'class="inputbox" size="1"', 'id', 'title', $params->get('agora_forum_id'));
		}
		
		//kunena forums
		if(component_insalled('com_kunena')){
			
			$com_kunena = JComponentHelper::getComponent( 'com_kunena' );
			$kunena_params = new JParameter( $com_kunena->params );
			
			$component[] = JHTML::_('select.option', 'com_kunena', 'Kunena Forum', 'id', 'title');
		
			if(preg_match('/^1\.[012345]/',component_version('com_kunena')))
			{		
				require_once (JPATH_SITE .DS.'components'.DS.'com_kunena'.DS.'lib'.DS.'kunena.defines.php');
				require_once (JPATH_SITE .DS.'components'.DS.'com_kunena'.DS.'lib'.DS.'kunena.session.class.php');	
			}
			
			require_once( JPATH_SITE .DS.'components'.DS.'com_kunena'.DS.'class.kunena.php' );
			
			$kunena_forum_list = JJ_categoryArray();
				
			foreach ($kunena_forum_list as $kunena_forum_item) {
				$kunena_forums[] = ($kunena_forum_item->parent == 0)?JHTML::_('select.optgroup', $kunena_forum_item->treename, 'id', 'title'):JHTML::_('select.option', $kunena_forum_item->id, $kunena_forum_item->treename, 'id', 'title');
			}
			$lists['kunena_forum_id'] = JHTML::_('select.genericlist',  $kunena_forums, 'params[kunena_forum_id]', 'class="inputbox" size="1"', 'id', 'title', $params->get('kunena_forum_id'));
			
		}
		
		//components
		$component[] = JHTML::_('select.option', 'com_content', 'Articles', 'id', 'title');		
		$lists['component'] = JHTML::_('select.genericlist',  $component, 'params[component]', 'class="inputbox" size="1"', 'id', 'title', $params->get('component','com_content'));
		
		//access level
		$access_level[] = JHTML::_('select.option', '0', 'Public', 'id', 'title');
		$access_level[] = JHTML::_('select.option', '1', 'Registered', 'id', 'title');
		$access_level[] = JHTML::_('select.option', '2', 'Special', 'id', 'title');
		$lists['access_level'] = JHTML::_('select.genericlist',  $access_level, 'params[access_level]', 'class="inputbox" size="1"', 'id', 'title', $params->get('access_level',0));
		
		// meta tags reorder
			$meta_tags_reorder[] = JHTML::_('select.option', 'default', 'Default (No Change) ', 'id', 'title');
			$meta_tags_reorder[] = JHTML::_('select.option', 'backwards', 'Backwards', 'id', 'title');
			$meta_tags_reorder[] = JHTML::_('select.option', 'shuffle', 'Shuffle', 'id', 'title');
			$lists['meta_tags_reorder'] = JHTML::_('select.genericlist',  $meta_tags_reorder, 'params[meta_tags_reorder]', 'class="inputbox" size="1"', 'id', 'title', $params->get('meta_tags_reorder','default'));
		
			$view	= &$this->getView('default','html');
			$view->assignRef('params', $params);
			$view->assignRef('email', $email);
			$view->assignRef('lists', $lists);
			$view->assignRef('sectioncategories', $sectioncategories);
			$view->display('new');
	}
	function copy()
	{
		
		global $mainframe;

		// Check for request forgeries
		JRequest::checkToken() or jexit( 'Invalid Token' );

		// Initialize variables
		$db			= & JFactory::getDBO();

		$cid		= JRequest::getVar( 'cid', array(), 'post', 'array' );
		$option		= JRequest::getCmd( 'option' );

		JArrayHelper::toInteger($cid);

		$item	= null;

		$total = count($cid);
		for ($i = 0; $i < $total; $i ++)
		{
			$query = 'SELECT a.*' .
				' FROM #__post_by_email AS a' .
				' WHERE a.id = '.(int) $cid[$i];
			$db->setQuery($query, 0, 1);
			$item = $db->loadObject();
			
			$row = array();
			// values loaded into array set for store			
			$row['id'] = NULL;
			$row['title'] = 'Copy Of '.$item->title;
			$row['email'] = $item->email;
			$row['sectionid'] = $item->sectionid;
			$row['catid'] = $item->catid;
			$row['default_author'] = $item->default_author;
			$row['created_by'] = $item->created_by;
			$row['created'] = $item->created;
			$row['cronjobs'] = $item->cronjobs;
			$row['last_run'] = $item->last_run;
			$row['published'] = 0;
			$row['front_page'] = $item->front_page;
			$row['checked_out'] = $item->checked_out;
			$row['checked_out_time'] = $item->checked_out_time;
			$row['params'] = $item->params;

			$model = $this->getModel('email');
			if ($model->store($row)) {
				$msg = JText::_( 'Email Copied' );
			} else {
				$msg = JText::_( 'Error Copying email' );
			}	
		}

		$msg = JText::sprintf('Item(s) successfully copied ', $total, $section, $category);
		$mainframe->redirect('index.php?option='.$option, $msg);

	}
	
	function save()
	{
		JRequest::checkToken() or die( 'Invalid Token' );
		$post	= JRequest::get('post');
		$cid	= JRequest::getVar( 'cid', array(0), 'post', 'array' );

		$post['id'] = (int) $cid[0];
		
		$params	= JRequest::getVar( 'params', null, 'post', 'array' );

		if (is_array($params))
		{
			$txt = array ();
			foreach ($params as $k => $v) {
				$txt[] = $k.'='.str_replace(array("\r\n", "\n", "\r"),'',$v);
			}
			$post['params'] = implode("\n", $txt);
		}
				
		$model = $this->getModel('email');
		if ($model->store($post)) {
			$msg = JText::_( 'email Saved' );
		} else {
			$msg = JText::_( 'Error Saving email' );
		}
		$model->checkin();
		$link = 'index.php?option=com_post_by_email';
		$this->setRedirect($link, $msg);
	}
	function publishemail($publish = 1, $action = 'publish')
	{
		JRequest::checkToken() or die( 'Invalid Token' );
		$cid = JRequest::getVar( 'cid', array(), 'post', 'array' );
		JArrayHelper::toInteger($cid);
		if (count( $cid ) < 1) {
			JError::raiseError(500, JText::_( 'Select an item to '.$action ) );
		}
		$model = $this->getModel('email');
		if(!$model->publish($cid, $publish)) {
			echo "<script> alert('".$model->getError(true)."'); window.history.go(-1); </script>\n";
		}
		$this->setRedirect( 'index.php?option=com_post_by_email' );
	}
	function frontpageemail($frontpage = 1, $action = 'front_yes')
	{
		JRequest::checkToken() or die( 'Invalid Token' );
		$cid = JRequest::getVar( 'cid', array(), 'post', 'array' );
		JArrayHelper::toInteger($cid);
		if (count( $cid ) < 1) {
			JError::raiseError(500, JText::_( 'Select an item to '.$action ) );
		}
		$model = $this->getModel('email');
		if(!$model->frontpage($cid, $frontpage)) {
			echo "<script> alert('".$model->getError(true)."'); window.history.go(-1); </script>\n";
		}
		$this->setRedirect( 'index.php?option=com_post_by_email' );
	}
	function remove()
	{
		global $mainframe;
		JRequest::checkToken() or die( 'Invalid Token' );
		$db			= & JFactory::getDBO();
		$cid		= JRequest::getVar( 'cid', array(), 'post', 'array' );
		JArrayHelper::toInteger($cid);
		if (count($cid) < 1) {
			$msg =  JText::_('Select an item to delete');
			$mainframe->redirect('index.php?option=com_post_by_email', $msg, 'error');
		}
		$model = $this->getModel('email');
		if(!$model->delete($cid, $frontpage)) {
			echo "<script> alert('".$model->getError(true)."'); window.history.go(-1); </script>\n";
		}
		$msg = JText::sprintf('Item(s) deleted', count($cid));
		$mainframe->redirect('index.php?option=com_post_by_email', $msg);
	}
	/**
	* Cancels an edit operation
	*/
	function cancel()
	{
		global $mainframe;
		// Check for request forgeries
		JRequest::checkToken() or die( 'Invalid Token' );
		// Initialize variables
		$db	= & JFactory::getDBO();
		// Check the article in if checked out
		$model = $this->getModel('email');
		$model->checkin();
		$mainframe->redirect('index.php?option=com_post_by_email');
	}
	
	function importall(){
		$db = & JFactory::getDBO();
		$db->setQuery( "SELECT id FROM #__post_by_email WHERE published = '1'" );
		$cid = $db->loadResultArray();
		$formData['cid'] = $cid;
		return $this->import( $formData );
	}

    function import($formData)
    {
	global $mainframe;
	require_once( JPATH_BASE .DS.'components'.DS.'com_content'.DS.'models'.DS.'element.php' );
	require_once( JPATH_COMPONENT . '/pop3.class.php' );
	$objResponse = new xajaxResponse();
	$objResponse->addScript("resetMsgArea();");
	// Initialize variables
	$db = & JFactory::getDBO();
	
	$cid = (empty($formData['cid'])) ? JRequest::getVar( 'cid', array(), 'get', 'array' ) : $formData['cid'];

	foreach($cid as $emailId) {
		$model = $this->getModel('email');
		$model->setId($emailId);
		$email = $model->getData();
		$params = new JParameter($email->params);
		
		/** Only check at this interval for new messages. */
		if ( !defined('WP_MAIL_INTERVAL') )
			define('WP_MAIL_INTERVAL', 300); // 5 minutes
		
		$phone_delim = '::';
		
		$pop3 = new POP3();
		$count = 0;
		
		if ( ! $pop3->connect($params->get('server'), $params->get('port') ) ||
			! $pop3->user($params->get('login')) ||
			( ! $count = $pop3->pass($params->get('password')) ) ) {
				$pop3->quit();
		}
		
		for ( $i = 1; $i <= $count; $i++ ) {
		
			$message = $pop3->get($i);
		
			$bodysignal = false;
			$boundary = '';
			$charset = '';
			$content = '';
			$content_type = '';
			$content_transfer_encoding = '';
			$author_found = false;
			$dmonths = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
			if(sizeof($message) > 0){
				foreach ($message as $line) {
					// body signal
					if ( strlen($line) < 3 )
						$bodysignal = true;
					if ( $bodysignal ) {
						$content .= $line;
					} else {
						if ( preg_match('/Content-Type: /i', $line) ) {
							$content_type = trim($line);
							$content_type = substr($content_type, 14, strlen($content_type) - 14);
							$content_type = explode(';', $content_type);
							if ( ! empty( $content_type[1] ) ) {
								$charset = explode('=', $content_type[1]);
								$charset = ( ! empty( $charset[1] ) ) ? trim($charset[1]) : '';
							}
							$content_type = $content_type[0];
						}
						if ( preg_match('/Content-Transfer-Encoding: /i', $line) ) {
							$content_transfer_encoding = trim($line);
							$content_transfer_encoding = substr($content_transfer_encoding, 27, strlen($content_transfer_encoding) - 27);
							$content_transfer_encoding = explode(';', $content_transfer_encoding);
							$content_transfer_encoding = $content_transfer_encoding[0];
						}
						if ( ( $content_type == 'multipart/alternative' ) && ( false !== strpos($line, 'boundary="') ) && ( '' == $boundary ) ) {
							$boundary = trim($line);
							$boundary = explode('"', $boundary);
							$boundary = $boundary[1];
						}
						if (preg_match('/Subject: /i', $line)) {
							$subject = trim($line);
							$subject = substr($subject, 9, strlen($subject) - 9);
							// Captures any text in the subject before $phone_delim as the subject
							if ( function_exists('iconv_mime_decode') ) {
								$subject = iconv_mime_decode($subject, 2, 'UTF-8');
							} else {
								$subject = wp_iso_descrambler($subject);
							}
							$subject = explode($phone_delim, $subject);
							$subject = $subject[0];
						}
			
						if (preg_match('/Date: /i', $line)) { // of the form '20 Mar 2002 20:32:37'
							$ddate = trim($line);
							$ddate = str_replace('Date: ', '', $ddate);
							if (strpos($ddate, ',')) {
								$ddate = trim(substr($ddate, strpos($ddate, ',') + 1, strlen($ddate)));
							}
							$date_arr = explode(' ', $ddate);
							$date_time = explode(':', $date_arr[3]);
			
							$ddate_H = $date_time[0];
							$ddate_i = $date_time[1];
							$ddate_s = $date_time[2];
			
							$ddate_m = $date_arr[1];
							$ddate_d = $date_arr[0];
							$ddate_Y = $date_arr[2];
							for ( $j = 0; $j < 12; $j++ ) {
								if ( $ddate_m == $dmonths[$j] ) {
									$ddate_m = $j+1;
								}
							}
			
							$time_zn = intval($date_arr[4]) * 36;
							$ddate_U = gmmktime($ddate_H, $ddate_i, $ddate_s, $ddate_m, $ddate_d, $ddate_Y);
							$ddate_U = $ddate_U - $time_zn;
							$created = gmdate('Y-m-d H:i:s', $ddate_U + $time_difference);
							//$post_date_gmt = gmdate('Y-m-d H:i:s', $ddate_U);
						}
					}
				}
			}
		
			$subject = trim($subject);
		
			if ( $content_type == 'multipart/alternative' ) {
				$content = explode('--'.$boundary, $content);
				$content = $content[2];
				// match case-insensitive content-transfer-encoding
				if ( preg_match( '/Content-Transfer-Encoding: quoted-printable/i', $content, $delim) ) {
					$content = explode($delim[0], $content);
					$content = $content[1];
				}
				$content = strip_tags($content, '<img><p><br><i><b><u><em><strong><strike><font><span><div>');
			}
			$content = trim($content);
		
			if ( false !== stripos($content_transfer_encoding, "quoted-printable") ) {
				$content = quoted_printable_decode($content);
			}
		
			if ( function_exists('iconv') && ! empty( $charset ) ) {
				$content = iconv($charset, 'UTF-8', $content);
			}
		
			// Captures any text in the body after $phone_delim as the body
			$content = explode($phone_delim, $content);
			$content = empty( $content[1] ) ? $content[0] : $content[1];
		
			$content = trim($content);
			
			switch($params->get('component'))
			{
				case 'com_community':
					require_once( JPATH_SITE .DS.'components'.DS.'com_community'.DS.'libraries'.DS.'core.php' ); //2.0.0
					require_once( JPATH_SITE .DS.'components'.DS.'com_community'.DS.'models'.DS.'wall.php' );
					
					if(CommunityModelWall::addPost('user', $params->get('joomsocial_cid'), $email->created_by, $content))
					{				
						$msg = JText::_( 'Mail Copied' );
					}else{
						$msg = JText::_( 'Error Copying Mail' );
					}
				break;
			
				case 'com_agora':
				
					define('IN_AGORA','1');
					define ('AGORA_TIME',time()); 
					require_once( JPATH_SITE .DS.'components'.DS.'com_agora'.DS.'include'.DS.'utils.php' );
					require_once( JPATH_SITE .DS.'components'.DS.'com_agora'.DS.'include'.DS.'db.php' );
					require_once( JPATH_SITE .DS.'components'.DS.'com_agora'.DS.'include'.DS.'db.php5.php' );
					require_once( JPATH_SITE .DS.'components'.DS.'com_agora'.DS.'model'.DS.'model.php' );
					require_once( JPATH_SITE .DS.'components'.DS.'com_agora'.DS.'model'.DS.'topic.php' );
					
					$agora_forum = new TopicModel();
					//loadAll($key = '', $per_page = null, $page = null, $allowed_forums=array());
					
					//addPost($topic_id, $forum_id, $message, $user_id, $username, $hide_smilies)
					
					$user = JFactory::getUser($email->created_by);
					
					if($agora_forum->add($params->get('agora_forum_id'), $subject,'',$icon, $user->username, $user->id, $content, 0))
					{				
						$msg = JText::_( 'Mail Copied' );
					}else{
						$msg = JText::_( 'Error Copying Mail' );
					}

				break;
				case 'com_kunena':
			
				require_once( JPATH_SITE .DS.'components'.DS.'com_kunena'.DS.'lib'.DS.'kunena.defines.php');
				require_once( JPATH_SITE .DS.'components'.DS.'com_kunena'.DS.'class.kunena.php');
					
				$user = JFactory::getUser($email->created_by);
					
				$thread = 0;
				$catid = $params->get('kunena_forum_id');
				
				if($params->get('kunena_type')){
					$thread = $params->get('kunena_thread_id', 0);
					$db->setQuery("SELECT catid FROM #__kunena_messages WHERE id='$thread'");
					$db->query();
					$catid = $db->loadResult();
				}
					
				if(preg_match('/^1\.[012345]/',component_version('com_kunena')))
				{
					$holdPost = 0;
					
					if (!$is_Moderator)
					{
					    $db->setQuery("SELECT review FROM #__fb_categories WHERE id='$catid'");
					    $db->query();
					    $holdPost = $db->loadResult();
					}
					
					$kunena_query  = "INSERT INTO #__fb_messages (parent,thread,catid,name,userid,email,subject,time,ip,topic_emoticon,hold) ";
					$kunena_query .= "VALUES({$thread},{$thread},'{$catid}','{$user->username}','{$user->id}','{$user->email}','{$subject}','".time()."','127.0.0.1','','{$holdPost}')";
					
					$db->setQuery($kunena_query);
					
					if ($db->query())
					{
						
						$pid = $db->insertId();
						
						// now increase the #s in categories only case approved
						if($holdPost==0) {
							CKunenaTools::reCountUserPosts();
							CKunenaTools::reCountBoards();
						}
						
						$db->setQuery("INSERT INTO #__fb_messages_text (mesid,message) VALUES('$pid',".$db->quote($content).")");
						$db->query();

						// A couple more tasks required...
						if ($thread == 0) {
						    //if thread was zero, we now know to which id it belongs, so we can determine the thread and update it
						    $db->setQuery("UPDATE #__fb_messages SET thread='".($params->get('kunena_type')?$thread:$pid)."' WHERE id='$pid'");
						    $db->query();
							if ($user->id)
							{
							    $db->setQuery("UPDATE #__fb_users SET posts=posts+1 WHERE userid={$user->id}");
							    $db->query();
							}
						}
					}
				}
				else
				{
					$fields ['name'] = $user->username;
					$fields ['email'] = $user->email;
					$fields ['subject'] = $subject;
					$fields ['message'] = $content;
					$fields ['topic_emoticon'] = null;
			
					$options ['anonymous'] = 0;
					
					require_once (KUNENA_PATH_LIB . DS . 'kunena.posting.class.php');
					$message = new CKunenaPosting ( );
					$message->_my->id = $user->id;
					$message->_my->username = $user->username;
					$message->parent = $catid;
					
					if ($thread == 0) {
						$success = $message->post ( $catid, $fields, $options );
					} else {
						$success = $message->reply ( $thread, $fields, $options );
					}
			
					if ($success) {
						$success = $message->save ();
					}
					/*
					if (! $success) {
						$errors = $message->getErrors ();
						foreach ( $errors as $field => $error )
						echo "$field: $error<br />";
					}
					*/
					$id = $message->get ( 'id' );
					
					$db->setQuery("UPDATE #__kunena_messages_text SET message=".$db->quote($fields['message'])." WHERE mesid=".$db->quote($id));
					$db->query ();
				}
				break;
				default:
				
					$data = array();
					$data['title'] = $subject;
					$data['fulltext'] = $content;
					$data['created'] = $created;
					$data['catid'] = $email->catid;
					$data['sectionid'] = $email->sectionid;
					$data['access'] = $params->get('access_level');				
					$data['created_by'] = $email->created_by;
					if ($params->get('auto_publish') > 0 && $params->get('publish_days') > 0){
						$data['publish_down'] = date('Y-m-d H:i:s', time() + ($params->get('publish_days') * 24 * 60 * 60));
					}
					
					if (!empty($email->default_author)) {
						$data['created_by_alias'] = $email->default_author;
					}
					//$data['publish_up'] = date('Y-m-d H:i:s');
					$data['state'] = $params->get('auto_publish');
				
					$row = & JTable::getInstance('content');
					
					$row->bind($data);
					
					if ($row->store()) {
						$msg = JText::_( 'Mail Copied' );
						
						require_once (JPATH_ADMINISTRATOR.DS.'components'.DS.'com_frontpage'.DS.'tables'.DS.'frontpage.php');
						$fp = new TableFrontPage($db);
						if ($email->front_page)
						{
							if (!$fp->load($row->id))
							{
								$db->setQuery('INSERT INTO #__content_frontpage VALUES ( '. (int) $row->id .', 1 )');
								if (!$db->query())
								{
									$mosMsg = $title . '***ERROR:' . $db->getErrorMsg();
									$objResponse->addAppend("fgmsgarea", "innerHTML", '<br />' . $mosMsg);
									return $objResponse->getXML();
								}
								$fp->ordering = 1;
							}
						}
						$fp->reorder();
						$cache = & JFactory::getCache('com_content');
						$cache->clean();
					} else {
						$msg = JText::_( 'Error Copying Mail' );
					}
				
			}// end switch
			
			if($params->get('delete_processed')){
				if(!$pop3->delete($i)) {
					echo '<p>' . $pop3->ERROR . '</p>';
					$pop3->reset();
					exit;
				}
			}
		}
		
		$pop3->quit();
							
		$db->setQuery( "UPDATE #__post_by_email SET last_run = '".date('Y-m-d H:i:s')."' WHERE id = '".$email->id."'" );
		$db->query();
			
		$objResponse->addAppend("fgmsgarea", "innerHTML", "<br /><b>$count</b> emails imported (".$email->title.") in $procTime seconds.<br />");
	
	}
	
	$closeLink = '<br /><a href="javascript:closeMsgArea();">Close this window</a><br />';
	$objResponse->addAppend("fgmsgarea", "innerHTML", $closeLink);
	return $objResponse->getXML();
	//return $msg;
    }
}
?>
