<?php
$mp_general['tab'] = 'batches';
$message = false;

if (class_exists('MailPress_batch_send'))
{
	$batch_send	= $_POST['batch_send'];

	$old_batch_send = get_option(MailPress_batch_send::option_name);

	update_option(MailPress_batch_send::option_name, $batch_send);

	if (!isset($old_batch_send['batch_mode'])) $old_batch_send['batch_mode'] = '';
	if ($old_batch_send['batch_mode'] != $batch_send['batch_mode'])
	{
		if ('wpcron' != $batch_send['batch_mode']) wp_clear_scheduled_hook('mp_process_batch_send');
		else							 MailPress_batch_send::schedule();
	}
}

if (class_exists('MailPress_batch_spool_send'))
{
	$batch_spool_send = $_POST['batch_spool_send'];
     $batch_spool_send['path'] = trim(stripslashes($batch_spool_send['path']));

	$old_batch_spool_send = get_option(MailPress_batch_spool_send::option_name);

	switch (true)
	{
		case ( !empty($batch_spool_send['path']) && !MailPress_batch_spool_send::is_path($batch_spool_send['path']) ) :
			$spoolpath = true;
			$message = __('path is invalid', MP_TXTDOM); $no_error = false;
		break;
		default :
			update_option(MailPress_batch_spool_send::option_name, $batch_spool_send);
	
			if (empty($batch_spool_send['path']) && !is_dir(MP_ABSPATH . 'tmp/spool')) mkdir(MP_ABSPATH . 'tmp/spool');

			if (!isset($old_batch_spool_send['batch_mode'])) $old_batch_spool_send['batch_mode'] = '';
			if ($old_batch_spool_send['batch_mode'] != $batch_spool_send['batch_mode'])
			{
				if ('wpcron' != $batch_spool_send['batch_mode']) wp_clear_scheduled_hook('mp_process_batch_spool_send');
				else							 		 MailPress_batch_spool_send::schedule();
			}
		break;
	}

}

if (class_exists('MailPress_bounce_handling'))
{
	$bounce_handling	= $_POST['bounce_handling'];

	$old_bounce_handling = get_option(MailPress_bounce_handling::option_name);

	update_option(MailPress_bounce_handling::option_name, $bounce_handling);

	if (!isset($old_bounce_handling['batch_mode'])) $old_bounce_handling['batch_mode'] = '';
	if ($old_bounce_handling['batch_mode'] != $bounce_handling['batch_mode'])
	{
		if ('wpcron' != $bounce_handling['batch_mode']) wp_clear_scheduled_hook('mp_process_bounce_handling');
		else 								MailPress_bounce_handling::schedule();
	}
}

if (class_exists('MailPress_bounce_handling_II'))
{
	$bounce_handling_II	= $_POST['bounce_handling_II'];

	$old_bounce_handling = get_option(MailPress_bounce_handling_II::option_name);

	update_option(MailPress_bounce_handling_II::option_name, $bounce_handling_II);

	if (!isset($old_bounce_handling['batch_mode'])) $old_bounce_handling['batch_mode'] = '';
	if ($old_bounce_handling['batch_mode'] != $bounce_handling_II['batch_mode'])
	{
		if ('wpcron' != $bounce_handling_II['batch_mode']) wp_clear_scheduled_hook('mp_process_bounce_handling_II');
		else 								MailPress_bounce_handling_II::schedule();
	}
}

if (class_exists('MailPress_delete_old_mails'))
{
	$batch_delete_old_mails	= $_POST['batch_delete_old_mails'];

	$old_delete_old_mails = get_option(MailPress_delete_old_mails::option_name);

	update_option(MailPress_delete_old_mails::option_name, $batch_delete_old_mails);

	if (!isset($old_delete_old_mails['batch_mode'])) $old_delete_old_mails['batch_mode'] = '';
	if ($old_delete_old_mails['batch_mode'] != $batch_delete_old_mails['batch_mode'])
	{
		if ('wpcron' != $batch_delete_old_mails['batch_mode']) 	wp_clear_scheduled_hook('mp_process_delete_old_mails');
		else 										MailPress_delete_old_mails::schedule();
	}
}

update_option(MailPress::option_name_general, $mp_general);

if (!$message) $message = __("'Batches' settings saved", MP_TXTDOM);