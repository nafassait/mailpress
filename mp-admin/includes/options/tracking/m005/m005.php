<?php
/*
m005
*/
	function meta_box_tracking_mp_m005($mail)
	{
		global $wpdb;

     		$query = "SELECT context, count(*) as count FROM $wpdb->mp_tracks WHERE mail_id = " . $mail->id . " GROUP BY context ORDER BY context;";
		$tracks = $wpdb->get_results($query);
		$total = 0;
		if ($tracks)
		{
			foreach($tracks as $track)
			{
				$context[$track->context] = $track->count;
				$total += $track->count;
			}
			foreach($context as $k => $v)
			{
				echo '<b>' . $k . '</b> : &nbsp;' . sprintf("%01.2f %%",100 * $v/$total ) . '&nbsp;&nbsp;&nbsp;&nbsp;';
			}
			echo '<br />';
		}
		echo '<br />';
		$query = "SELECT agent, count(*) as count FROM $wpdb->mp_tracks WHERE mail_id = " . $mail->id . "  GROUP BY agent ORDER BY agent LIMIT 10;";
		$tracks = $wpdb->get_results($query);

		if ($tracks)
		{
			$total = 0;
			foreach($tracks as $track)
			{
				$agent[$track->agent] = $track->count;
				$total += $track->count;
			}
			foreach($agent as $k => $v)
			{
				echo MailPress_tracking::get_os($k) . ' ' . MailPress_tracking::get_browser($k) . ' : &nbsp;' . sprintf("%01.2f %%",100 * $v/$total ) . '<br />';
			}
		}
	}
?>