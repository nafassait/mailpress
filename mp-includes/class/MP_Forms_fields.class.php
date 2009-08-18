<?php
class MP_Forms_fields
{
	public static function get($field, $output = OBJECT) 
	{
		global $wpdb;

		switch (true)
		{
			case ( empty($field) ) :
				if ( isset($GLOBALS['mp_field']) ) 	$_field = & $GLOBALS['mp_field'];
				else						$_field = null;
			break;
			case ( is_object($field) ) :
				wp_cache_add($field->id, $field, 'mp_field');
				$_field = $field;
			break;
			default :
				if ( isset($GLOBALS['mp_field']) && ($GLOBALS['mp_field']->id == $field) ) 
				{
					$_field = & $GLOBALS['mp_field'];
				} 
				elseif ( ! $_field = wp_cache_get($field, 'mp_field') ) 
				{
					$_field = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->mp_fields WHERE id = %d LIMIT 1", $field));
					$_field->settings = unserialize(stripslashes($_field->settings));
					if ($_field) wp_cache_add($_field->id, $_field, 'mp_field');
				}
			break;
		}
		if ( $output == OBJECT ) {
			return $_field;
		} elseif ( $output == ARRAY_A ) {
			return get_object_vars($_field);
		} elseif ( $output == ARRAY_N ) {
			return array_values(get_object_vars($_field));
		} else {
			return $_field;
		}
	}

	public static function get_all($form_id, $all = false) 
	{
		global $wpdb;

		$columns = ($all) ? '*' : 'a.id, a.ordre';
		$query = "SELECT DISTINCT $columns FROM $wpdb->mp_fields a WHERE a.form_id = '$form_id' ORDER BY a.ordre";

		return $wpdb->get_results($query);
	}

	// file uploading ?
	public static function have_file($form_id) 
	{
		global $wpdb;

		$query = "SELECT DISTINCT type FROM $wpdb->mp_fields a WHERE a.form_id = '$form_id' ;";
		$fields = $wpdb->get_results($query);

		if (!$fields) return false;

		MailPress::require_class('Forms_field_types');
		$have_file = false;
		foreach ($fields as $field) $have_file = MP_Forms_field_types::have_file($have_file, $field->type);
		return $have_file;
	}

	public static function exists($label, $form_id) 
	{
		global $wpdb;
		return $wpdb->get_var($wpdb->prepare("SELECT id FROM $wpdb->mp_fields WHERE label = %s AND form_id = %d LIMIT 1", $label, $form_id));
	}

	public static function insert($_post_field) 
	{
		$_post_defaults = array('id' => 0, 'form_id' => 0);
		$_post_field = wp_parse_args($_post_field, $_post_defaults);
		extract($_post_field, EXTR_SKIP);

		if ( trim( $label ) == '' )
		{
			if ( ! $wp_error )	return 0;
			else				return new WP_Error( 'label', __('You did not enter a valid label.', 'MailPress') );
		}

		$type_prefixed 	= MailPress_form::prefix . $_type;
		$settingss	 	= $$type_prefixed;
		$settings		= $settingss['settings'];

		foreach($settings['attributes'] as $k => $v) { $v = trim($v); continue; if (empty($v)) unset($settings['attributes'][$k]); }
		if (isset($settingss['textareas'])) foreach ($settingss['textareas'] as $t) if (isset($settings['attributes'][$t])) $settings['attributes'][$t] = base64_encode($settings['attributes'][$t]);

		$data = $format 		= array();

		$data['form_id'] 		= $form_id;								$format[] = '%d';
		$data['ordre'] 		= (int) $ordre;							$format[] = '%d';
		$data['type'] 		= $_type;								$format[] = '%s';

		$data['template']		= $template;							$format[] = '%s';
		$data['label'] 		= $label;								$format[] = '%s';
		$data['description'] 	= $description;							$format[] = '%s';
		$data['settings'] 	= mysql_real_escape_string(serialize($settings));	$format[] = '%s';

		// Are we updating or creating?
		global $wpdb;
		$update = (!empty ($id) ) ? true : false;
		if ( $update )
		{
			$where['id'] 	= (int) $id;
			$wpdb->update( $wpdb->mp_fields, $data, $where );
			return $id;
		}
		else
		{
			$wpdb->insert( $wpdb->mp_fields, $data, $format );
			return $wpdb->insert_id;
		}
	}

	public static function duplicate($id, $form_id = false)
	{
		$field = self::get($id);
		if (!$field) return false;

		if (!$form_id)
		{
			do 
			{
				$sep = '_x';
				$label = explode($sep, $field->label);
				$num = array_pop($label);
				$label[] = (is_numeric($num)) ? $num + 1 : $num . $sep . '2';
				$field->label = implode($sep, $label);
			} while (self::exists($field->label, $field->form_id));
		}

		$data = $format 		= array();

		$data['form_id'] 		= ($form_id) ? $form_id : $field->form_id;		$format[] = '%d';
		$data['ordre'] 		= $field->ordre;							$format[] = '%d';
		$data['type'] 		= $field->type;							$format[] = '%s';

		$data['template']		= mysql_real_escape_string(stripslashes($field->template));		$format[] = '%s';
		$data['label'] 		= mysql_real_escape_string(stripslashes($field->label));		$format[] = '%s';
		$data['description'] 	= mysql_real_escape_string(stripslashes($field->description));	$format[] = '%s';
		$data['settings'] 	= mysql_real_escape_string(serialize($field->settings));		$format[] = '%s';	
		global $wpdb;
		$wpdb->insert( $wpdb->mp_fields, $data, $format );
		return $wpdb->insert_id;
	}

	public static function delete($id)
	{
		global $wpdb;
		$query = "DELETE FROM $wpdb->mp_fields WHERE id = $id;";
		$wpdb->query($query);

		return true;
	}

	public static function delete_all($form_id)
	{
		global $wpdb;
		$query = "DELETE FROM $wpdb->mp_fields WHERE form_id = $form_id;";
		$wpdb->query($query);

		return true;
	}

	public static function check_visitor($form_id, $has_visitor, $tbc_visitor)
	{
		$fields = self::get_all($form_id);
		if (empty($fields))
		{
			if ($has_visitor) self::insert_visitor($form_id);
			if ($tbc_visitor) self::insert_visitor_mail($form_id);
			return;
		}
		$have_email = $have_name = $have_tbc = array();
		foreach ($fields as $field)
		{
			$field = self::get($field->id);
			if ( isset($field->settings['options']['visitor_email']) ) $have_email[] = $field->id;
			if ( isset($field->settings['options']['visitor_name'])  ) $have_name[]  = $field->id;
			if ( isset($field->settings['options']['visitor_mail'])  ) $have_tbc[]   = $field->id;
		}

		if ($has_visitor)
		{
			if ( empty($have_email) ) self::insert_visitor_email($form_id);
			if ( empty($have_name)  ) self::insert_visitor_name($form_id);
		}
		else
		{
			foreach(array_merge($have_email, $have_name)  as $field_id) self::delete($field_id);
		}

		if ($tbc_visitor)
		{
			if ( empty($have_tbc) )	self::insert_visitor_mail($form_id);
		}
		else 
		{
			foreach($have_tbc as $field_id) self::delete($field_id); 
		}
	}

	public static function insert_visitor($form_id) 
	{
		self::insert_visitor_email($form_id);
		self::insert_visitor_name($form_id);
	}

	public static function insert_visitor_email($form_id) 
	{
		$data = $format 		= array();
		$settings = array('attributes' => array('type' => 'text', 'size' => 22), 'controls' => array('required' => 1), 'options' => array('visitor_email' => 1, 'protected' =>1, 'incopy' => 1));

		$data['form_id'] 		= $form_id;							$format[] = '%d';
		$data['ordre'] 		= 1;								$format[] = '%d';
		$data['type'] 		= 'email';							$format[] = '%s';

		$data['template']		= 'standard';									$format[] = '%s';
		$data['label'] 		= mysql_real_escape_string(__('Email', 'MailPress'));			$format[] = '%s';
		$data['description'] 	= mysql_real_escape_string(__('Visitor email', 'MailPress'));	$format[] = '%s';
		$data['settings'] 	= mysql_real_escape_string(serialize($settings));			$format[] = '%s';

		global $wpdb;
		$wpdb->insert( $wpdb->mp_fields, $data, $format );
		return $wpdb->insert_id;
	}

	public static function insert_visitor_name($form_id) 
	{
		$data = $format 		= array();
		$settings = array('attributes' => array('type' => 'text', 'size' => 22), 'controls' => array('required' => 1), 'options' => array('visitor_name' => 1, 'protected' =>1, 'incopy' => 1));

		$data['form_id'] 		= $form_id;							$format[] = '%d';
		$data['ordre'] 		= 1;								$format[] = '%d';
		$data['type'] 		= 'text';							$format[] = '%s';

		$data['template']		= 'standard';									$format[] = '%s';
		$data['label'] 		= mysql_real_escape_string(__('Name', 'MailPress'));			$format[] = '%s';
		$data['description'] 	= mysql_real_escape_string(__('Visitor name', 'MailPress'));	$format[] = '%s';
		$data['settings'] 	= mysql_real_escape_string(serialize($settings));			$format[] = '%s';

		global $wpdb;
		$wpdb->insert( $wpdb->mp_fields, $data, $format );
		return $wpdb->insert_id;
	}

	public static function insert_visitor_mail($form_id) 
	{
		$data = $format 		= array();
		$settings = array('attributes' => array('type' => 'checkbox', 'checked' => 'checked'), 'options' => array('visitor_mail' => 1, 'protected' =>1));

		$data['form_id'] 		= $form_id;								$format[] = '%d';
		$data['ordre'] 		= 1;									$format[] = '%d';
		$data['type'] 		= 'checkbox';							$format[] = '%s';

		$data['template']		= 'standard';										$format[] = '%s';
		$data['label'] 		= mysql_real_escape_string(__('Mail sent if checked', 'MailPress'));	$format[] = '%s';
		$data['description'] 	= mysql_real_escape_string(__('Check to get a copy', 'MailPress'));	$format[] = '%s';
		$data['settings'] 	= mysql_real_escape_string(serialize($settings));				$format[] = '%s';

		global $wpdb;
		$wpdb->insert( $wpdb->mp_fields, $data, $format );
		return $wpdb->insert_id;
	}
}
?>