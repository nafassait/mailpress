<?php
add_action(	'mp_action_viewadmin',		array('MP_Mail','mp_action_viewadmin'));
add_action(	'mp_action_view',			array('MP_Mail','mp_action_viewuser'));

// for ajax admin

add_action(	'mp_action_dim-mail',		array('MP_Mail','mp_action_send_mail'));
add_action(	'mp_action_delete-mail',	array('MP_Mail','mp_action_delete_mail'));
add_action(	'mp_action_add-mail',		array('MP_Mail','mp_action_add_mail'));

// for ajax mail-new

add_action(	'mp_action_autosave',		array('MP_Mail','mp_action_autosave'));
add_action(	'mp_action_get-previewlink',array('MP_Mail','mp_action_get_previewlink'));
add_action(	'mp_action_iview',          array('MP_Mail','mp_action_iview'));

// for connection 
add_filter('MailPress_Swift_Connection_SMTP', 	array('MP_Mail','SMTP_connect'),8,2);	

class MP_Mail
{

	function MP_Mail( $plug = MP_FOLDER )
	{
		$this->plug = $plug;
		$this->theme = new MP_Themes();
		$this->to = $this->from = $this->message = null;
	}


////	MP_Mail send functions	////

	function send($args)
	{
		$this->args = (is_numeric($args)) ? $this->get($args) : $args ;

		return $this->end( $this->start() );
	}

	function start()
	{
		$this->row = $this->mail = (object) '';

// MP_Log
		$f = (isset($this->args->forcelog)) ? true : false; 
		$this->trace = new MP_Log('MP_Mail',ABSPATH . MP_PATH,$this->plug,$f);


		if (!$this->args)	
		{
/*trace*/		$this->trace->log(__('*** ERROR *** Sorry invalid arguments in MP_Mail::send','MailPress'));
			return array(false,false);
		}

// general settings
		$mp_general = get_option('MailPress_general');

// defaults
		$rc = true;
		$this->row->status = 'sent';

// charset
		$this->row->charset = (isset($this->args->charset)) ? $this->args->charset : get_option('blog_charset');

// fromemail & fromname
		$this->row->fromemail 	= (empty($this->args->fromemail)) ? $mp_general['fromemail'] : $this->args->fromemail;
		$this->row->fromname 	= (empty($this->args->fromname))  ? $mp_general['fromname']  : $this->args->fromname;

// toemail & toname

		if (isset($this->args->replacements) && (0 != count($this->args->replacements)))
		{
			$this->row->toemail = $this->args->replacements;
			$this->row->toname  = '';
			$this->batch = true;
		}
		elseif ( (isset($this->args->toemail)) && (!empty($this->args->toemail)) )
		{
			$this->row->toemail = $this->args->toemail;
			$this->row->toname 	= (empty($this->args->toname))  ? $this->args->toemail : $this->args->toname;
			$this->row->toname 	= MP_Mail::display_name($this->row->toname);
			$this->batch = false;
		}
		else
		{
// no recipients ?
		$this->trace->log(__('*** ERROR *** No recipient','MailPress'));	
			return array(false,false);
		}


// subject
		$this->row->subject = (isset($this->args->subject)) ? $this->args->subject : false;
		$pt = $this->theme->get_page_templates_from((isset($this->args->Theme)) ? $this->args->Theme : $this->theme->themes[$this->theme->current_theme]['Template']);
		if (isset($this->args->Template)) if (isset($pt[$this->args->Template][1]) ) $this->row->subject = $pt[$this->args->Template][1];
		$this->row->subject = ($this->row->subject) ? trim($this->mail_decorator($this->do_eval($this->row->subject),get_object_vars($this->args))) : '';
// plaintext
		$this->row->plaintext   = trim(strip_tags($this->build_mail_content('plaintext')));
// html
		$this->row->html        = trim($this->build_mail_content('html'));
		$this->row->html        = ( '<br />' == $this->row->html ) ? '' : $this->row->html;

		$this->row->theme		= $this->mail->theme;
		$this->row->themedir	= $this->mail->themedir;
		$this->row->template 	= $this->mail->template;

// mail empty ?
		if (!$this->row->subject && !$this->row->plaintext && !$this->row->html)
		{
/*trace*/		$this->trace->log(__('*** WARNING *** Mail is empty','MailPress'));
			return array(false,false);
		}


/*trace*/	$x  = " \n\n ------------------- start of mail -------------------  ";
/*trace*/	$x .= " \n From : " . $this->row->fromname . " <" . $this->row->fromemail . "> ";
/*trace*/	$x .= " \n Subject : "                                               . $this->row->subject;
/*trace*/	$x .= " \n   ------------------- plaintext -------------------  \n " . $this->row->plaintext;
/*trace*/	$x .= " \n\n ------------------- text/html -------------------  \n " . $this->row->html;
/*trace*/	$x .= " \n\n ------------------ end of mail ------------------  \n\n";
/*trace*/	$this->trace->log($x,$this->trace->levels[512]);


		$batchprocessing = false;
// no mail ?
		if (isset($this->args->nomail))
		{
/*trace*/		$this->trace->log(__(':::: Mail not sent as required ::::','MailPress'));
		}
		elseif (apply_filters('MailPress_batch_send_status',false) && $this->batch && (count($this->row->toemail) > 1))
		{
			$batchprocessing = true;
			$this->row->status = (apply_filters('MailPress_batch_send_status','sent'));
/*trace*/	$this->trace->log(':::: Mail batch processing as required ::::');
		}
		else
		{
			$rc = $this->swift_processing();
		}

/** MAIL SAVE **/

		if (!isset($this->args->noarchive) && $rc) 
		{ 
			global $wpdb;

			if (!isset($this->args->nostats)) MailPress::update_stats('t',isset($this->args->Template) ? $this->args->Template : '',(isset($this->args->replacements)) ? count($this->args->replacements) : 1);

			$now	  	= date('Y-m-d H:i:s');
			$user_id 	= MailPress::get_wp_user_id();

			if (isset($this->args->id)) $this->row->id = $this->args->id;

			if ($this->batch) $this->row->toemail = mysql_real_escape_string(serialize($this->row->toemail));

			$this->row->subject         = mysql_real_escape_string(trim($this->row->subject));
			$this->row->html			= mysql_real_escape_string(trim($this->row->html));
			$this->row->plaintext		= mysql_real_escape_string($this->row->plaintext);

			$this->row->created 		= $now;
			$this->row->created_user_id = $user_id;
			$this->row->sent 			= $now;
			$this->row->sent_user_id 	= $user_id;

			if (isset($this->row->id)) 
			{
				wp_cache_delete($this->row->id,'mp_mail');
				$query = "UPDATE $wpdb->mp_mails SET status = '" . $this->row->status . "', theme = '" . $this->row->theme . "', themedir = '" . $this->row->themedir . "', template = '" . $this->row->template . "', fromemail = '" . $this->row->fromemail . "', fromname = '" . $this->row->fromname . "', toemail = '" . $this->row->toemail . "', toname = '" . $this->row->toname . "',  charset = '" . $this->row->charset . "', subject = '" . $this->row->subject . "', html = '" . $this->row->html . "', plaintext = '" . $this->row->plaintext . "', sent = '" . $this->row->sent . "', sent_user_id = " . $this->row->sent_user_id . " WHERE id = " . $this->row->id . " ;";
			}
			else
			{
				$query = "INSERT INTO $wpdb->mp_mails (status, theme, themedir, template, fromemail, fromname, toemail, toname, charset, subject, html, plaintext, created, created_user_id, sent, sent_user_id ) values ('" . $this->row->status . "', '" . $this->row->theme . "', '" . $this->row->themedir . "', '" . $this->row->template . "', '" . $this->row->fromemail . "', '" . $this->row->fromname . "', '" . $this->row->toemail . "', '" . $this->row->toname . "', '" . $this->row->charset . "', '" . $this->row->subject . "', '" . $this->row->html . "', '" . $this->row->plaintext . "', '" . $this->row->created . "', " . $this->row->created_user_id . ", '" . $this->row->sent . "', " . $this->row->sent_user_id . " );";
			}

			$x = $wpdb->query( $query );

			if (!$x)
/*trace*/		$this->trace->log(sprintf(__('*** ERROR *** Database error, Mail not saved : %1$s','MailPress'), $wpdb->last_error));
			elseif ($batchprocessing)
			{
/*trace*/		$this->trace->log(':::: MAIL SAVED for batch processing ::::');
				do_action('MailPress_batch_send_schedule');
			}
			else
/*trace*/		$this->trace->log(__('::: MAIL SAVED :::','MailPress'));

			return ($x) ? true : false ;
		}
		else
		{
			if (isset($this->args->noarchive) && (isset($this->args->id))) MP_Mail::delete($this->args->id);
		}
		return $rc;
	}

	function end($rc)
	{
		$this->trace->end($rc);
		return $rc;
	}

	function do_eval($x)
	{
		ob_start();
			echo(eval('global $posts, $post, $wp_did_header, $wp_did_template_redirect, $wp_query, $wp_rewrite, $wpdb, $wp_version, $wp, $id, $comment, $user_ID; ?>' . $x));
			$x = ob_get_contents();
		ob_end_clean();
		return $x;
	}

	function build_mail()
	{
// from
		$this->from = new Swift_Address($this->row->fromemail, 	$this->row->fromname);
// to 
		if (!$this->batch) $this->to = new Swift_Address($this->row->toemail, 	$this->row->toname);
		else
		{
			$this->to = new Swift_RecipientList();
			//foreach ($this->row->toemail as $dest)	$this->to->addTo($dest['{{toemail}}'],$dest['{{toemail}}']);
			foreach ($this->row->toemail as $dest)	$this->to->addTo($dest['{{toemail}}'], MP_Mail::display_name($dest['{{toemail}}']));
		}
// subject 
		$this->message 	 = new Swift_Message($this->row->subject);
		$this->message->headers->setLanguage(substr(WPLANG,0,2));
		$this->message->headers->setCharset($this->row->charset);
// plaintext 
		if ($this->row->plaintext)
		{
			$plaintxt 	  = new Swift_Message_Part($this->row->plaintext);
			$plaintxt->setCharset($this->row->charset);
			$this->message->attach($plaintxt);
		}
// html
		if ($this->row->html)
		{
			$htmltxt 	  = new Swift_Message_Part( $this->process_img($this->row->html,$this->row->themedir ) , 'text/html');
			$htmltxt->setCharset($this->row->charset);
			$this->message->attach($htmltxt);
			$this->row->html = $this->process_img($this->row->html,$this->row->themedir,'draft');
		}
	}

	function build_mail_content($type)
	{
		$x = $theme = $themedir = $templatefile = $content = '';
		$ct = $this->theme->current_theme;

//theme & themedir

		switch ($type)
		{
			case 'plaintext' :
				$this->theme->current_theme = 'plaintext';
				$x  = $this->theme->get_theme_by_template('plaintext');
				if (empty($x))
				{
/*trace*/           if (isset($this->trace)) $this->trace->log('Missing theme : plaintext theme has been deleted.',$this->trace->levels[512]);
					$this->theme->current_theme = $ct;
					return '';
				}
				$themedir 					= $x['Template Dir'];
				$this->mail->theme	= $theme	= 'plaintext';
				if (isset($this->args->plaintext)) $content = $this->args->plaintext;
			break;
			default :
				if (isset($this->args->Theme))
				{
					$x  = $this->theme->get_theme_by_template($this->args->Theme);
					if (!empty($x))
					{
						$this->theme->current_theme 	= $x['Name'];
						$this->mail->theme		= $theme 	= $this->args->Theme;
						$this->mail->themedir		= $themedir = $x['Template Dir'];
					}
					else
/*trace*/           if (isset($this->trace)) $this->trace->log(sprintf('Missing theme : %1$s. You should create this theme with the appropriate files (header, footer, ...) & templates.', $this->args->Theme),$this->trace->levels[512]);
				}
				if (empty($x))
				{
					$this->mail->theme 	= $theme 	= $this->theme->themes[$this->theme->current_theme]['Template'];
					$this->mail->themedir  	= $themedir = $this->theme->themes[$this->theme->current_theme]['Template Dir'];
				}
				if (isset($this->args->html)) $content = $this->args->html;
			break;
		}

//template
		$templatefile  		= '';
		$this->mail->template 	= '';
		$pt = $this->theme->get_page_templates_from($this->mail->theme);

		if (isset($this->args->Template)) 
		{		
			if (isset($pt[$this->args->Template]))
			{
				$templatefile  		= $pt[$this->args->Template][0];
				$this->mail->template 	= $this->args->Template;
			}	
			else
/*trace*/       if (isset($this->trace)) $this->trace->log(sprintf('Missing template file %1$s. You should create the template in %2$s .', $this->args->Template, $themedir ),$this->trace->levels[512]);
		}

		$fname = ABSPATH . $themedir . '/' . $templatefile;
		if (is_file($fname))	$content = file_get_contents($fname);
		else
		{
			if ('' == $content)
				if (isset($this->args->content))
					$content = $this->args->content;
			if ('' != $content)
				$content = '<?php $this->get_header(); ?>' . $content . '<?php $this->get_footer(); ?>' ;
		}

		if ('' == $content) 
		{
			$this->theme->current_theme = $ct;
			return '';
		}
			
// functions.php
		$fname = ABSPATH . $themedir . '/' . 'functions.php';
		if (is_file($fname)) $content = "<?php require_once ('$fname'); ?>" . $content;

		$x = $this->do_eval($content);

		$this->theme->current_theme = $ct;

		return $this->mail_decorator($x,get_object_vars($this->args));
	}

	function mail_decorator($text,$x='',$sepb='{{',$sepa='}}',$before='',$first=0)
	{
		foreach($x as $key => $value)
		{
			if (is_object($value) || is_array($value))
			{
				$abefore= (0 == $first) 	? $key 				: $before . '[' . $key . ']'; 
				$a = (is_object($value)) ? get_object_vars($value) : $value;
				$text = $this->mail_decorator($text, $a , $sepb , $sepa , $abefore , $first + 1 );
			}
			else 
			{
				$x 		= (0 == $first)		? $key 				: $before . '[' . $key . ']'; 
				$x = $sepb . $x . $sepa;
				$text = str_replace($x,$value,$text,$c);
/*trace*/		if (isset($this->trace))
                    if (0 < $c ) 	$this->trace->log('Mail decorator : ' . $first . ' ' . $x . ' => ' . $value . " *** DONE ($c) *** ");
			}
		}
		return $text;	
	}

	function process_img($html,$path,$dest='mail')
	{
		$x		= $matches = array();
		$masks 	= array ('', $path . '/images/', $path . '/');

		$siteurl 	= get_option('siteurl') . '/';
		$fprefix 	= ('mail' == $dest) ? ABSPATH : $siteurl;

		$output = preg_match_all('/<img[^>]*>/Ui', $html, $imgtags, PREG_SET_ORDER); // all img tag

		foreach ($imgtags as $imgtag)
		{
			$output = preg_match_all('/src=[\'"]([^\'"]+)[\'"]/Ui', $imgtag[0], $src, PREG_SET_ORDER); // for src attribute
			$matches[] = array(0 => $imgtag[0], 1 => $src[0][1]);
		}

		foreach ($matches as $match)
		{
			$f = $u = false;

			if (stristr($match[1],$siteurl) && apply_filters('MailPress_process_img_url',false))
			{
				$imgs[$match[1]] = $match[1];
				continue;
			}
			elseif (stristr($match[1],$siteurl)) $u = true;
			elseif ((stristr($match[1],'http://')) || (stristr($match[1],'https://')))
			{
				$imgs[$match[1]] = $match[1];
				continue;
			}

			foreach ($masks as $mask)
			{
				if ($u) 	$file = str_ireplace($siteurl,'',$match[1]);
				else		$file = $mask . $match[1];

				if (is_file(ABSPATH . $file)) 
				{
					$f = true;
					$x[$match[1]] = $fprefix . $file;		// we can have the src/url image in different img tags ... so we embed it one time only
/*trace*/           if (isset($this->trace)) if ('mail' == $dest) $this->trace->log('Image found : ' . $file,$this->trace->levels[512]);
				}
				if ($f) break;
			}
/*trace*/	if (isset($this->trace)) if (('mail' == $dest) && (!$f)) $this->trace->log('Image NOT found : ' . $match[1],$this->trace->levels[512]);
		}

		if ('mail' == $dest)
		{
			foreach ($x as $key => $file)
			{
				try 
				{
					$y = new Swift_File($file);
					$z = new Swift_Message_Image($y);
					$imgs[$key] = $this->message->attach($z);
				}
				catch (Swift_FileException $e) 
				{
/*trace*/           if (isset($this->trace)) $this->trace->log('**** SWIFT ERROR ****' . "There was a problem with this image: $file \n" . $e->getMessage());
				} 
			}
		}
		else
		{
			foreach ($x as $key => $file)
			{
				$imgs[$key] = $file;
			}
		}

		foreach ($matches as $match)
		{
			$match[3]  = str_replace($match[1], $imgs[$match[1]], $match[0]); // and we retrieve it now with the proper <img ... />

			if ('html' != $dest) $match[3]  = apply_filters('MailPress_process_img',$match[3]); // apply_filters for 'mail','draft'

			$html      = str_replace($match[0], $match[3], $html);
		}

	return $html;
	}

	function get_header() {
		$path	= ABSPATH . $this->theme->themes[$this->theme->current_theme] ['Template Dir'];

		if ( file_exists( $path . '/header.php') )      $this->load_template( $path . '/header.php');
		elseif ('plaintext' != $this->args->Theme)      $this->load_template( ABSPATH . MP_PATH . 'mp-content/themes/default/header.php');
	}

	function get_footer() {
		$path	= ABSPATH . $this->theme->themes[$this->theme->current_theme] ['Template Dir'];

		if ( file_exists( $path . '/footer.php') )      $this->load_template( $path .  '/footer.php');
		elseif ('plaintext' != $this->args->Theme)      $this->load_template( ABSPATH . MP_PATH . 'mp-content/themes/default/footer.php');
	}

	function get_stylesheet() {
		$path	= ABSPATH . $this->theme->themes[$this->theme->current_theme] ['Stylesheet Dir'];

		if ( file_exists( $path . '/style.css') )
		{
			echo "<style type='text/css' media='all'>\n";
			$this->load_template( $path .  '/style.css');
			echo "</style>\n";
		}
		elseif ('plaintext' != $this->args->Theme)
		{
			echo "<style type='text/css' media='all'>\n";
			$this->load_template( ABSPATH . MP_PATH . 'mp-content/themes/default/style.css');
			echo "</style>\n";
		}
	}

	function get_sidebar( $name = null ) {
		$path	= ABSPATH . $this->theme->themes[$this->theme->current_theme] ['Template Dir'];

		if ( isset($name) && file_exists( $path . "/sidebar-{$name}.php") ) $this->load_template(  $path  . "/sidebar-{$name}.php");
		elseif ( file_exists(  $path . '/sidebar.php') )                    $this->load_template(  $path  . '/sidebar.php');
		elseif ('plaintext' != $this->args->Theme)                          $this->load_template( ABSPATH . MP_PATH . 'mp-content/themes/default/sidebar.php');
	}

	function load_template($_template_file) 
	{
		global $posts, $post, $wp_did_header, $wp_did_template_redirect, $wp_query, $wp_rewrite, $wpdb, $wp_version, $wp, $id, $comment, $user_ID;
	
		if ( is_array($wp_query->query_vars) )  extract($wp_query->query_vars, EXTR_SKIP);

		include($_template_file);
	}


////	MP_Mail SWIFT functions	////

	function swift_processing()
	{

/** SWIFT MESSAGE **/
		try 
		{
			$this->build_mail();
		}
		catch (Swift_Message_MimeException $e) 
		{
/*trace*/		$this->trace->log('**** SWIFT ERROR ****' . "There was an unexpected problem building the mail:\n" . $e->getMessage());	
			return false;
		}

/** SWIFT CONNECTION **/
		try 
		{
			$Swift_Connection_type = apply_filters('MailPress_Swift_Connection_type','SMTP');

			$conn = apply_filters('MailPress_Swift_Connection_' . $Swift_Connection_type , null, $this->trace );

			$this->swift = new Swift($conn);
		}
		catch (Swift_ConnectionException $e) 
		{
/*trace*/		$this->trace->log('**** SWIFT ERROR ****' . "There was a problem connecting with $Swift_Connection_type :\n" . $e->getMessage());	
			return false;
		} 

		Swift_CacheFactory::setClassName('Swift_Cache_Disk');
		Swift_Cache_Disk::setSavePath(MP_TMP . "/tmp");

/** SWIFT SEND **/
		try 
		{
			switch (true)
			{
				case (true  === $this->batch) :
					require_once MP_TMP . '/mp-includes/class/swift/Swift/Plugin/Decorator.php';
					$this->swift->attachPlugin(new Swift_Plugin_Decorator($this->row->toemail), 'decorator');

					if ($this->swift->batchSend($this->message, $this->to , $this->from)) break;

					$this->swift->disconnect(); 
					return false;
				break;
				case (false === $this->batch) :
					if (!$this->swift->send($this->message, $this->to , $this->from)) {$this->swift->disconnect(); return false;}
					$this->swift->disconnect();
				break;
				default :
					return apply_filters('MailPress_swift_send',true,$this);
				break;
			}
		}
		catch (Swift_ConnectionException $e) 
		{
/*trace*/		$this->trace->log('**** SWIFT ERROR ****' . "There was a problem sending with $Swift_Connection_type :\n" . $e->getMessage());	
			return false;
		}
		return true;
	}

////	MP_Mail SMTP functions	////

	public static function SMTP_connect($x,$y)
	{
		require_once MP_TMP . '/mp-includes/class/swift/Swift/Connection/SMTP.php';

		$smtp_settings = get_option('MailPress_smtp_config');

		$enc 		= null;
		switch (true)
		{
			case ('ssl' == $smtp_settings ['ssl']) :
				$enc = Swift_Connection_SMTP::ENC_SSL;
			break;
			case ('tls' == $smtp_settings ['ssl']) :
				$enc = Swift_Connection_SMTP::ENC_TLS;
			break;
		}
		$conn = new Swift_Connection_SMTP($smtp_settings ['server'], $smtp_settings ['port'], $enc);

		if (isset($smtp_settings['smtp-auth']) && (!empty($smtp_settings['smtp-auth'])))
		{
			require_once MP_TMP . '/mp-includes/class/swift/Swift/Authenticator/' . $smtp_settings['smtp-auth'] . '.php';
			switch ($smtp_settings['smtp-auth'])
			{
				case 'CRAMMD5' :
					$conn->attachAuthenticator(new Swift_Authenticator_CRAMMD5());
				break;
				case 'LOGIN' :
					$conn->attachAuthenticator(new Swift_Authenticator_LOGIN());
				break;
				case 'PLAIN' :
					$conn->attachAuthenticator(new Swift_Authenticator_PLAIN());
				break;
				case '@PopB4Smtp' :
					$conn->attachAuthenticator(new Swift_Authenticator_PopB4Smtp($smtp_settings['pophost']));
				break;
			}
		}

		if (empty($smtp_settings['username']) && empty($smtp_settings['password']))
		{
$y->log("**** Empty user/password for $Swift_Connection_type connection ****");	
		}
		else
		{
			$conn->setUsername($smtp_settings ['username']);
			$conn->setPassword($smtp_settings ['password']);
		}
		return $conn;
	}





////	MP_Mail mail functions	////
////	MP_Mail mail functions	////
////	MP_Mail mail functions	////

	public static function &get(&$mail, $output = OBJECT) {
		global $wpdb;

		switch (true)
		{
			case ( empty($mail) ) :
				if ( isset($GLOBALS['mp_mail']) ) 	$_mail = & $GLOBALS['mp_mail'];
				else						$_mail = null;
			break;
			case ( is_object($mail) ) :
				wp_cache_add($mail->id, $mail, 'mp_mail');
				$_mail = $mail;
			break;
			default :
				if ( isset($GLOBALS['mp_mail']) && ($GLOBALS['mp_mail']->id == $mail) ) 
				{
					$_mail = & $GLOBALS['mp_mail'];
				} 
				elseif ( ! $_mail = wp_cache_get($mail, 'mp_mail') ) 
				{
					$_mail = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->mp_mails WHERE id = %d LIMIT 1", $mail));
					wp_cache_add($_mail->id, $_mail, 'mp_mail');
				}
			break;
		}

		if ( $output == OBJECT ) {
			return $_mail;
		} elseif ( $output == ARRAY_A ) {
			return get_object_vars($_mail);
		} elseif ( $output == ARRAY_N ) {
			return array_values(get_object_vars($_mail));
		} else {
			return $_mail;
		}
	}

	public static function delete($id)
	{
		global $wpdb;
		MP_Mail::delete_meta( $id ); 
		$query = "DELETE FROM $wpdb->mp_mails WHERE id = $id ; ";
		return $wpdb->query( $query );
	}


/// DRAFT ///


	function get_id()
	{
		global $wpdb;
		$x 	= md5(uniqid(rand(),1));
		$now	= date('Y-m-d H:i:s');
		$wp_user = MailPress::get_wp_user_id();

		$query = "INSERT INTO $wpdb->mp_mails (status, theme, themedir, template, fromemail, fromname, toemail, toname, subject, html, plaintext, created, created_user_id, sent, sent_user_id ) values ('', '$x', '', '', '', '', '', '', '', '', '', '$now', $wp_user, '0000-00-00 00:00:00', 0);";
		$wpdb->query( $query );
		return $wpdb->get_var( "SELECT id FROM $wpdb->mp_mails WHERE theme = '$x' ;" );
	}

	function update_draft($id,$status='draft')
	{
		global $wpdb;

		wp_cache_delete($id,'mp_mail');

		$now = isset($_POST['created']) ? $_POST['created'] : date('Y-m-d H:i:s');
		if ('' != $_POST['to_list'])
		{
			$_POST['toemail'] = $_POST['to_list'];
			$_POST['toname']  = '';
		}
		if (isset($_POST['content'])) $_POST['html'] = $_POST['content'];
		$query = "UPDATE $wpdb->mp_mails SET status = '$status', theme = '', toemail = '" . trim($_POST['toemail']) . "', toname = '" . trim($_POST['toname']) . "', subject = '" . trim($_POST['subject']) . "', html = '" . trim($_POST['html']) . "', plaintext = '" . trim($_POST['plaintext']) . "', created = '$now', created_user_id = " . MailPress::get_wp_user_id() . " WHERE id = $id ;";
		return $wpdb->query( $query );
	}

	function send_draft($id=NULL,$ajax=false) {

		if (NULL == $id) return false;
		$draft = MP_Mail::get($id);
		if ('draft' != $draft->status) return false;

		$mail->id = MP_Mail::get_id();

		$count = 0;

		$query = MP_User::get_mailing_lists_query($draft->toemail);

		if ($query)
		{
			$mail->replacements = MP_User::get_recipients($query,$mail->id);
			$mail->unsubscribe  = '{{unsubscribe}}';
			$mail->viewhtml	  = '{{viewhtml}}';
			$count = count($mail->replacements);
		}
		else
		{
			if 	(!MailPress::is_email($draft->toemail)) return 'y';
			$mail->toemail 		 = $draft->toemail;
			$mail->toname		 = $draft->toname;
			$key 				 = MP_User::get_key_by_email($draft->toemail);
			if ($key)
			{
				$mail->subscribe 	 = MP_User::get_subscribe_url($key);
				$mail->unsubscribe = MP_User::get_unsubscribe_url($key);
				$mail->viewhtml	 = MP_User::get_view_url($key,$mail->id);
			}
			$count = 1;
		}

// Set mail's subject and body
		$mail->subject	= stripslashes($draft->subject);
		$mail->html		= stripslashes(apply_filters('the_content', $draft->html));
		$mail->plaintext	= stripslashes($draft->plaintext);

		$mail->draft 	= true;

		if (isset($_POST['Theme'])) $mail->Theme = $_POST['Theme'];

		if (0 == $count)				return 'x';				// no recipient

		if (MailPress::mail($mail))							// ok
		{
			if ($ajax) 	return array($mail->id);
			else		return $count;
		}
		return 0;										// ko
	}

	public static function check_mail_lock( $id ) {
		global $current_user;

		if ( !$mail = MP_Mail::get( $id ) )
			return false;

		$lock = MP_Mail::get_meta( $mail->id, '_edit_lock', true );
		$last = MP_Mail::get_meta( $mail->id, '_edit_last', true );

		$time_window = apply_filters( 'mp_check_mail_lock_window', AUTOSAVE_INTERVAL * 2 );
	
		if ( $lock && $lock > time() - $time_window && $last != $current_user->ID )	return $last;
		return false;
	}

	public static function set_mail_lock( $id ) {
		global $current_user;
		if ( !$mail = MP_Mail::get( $id ) )		return false;
		if ( !$current_user || !$current_user->ID )	return false;

		$now = time();

		MP_Mail::update_meta( $mail->id, '_edit_lock', $now );
		MP_Mail::update_meta( $mail->id, '_edit_last', $current_user->ID );
	}


////	MP_Mail mail meta functions	////
////	MP_Mail mail meta functions	////
////	MP_Mail mail meta functions	////


	public static function delete_meta( $mp_mail_id, $meta_key = '' , $meta_value = '' ) 
	{
		global $wpdb;
		if ( !is_numeric( $mp_mail_id ) ) return false;
		$meta_key = preg_replace('|[^a-z0-9_]|i', '', $meta_key);

		if ( is_array($meta_value) || is_object($meta_value) ) $meta_value = serialize($meta_value);
		$meta_value = trim( $meta_value );

		if ( ! empty($meta_value) ) 	$wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->mp_mailmeta WHERE mail_id = %d AND meta_key = %s AND meta_value = %s", $mp_mail_id, $meta_key, $meta_value) );
		elseif ( ! empty($meta_key) ) $wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->mp_mailmeta WHERE mail_id = %d AND meta_key = %s", $mp_mail_id, $meta_key) );
		else  				$wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->mp_mailmeta WHERE mail_id = %d", $mp_mail_id) );

		return true;
	}

	public static function get_meta( $mp_mail_id, $meta_key = '', $meta_value = '') 
	{
		global $wpdb;
		$mp_mail_id = (int) $mp_mail_id;

		if ( !$mp_mail_id ) return false;

		if ( !empty($meta_key) ) 
		{
			$meta_key = preg_replace('|[^a-z0-9_]|i', '', $meta_key);
			if ( !empty($meta_value) ) 
			{
				$metas = $wpdb->get_col( $wpdb->prepare("SELECT meta_value FROM $wpdb->mp_mailmeta WHERE mail_id = %d AND meta_key = %s AND meta_value = %s", $mp_mail_id, $meta_key, $meta_value) );
			}
			else
			{
				$metas = $wpdb->get_col( $wpdb->prepare("SELECT meta_value FROM $wpdb->mp_mailmeta WHERE mail_id = %d AND meta_key = %s", $mp_mail_id, $meta_key) );
			}
		}
		else
		{
			$metas = $wpdb->get_results( $wpdb->prepare("SELECT meta_value FROM $wpdb->mp_mailmeta WHERE mail_id = %d", $mp_mail_id) );
		}

		if ( empty($metas) ) 
		{
		 	if ( empty($meta_key) ) return array();
			else			return '';
		}

		$metas = array_map('maybe_unserialize', $metas);

		if ( count($metas) == 1 ) 	return $metas[0];
		else					return $metas;
	}

	public static function add_meta( $mp_mail_id, $meta_key, $meta_value ) 
	{
		global $wpdb;
		if ( !is_numeric( $mp_mail_id ) ) return false;
		$meta_key = preg_replace('|[^a-z0-9_]|i', '', $meta_key);

// FIXME: mp_mailmeta data is assumed to be already escaped
		if ( is_string($meta_value) )$meta_value = stripslashes($meta_value);
		$meta_value = maybe_serialize($meta_value);

		if (empty($meta_value)) return MP_Mail::delete_meta($mp_mail_id, $meta_key);

		$wpdb->query( $wpdb->prepare("INSERT INTO $wpdb->mp_mailmeta ( mail_id, meta_key, meta_value ) VALUES ( %d, %s, %s )", $mp_mail_id, $meta_key, $meta_value) );

		return true;
	}

	public static function update_meta($mail_id, $meta_key, $meta_value, $prev_value = '') 
	{
		global $wpdb;

		// expected_slashed ($meta_key)
		$meta_key = stripslashes($meta_key);

		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT meta_key FROM $wpdb->mp_mailmeta WHERE meta_key = %s AND mail_id = %d", $meta_key, $mail_id ) ) ) {
			return MP_Mail::add_meta($mail_id, $meta_key, $meta_value);
		}

		$meta_value = maybe_serialize($meta_value);

		$data  = compact( 'meta_value' );
		$where = compact( 'meta_key', 'mail_id' );

		if ( !empty( $prev_value ) ) 
		{
			$prev_value = maybe_serialize($prev_value);
			$where['meta_value'] = $prev_value;
		}

		$wpdb->update( $wpdb->mp_mailmeta, $data, $where );
		return true;
	}

/// DISPLAYING E-MAILS & NAMES ///

	public static function display_toemail($toemail,$toname,$tolist='')
	{
		$return = '';
		$draft_dest = MP_User::get_mailing_lists();

		if 		(!empty($tolist)  && isset($draft_dest[$tolist]))	return "<b>" . $draft_dest[$tolist] . "</b>"; 
		elseif 	(!empty($toemail) && isset($draft_dest[$toemail]))	return "<b>" . $draft_dest[$toemail] . "</b>"; 
		elseif 	(MailPress::is_email($toemail))
		{
				return MP_Mail::display_name_email($toname,$toemail);
		}
		else
		{
			$y = unserialize($toemail);
			if (is_array($y))
			{
				$return = '<select>';
				foreach ($y as $k => $v)
				{
					$return .= "<option>$k</option>";
				}
				$return .= '</select>';
				return $return;
			}
		}
		return false;
	}

	public static function display_name_email($name, $email)
	{
		if (empty($name)) return $email;
		return MP_Mail::display_name($name) . " &lt;$email&gt;";
	}

	public static function display_name($name)
	{
		if (MailPress::is_email($name))				return str_replace('@','(at)',$name); 
		else									return htmlentities(stripslashes($name),ENT_QUOTES);

	}

/// AJAX ///


////	MP_Mail admin list ajax functions	////

	public static function mp_action_delete_mail() {
		$id = isset($_POST['id'])? (int) $_POST['id'] : 0;
		die( MP_Mail::delete( $id ) ? '1' : '0' );
	}

	public static function mp_action_send_mail() {
		$id 		= isset($_POST['id']) 	? (int) $_POST['id'] : 0;
		$url_parms 	= MP_Admin::get_url_parms();
		$x 		= MP_Mail::send_draft($id,true);
		if (is_array($x))
		{
			ob_end_clean();
			ob_start();
				MP_Mail::get_row( $x[0], $url_parms );
				$item = ob_get_contents();
			ob_end_clean();
			header('Content-Type: text/xml');
			echo "<?xml version='1.0' standalone='yes'?><mp_action>";
			echo "<rc><![CDATA[2]]></rc>";
			echo "<id><![CDATA[$id]]></id>";
			echo "<item><![CDATA[$item]]></item>";
			echo '</mp_action>';
			die(0);
		}
		elseif (is_numeric($x))
		{
			if (0 == $x)
			{
				die(-1);
			}
			else
			{
				ob_end_clean();
				ob_start();
					MP_Mail::get_row( $id, $url_parms );
					$item = ob_get_contents();
				ob_end_clean();
				header('Content-Type: text/xml');
				echo "<?xml version='1.0' standalone='yes'?><mp_action>";	
				echo "<rc><![CDATA[1]]></rc>";
				echo "<id><![CDATA[$id]]></id>";
				echo "<item><![CDATA[$item]]></item>";
				echo '</mp_action>';
				die(1);
			}
		}
		else
		{
			ob_end_clean();
			ob_start();
				MP_Mail::get_row( $id, $url_parms, __('no recipient','MailPress') );
				$item = ob_get_contents();
			ob_end_clean();
			header('Content-Type: text/xml');
			echo "<?xml version='1.0' standalone='yes'?><mp_action>";
			echo "<rc><![CDATA[0]]></rc>";
			echo "<id><![CDATA[$id]]></id>";
			echo "<item><![CDATA[$item]]></item>";
			echo '</mp_action>';
			die(0);
		}
	}

	public static function mp_action_add_mail() {
		$url_parms = MP_Admin::get_url_parms(array('mode','status','s'));

		$start = isset($_POST['apage']) ? intval($_POST['apage']) * 25 - 1: 24;

		list($mails, $total) = MP_Mail::get_list( $url_parms, $start, 1 );

		if ( !$mails ) die('1');

		$x = new WP_Ajax_Response();
		foreach ( (array) $mails as $mail ) {
			MP_Mail::get( $mail );
			ob_start();
				MP_Mail::get_row( $mail->id, $url_parms );
				$mail_list_item = ob_get_contents();
			ob_end_clean();
			$x->add( array(
				'what' 	=> 'mail',
				'id' 		=> $mail->id,
				'data' 	=> $mail_list_item
			) );
		}
		$x->send();
	}

	public static function mp_action_autosave()
	{
		global $current_user;

		$do_autosave = (bool) $_POST['autosave'];
		$do_lock = true;

		$data = '';
		$message['revision'] = sprintf( __('Revision saved at %s.','MailPress'), date( __('g:i:s a'), current_time( 'timestamp', true ) ) );
		$message['draft']    = sprintf( __('Draft saved at %s.','MailPress'), date( __('g:i:s a'), current_time( 'timestamp', true ) ) );

		$supplemental = array();

		$id = 0;
		$supplemental['tipe'] = '';

		if ( -1 == $_POST['revision'])
		{
			$id 	= $mail->id = (int) $_POST['id'];
			if ( $do_autosave ) 
			{
				$id = (0 == $_POST['id']) ? MP_Mail::get_id() : $_POST['id'];
				$mail->id = $id;
				MP_Mail::update_draft($id);
				$data = $message['draft'];
				$supplemental['tipe'] = 'mail';
			}
		}
		else
		{
			$id 	= $mail->id = (int) $_POST['id'];
			$mail = MP_Mail::get($id);

			if ( $last = MP_Mail::check_mail_lock( $mail->id ) ) 
			{
				$do_autosave 	= $do_lock = false;
				$last_user 		= get_userdata( $last );
				$last_user_name 	= $last_user ? $last_user->display_name : __( 'Someone' );	
				$data 		= new WP_Error( 'locked', sprintf( __( 'Autosave disabled: %s is currently editing this mail.' ) , wp_specialchars( $last_user_name )	) );
				$supplemental['disable_autosave'] = 'disable';
			}
			if ( $do_autosave ) 
			{
				$id = (0 == $_POST['revision']) ? MP_Mail::get_id() : $_POST['revision'];

				if (0 == $_POST['revision'])
				{
					$mailmetas 		= MP_Mail::get_meta( $mail->id ,'_MailPress_mail_revisions');
					$mailmetas[$current_user->ID] = $id;
					MP_Mail::update_meta($mail->id ,'_MailPress_mail_revisions',$mailmetas);
				}

				MP_Mail::update_draft($id,'');
				$data = $message['revision'];
				$supplemental['tipe'] = 'revision';
			}
			else
			{
				if (0 != $_POST['revision']) $id = $_POST['revision'];
			}
		}

		if ( $do_lock && $id ) MP_Mail::set_mail_lock( $mail->id );

		$x = new WP_Ajax_Response( array	(
								'what' => 'autosave',
								'id' => $id,
								'type' => $type,
								'data' => $id ? $data : '',
								'supplemental' => $supplemental
								)
						 );
		$x->send();
	}

	public static function mp_action_get_previewlink()
	{
		$mail_id = isset($_POST['id'])? intval($_POST['id']) : 0;
		$preview_url= clean_url(get_option('siteurl') . '/' . MP_PATH . "mp-includes/action.php?action=iview&id=$mail_id&KeepThis=true&TB_iframe=true");
		die($preview_url);
	}


////	mailpress_mails	////


	public static function manage_list_columns() {
		$disabled = (!current_user_can('MailPress_delete_mails') && !current_user_can('MailPress_send_mails')) ? " disabled='disabled'" : '';
		$mails_columns = array(	'cb' 		=> "<input type='checkbox'$disabled />",
						'title' 	=> __('Subject','MailPress'),
						'author' 	=> __('Author'),
						'to' 		=> __('To','MailPress'),
						'theme' 	=> __('Theme','MailPress'),
						'date'	=> __('Date') );
		$mails_columns = apply_filters('MailPress_manage_mails_columns', $mails_columns);
		return $mails_columns;
	}

	public static function get_list( $url_parms, $start, $num ) {
		global $wpdb;
		global $user_ID;

		$start = abs( (int) $start );
		$num = (int) $num;

		$where = " status <> '' ";
		if (isset($url_parms['s']) && !empty($url_parms['s']))
		{
			$s = $wpdb->escape($url_parms['s']);
			if (!empty($where)) $where = $where . ' AND ';
			if ($s) $where .= " ((theme LIKE '%$s%') OR (themedir LIKE '%$s%') OR (template LIKE '%$s%') OR (toemail LIKE '%$s%') OR (subject LIKE '%$s%') OR (html LIKE '%$s%') OR (plaintext LIKE '%$s%') OR (created like '%$s%') OR (sent like '%$s%')) "; 
		}
		if (isset($url_parms['status']) && !empty($url_parms['status']))
		{
			if (!empty($where)) $where = $where . ' AND ';
			$where .= "status = '" . $url_parms['status'] . "'";
		}
		if (isset($url_parms['author']) && !empty($url_parms['author']))
		{
			if (!empty($where)) $where = $where . ' AND ';
			$where .= "( created_user_id = " . $url_parms['author'] . "  OR sent_user_id = " . $url_parms['author'] . " ) ";
		}
		if (!current_user_can('MailPress_edit_others_mails'))
		{
			if (!empty($where)) $where = $where . ' AND ';
			$where .= "( created_user_id = " . $user_ID . " ) ";
		}
		if ($where) $where = ' WHERE ' . $where;

		$mails = $wpdb->get_results( "SELECT SQL_CALC_FOUND_ROWS * FROM $wpdb->mp_mails  $where ORDER BY created DESC LIMIT $start, $num" );

		MP_Admin::update_cache($mails,'mp_mail');

		$total = $wpdb->get_var( "SELECT FOUND_ROWS()" );

		return array($mails, $total);
	}

	public static function get_row( $id, $url_parms, $xtra=false) {

		global $mp_mail;
		global $mp_screen;

		$disabled = (!current_user_can('MailPress_delete_mails') && !current_user_can('MailPress_send_mails')) ? " disabled='disabled'" : '';

		$mp_mail = $mail = MP_Mail::get( $id );
		$the_mail_status = $mail->status;
// url's
		$view_url		= clean_url(get_option('siteurl') . '/' . MP_PATH . "mp-includes/action.php?action=iview&id=$id&KeepThis=true&TB_iframe=true&width=600&height=400");
		$edit_url    	= clean_url(MP_Admin::url( MailPress_edit . "&id=$id",			false,	$url_parms ));

		$actions = array();

		if ('draft' == $mail->status) 
		{ 
			$actions['edit']    = "<a href='$edit_url'   title='" . sprintf( __('Edit "%1$s"','MailPress') , ( '' == $mail->subject) ? __('(no subject)','MailPress') : htmlspecialchars($mail->subject, ENT_QUOTES) ) . "'>"				. __('Edit') . '</a>';
			if (current_user_can('MailPress_send_mails'))
			{
				$send_url 	= clean_url(MP_Admin::url( MailPress_mail	."&action=send&id=$id",		"send-mail_$id",		$url_parms ));
				$actions['send']    = "<a href='$send_url' 	class='dim:the-mail-list:mail-$id:unapproved:e7e7d3:e7e7d3:?mode=" . $url_parms['mode'] . "' title='" . __('Send this mail','MailPress' ) . "'>" 					. __( 'Send','MailPress' ) . '</a>';
			}
			if (current_user_can('MailPress_delete_mails'))
			{
				$delete_url = clean_url(MP_Admin::url( MailPress_mail	."&action=delete&id=$id",	"delete-mail_$id" ,	$url_parms ));
				$actions['delete']  = "<a href='$delete_url' 	class='delete:the-mail-list:mail-$id submitdelete' title='" . __('Delete this mail','MailPress' ) . "'>" 												. __('Delete','MailPress') . '</a>';
			}
			$preview_url = $view_url;
			$actions['view'] = "<a href='$preview_url' class='thickbox'  title='" . __('View','MailPress' ) . "'>"																					. __('View','MailPress') . '</a>';
		}
		elseif (current_user_can('MailPress_delete_mails'))
		{ 
			$delete_url = clean_url(MP_Admin::url( MailPress_mail	."&action=delete&id=$id",	"delete-mail_$id" ,	$url_parms ));
			$actions['delete'] = "<a href='$delete_url' 	class='delete:the-mail-list:mail-$id submitdelete' title='" . __('Delete this mail','MailPress' ) . "'>" 			. __('Delete','MailPress') . '</a>';
			$actions['view'] = "<a href='$view_url' class='thickbox'  title='" . sprintf( __('View "%1$s"','MailPress') , ( '' == $mail->subject) ? __('(no subject)','MailPress') : htmlspecialchars($mail->subject, ENT_QUOTES) ) . "'>"	. __('View','MailPress') . '</a>';
		}
		else
		{
			$actions['view'] = "<a href='$view_url' class='thickbox'  title='" . sprintf( __('View "%1$s"','MailPress') , ( '' == $mail->subject) ? __('(no subject)','MailPress') : htmlspecialchars($mail->subject, ENT_QUOTES) ) . "'>"	. __('View','MailPress') . '</a>';

		}
// data
		$class = '';
		if ('draft' == $the_mail_status) $class = 'draft';
		if ('unsent' == $the_mail_status) $class = 'unsent';
//to
		$draft_dest = MP_User::get_mailing_lists();

		$email = false;
		if 	($xtra)										$email_display = "<blink style='color:red;font-weight:bold;'>" . $xtra . '</blink>';
		elseif (is_array(unserialize($mail->toemail)))					$email_display = "<div class='num post-com-count-wrapper'><a class='post-com-count'><span class='comment-count'>" . count(unserialize($mail->toemail)) . "</span></a></div>"; 
		elseif (MailPress::is_email($mail->toemail))
		{
			$email = true;
			$email_display = $mail->toemail;
			if ( strlen($email_display) > 40 )						$email_display = substr($email_display, 0, 39) . '...';
			$mail_url = MailPress_write . '&amp;toemail=' . $mail->toemail;

			$mail_url = MP_Admin::url(MailPress_mails, false, $url_parms);
			$mail_url = remove_query_arg('s',$mail_url);
			$mail_url = clean_url( $mail_url . '&s=' . $mail->toemail );
			
		}
		elseif (isset($draft_dest[$mail->toemail]))	$email_display = "<strong>" . $draft_dest[$mail->toemail] . "</strong>";
		else
		{
			$email_display = "<span style='color:red;font-weight:bold;'>" . __('(unknown)','MailPress') . '</span>';
			unset($actions['send']);
		}
//by
		$author = ( 0 == $mail->sent_user_id) ? $mail->created_user_id : $mail->sent_user_id;
		if ($author != 0 && is_numeric($author)) {
			unset($url_parms['author']);
			$wp_user 		= get_userdata($author);
			$author_url 	= clean_url(MP_Admin::url( MailPress_mails . "&author=" . $author, false, $url_parms ));
		}

		$subject_display = htmlspecialchars($mail->subject,ENT_QUOTES); ;
		if ( strlen($subject_display) > 40 )	$subject_display = substr($subject_display, 0, 39) . '...';
?>
	<tr id="mail-<?php echo $id; ?>" class='<?php echo $class; ?>'>
<?php

		$mails_columns = MP_Mail::manage_list_columns();
		$hidden = (array) get_user_option( "manage" . $mp_screen . "columnshidden" );

		foreach ( $mails_columns as $column_name=>$column_display_name ) 
		{
			$class = "class=\"$column_name column-$column_name\"";

			$style = '';
			if ('unsent' == $mail->status) 		$style .= 'font-style:italic;';
			if ( in_array($column_name, $hidden) ) 	$style .= 'display:none;';
			$style = ' style="' . $style . '"';

			$attributes = "$class$style";

			switch ($column_name) 
			{

				case 'cb':
					if ('unsent' == $mail->status) {
?>
		<th class='check-column' scope='row'>
		</th>
<?php 				}
					else 
					{ 
?>
		<th class='check-column' scope='row'>
			<input type='checkbox' name='delete_mails[]' value='<?php echo $id; ?>'<?php echo $disabled; ?>/>
		</th>
<?php
	 				} 
				break;
				case 'title':
					$attributes = 'class="post-title column-title"' . $style;
?>
		<td  <?php echo $attributes ?>>
			<strong>
				<a class='row-title<?php echo ('draft' == $mail->status) ? '' : ' thickbox'; ?>' href='<?php echo ('draft' == $mail->status) ? $edit_url : $view_url; ?>' title='<?php printf( ('draft' == $mail->status) ?  __('Edit "%1$s"','MailPress') : __('View "%1$s"','MailPress') , ( '' == $mail->subject) ? __('(no subject)','MailPress') : htmlspecialchars($mail->subject, ENT_QUOTES) ); ?>'>
					<?php echo ( '' == $subject_display) ? __('(no subject)','MailPress') : $subject_display; ?>
				</a>
<?php if ('draft' == $mail->status) echo ' - ' . __('Draft'); ?>
			</strong>
<?php
					if ('unsent' != $mail->status)
					{
						$action_count = count($actions);
						$i = 0;
						echo "			<div class='row-actions'>";
						foreach ( $actions as $action => $link ) {
							++$i;
							( $i == $action_count ) ? $sep = '' : $sep = ' | ';
							echo "				<span class='$action'>$link</span>$sep\n";
						}
						echo "			</div>";
					}
?>
		</td>
<?php
				break;
				case 'date':

					$t_time = MP_Mail::get_mail_date(__('Y/m/d g:i:s A'));
					$m_time = MP_Mail::get_mail_date_raw();
					$time   = MP_Mail::get_mail_date('U');

					$time_diff = time() - $time; 

					if ( $time_diff > 0 && $time_diff < 24*60*60 )	$h_time = sprintf( __('%s ago'), human_time_diff( $time ) );
					elseif ( $time_diff == 0 )				$h_time = __('now','MailPress');
					else								$h_time = mysql2date(__('Y/m/d'), $m_time);
					
					$attributes = 'class="date column-date"' . $style;
?>
		<td  <?php echo $attributes ?>>
			<abbr title="<?php echo $t_time; ?>"><?php echo $h_time; ?></abbr>
		</td>
<?php
				break;
				case 'author':
?>
		<td  <?php echo $attributes ?>>
<?php
					if ($author != 0 && is_numeric($author)) { 
?>
			<a href='<?php echo $author_url; ?>' title='<?php printf( __('Mails by "%1$s"','MailPress'), $wp_user->display_name); ?>'><?php echo $wp_user->display_name; ?></a>
<?php 				} else { 	
			_e("(unknown)",'MailPress');
					}
?>
		</td>
<?php
				break;
				case 'to':
?>
		<td  <?php echo $attributes ?>>
<?php 				if ($email) { ?>
			<strong>
				<a class='row-title' href='<?php echo $mail_url; ?>'  title='<?php printf( __('Search "%1$s"','MailPress'), $mail->toemail); ?>'>
					<?php if ( ('detail' == $url_parms['mode']) && (get_option('show_avatars') ) ) echo get_avatar( $mail->toemail, 32 );?>
					<?php echo $email_display; ?>
				</a>
			</strong>
<?php 				} else { ?>
			<?php echo $email_display; ?>
<?php 				} ?>
		</td>
<?php
				break;
				case 'theme':
?>
		<td  <?php echo $attributes ?>>
			<?php echo $mail->theme; ?>
			<?php if ('' != $mail->template) echo "<br/>(" . $mail->template . ")"; ?>
		</td>
<?php
				break;
				default:
?>
		<td  <?php echo $attributes ?>>
			<?php	do_action('MailPress_manage_mails_custom_column', $column_name, $mail, $url_parms); ?>
		</td>
<?php
				break;
			}
		}
?>
	  </tr>
<?php
	}

	public static function mail_date( $d = '' ) {
		echo  MP_Mail::get_mail_date($d);
	}

	public static function get_mail_date($d = '' ) {
		$x = MP_Mail::get_mail_date_raw();
		return ( '' == $d ) ? mysql2date( get_option('date_format'), $x) : mysql2date($d, $x);
	}

	public static function get_mail_date_raw() {
		global $mp_mail;
		$x = ($mp_mail->sent >= $mp_mail->created) ? $mp_mail->sent : $mp_mail->created;
		return $x;
	}


/// mailpress_write && mailpress_edit ///


	public static function submit_meta_box($draft) 
	{
		if ($draft)
		{
			if ($draft->id)
			{
				if (current_user_can('MailPress_delete_mails')) $delete_url = clean_url(MailPress_mail  ."&amp;action=delete&amp;id=$draft->id");
				$preview_url= clean_url(get_option('siteurl') . '/' . MP_PATH . "mp-includes/action.php?action=iview&id=$draft->id&KeepThis=true&TB_iframe=true");
				$preview	= "<a class='preview button' target='_blank' href='$preview_url'>" . __('Preview') . "</a>";
			}
		}
?>
<div class="submitbox" id="submitpost">
	<div id="minor-publishing">
		<!--<div id="misc-publishing-actions"></div>-->
		<div id="minor-publishing-actions">
			<input type='submit' name='save' id='save-post' class='button button-highlighted' 	value="<?php _e('Save Draft','MailPress'); ?>"  />
			<span id='previewview27'><?php if ($preview) echo $preview; ?></span>
		</div>
		<div class="clear"><br/><br/><br/><br/><br/></div>
	</div>
	<div id="major-publishing-actions">
		<div id="delete-action">
<?php 	if ($delete_url) : ?>
			<a class='submitdelete' href='<?php echo $delete_url ?>' onclick="if (confirm('<?php echo(js_escape(sprintf( __("You are about to delete this draft '%s'\n  'Cancel' to stop, 'OK' to delete."), $draft->id ))); ?>')) return true; return false;">
				<?php _e('Delete','MailPress'); ?>
			</a>
<?php		endif; ?>
		</div>
		<div id="publishing-action">
<?php 	if (current_user_can('MailPress_send_mails')) : ?><input id='publish' type='submit' name='send' class='button-primary' value="<?php _e('Send','MailPress'); ?>" /><?php endif; ?>
		</div>
		<div class="clear"></div>
	</div>
</div>
<?php
	}

	public static function plaintext_meta_box($draft)
	{
?>
<textarea id='plaintext' name='plaintext' cols='40' rows='1'><?php echo htmlspecialchars(stripslashes($draft->plaintext), ENT_QUOTES); ?></textarea>
<?php
	}

	public static function revision_meta_box($draft)
	{
		mp_list_mail_revisions($draft->id);
	}


/// THICKBOX ///


	public static function mp_action_iview()
	{
		$theme = (isset($_GET['theme'])) ? '&theme=' . $_GET['theme'] : '';
		$id 		= $_GET['id'];
		$mail 	= MP_Mail::get($id);
		$mp_general = get_option('MailPress_general');
		$from 	= ('sent' == $mail->status) ? MP_Mail::display_toemail($mail->fromemail,$mail->fromname) :  MP_Mail::display_toemail($mp_general['fromemail'],$mp_general['fromname']);
		$to 		= MP_Mail::display_toemail($mail->toemail,$mail->toname);
		$subject 	= ('sent' == $mail->status) ? $mail->subject : MP_Mail::do_eval(stripslashes($mail->subject));
		$html_url 	= clean_url(get_option('siteurl') . '/' . MP_PATH . "mp-includes/action.php?action=viewadmin&id=$id&type=html$theme");
		$html 	= "<iframe id='ihtml' style='width:100%;border:0;height:550px' src='" . $html_url . "'></iframe>";
		$plaintext_url = clean_url(get_option('siteurl') . '/' . MP_PATH . "mp-includes/action.php?action=viewadmin&id=$id&type=plaintext");
		$plaintext 	= "<iframe id='iplaintext' style='width:100%;border:0;height:550px' src='" . $plaintext_url . "'></iframe>";

		include MP_TMP . '/mp-includes/viewthickbox.html.php';
	}


////	IFRAMES	////


	public static function mp_action_viewuser() {
		$id 		= $_GET['id'];
		$key		= $_GET['key'];
		$email 	= MP_User::get_email(MP_User::get_id($key));
		$mail 	= MP_Mail::get($id);

		$x = new MP_Mail();

		if (MailPress::is_email($mail->toemail))
		{
			echo $x->process_img($mail->html,$mail->themedir,'draft');
		}
		else
		{
			$recipients = unserialize($mail->toemail);
			$recipient = $recipients[$email];
			echo $x->process_img($x->mail_decorator($mail->html,$recipient,'',''),$mail->themedir,'draft');
		}
	}

	public static function mp_action_viewadmin() {
		$id 	= $_GET['id'];
		$type	= (isset($_GET['type'])) ? $_GET['type'] : 'html';
		$theme= (isset($_GET['theme'])) ? $_GET['theme'] : false;

		$x = new MP_Mail();
		if ('html' == $type) 		$x->viewhtml($id,$theme);
		elseif ('plaintext' == $type) $x->viewplaintext($id);
	}

	function viewhtml($id,$theme=false)
	{
		$x = MP_Mail::get($id);
		$y = array('sent','unsent');
		if (!in_array($x->status, $y))
		{
			if ($theme) $this->args->Theme = $theme;
			$this->args->html = stripslashes(apply_filters('the_content', $x->html));
			$x->html 		= $this->build_mail_content('html');
			$x->themedir 	= $this->mail->themedir;
		}
		echo $this->process_img($x->html,$x->themedir,'draft');
	}

	function viewplaintext($id)
	{
		$x = MP_Mail::get($id);
		$y = array('sent','unsent');
		if (!in_array($x->status, $y))
		{
			$this->args->plaintext 	= stripslashes($x->plaintext);
			$x->plaintext 		= strip_tags($this->build_mail_content('plaintext'));
		}
		include MP_TMP . '/mp-includes/viewplaintext.html.php';
	}
}
?>