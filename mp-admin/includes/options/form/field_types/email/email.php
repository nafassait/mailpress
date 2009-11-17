<?php
MailPress::require_class('Forms_field_type_abstract');

class MP_Forms_field_type_email extends MP_Forms_field_type_abstract
{
	var $field_type 	= 'email';
	var $order		= 15;

	function __construct()
	{
		$this->description = __('Email', MP_TXTDOM);
		$this->settings	 = dirname(__FILE__) . '/settings.xml';
		parent::__construct();
	}

	function submitted($field)
	{
		$value	= trim($_POST[$this->prefix][$field->form_id][$field->id]);

		$required 	= (isset($field->settings['controls']['required']) && $field->settings['controls']['required']);
		$empty 	= empty($value);
		$is_email 	= MailPress::is_email($value);
		if ($required)
		{
			if ($empty)
			{
				$field->submitted['on_error'] = 1;
				return $field;
			}
			if (!$is_email)
			{
				$field->submitted['on_error'] = 2;
				return $field;
			}
		}
		if (!$empty && !$is_email)
		{
			$field->submitted['on_error'] = 3;
			return $field;
		}
		return parent::submitted($field);
	}

	function attributes_filter($no_reset)
	{
		$visitor_email = ( isset($this->field->settings['options']['visitor_email']) && $this->field->settings['options']['visitor_email'] );
		if ( $visitor_email )
		{
			global $user_ID; switch (true) { case ($user_ID != 0 && is_numeric($user_ID) ) : $user  = get_userdata($user_ID); $email = $user->user_email; break; default : $email = (isset($_COOKIE['comment_author_email_' . COOKIEHASH])) ? $_COOKIE['comment_author_email_' . COOKIEHASH] : ''; break; }
			if ( !empty($email) ) $this->field->settings['attributes']['value'] = $email;
		}

		if (!$no_reset) return;

		parent::attributes_filter($no_reset);
		$this->attributes_filter_css();
	}
}
$MP_Forms_field_type_email = new MP_Forms_field_type_email();
?>