<?php
if (class_exists('MailPress') && !class_exists('MailPress_wp_fromemail'))
{
/*
Plugin Name: MailPress_wp_fromemail
Plugin URI: http://www.mailpress.org/wiki/index.php
Description: This is just an add-on for MailPress to force from email & name on New Mail by wp values
Version: 5.2.1
*/

class MailPress_wp_fromemail
{
	function __construct()
	{
		add_filter('MailPress_write_fromemail', 		array(__CLASS__, 'fromemail'), 1, 1);
		add_filter('MailPress_write_fromname', 		array(__CLASS__, 'fromname'), 1, 1);
	}

////  Admin  ////

	public static function fromemail($fromemail)
	{
		$user = wp_get_current_user();
		if ($user) return $user->user_email;
		return $fromemail;
	}

	public static function fromname($fromname)
	{
		$user = wp_get_current_user();
		if ($user) return $user->user_nicename;
		return $fromname;
	}
}
new MailPress_wp_fromemail();
}