<?php
/*
u006
*/

	function meta_box_tracking_mp_u006($mp_user)
	{
?>
<script type='text/javascript'>
/* <![CDATA[ */
<?php
		global $mp_general;
		$Gkey = $mp_general['gmapkey'];
		$skip = array('hostname', 'countrycode', 'countryflag', 'areacode', 'dmacode', 'queries');

		global $wpdb;
		$m = array();

		$query = "SELECT DISTINCT ip FROM $wpdb->mp_tracks WHERE user_id = " . $mp_user->id . " LIMIT 10;";
		$tracks = $wpdb->get_results($query);

		if ($tracks)
		{
			MP_AdminPage::require_class('Ip');
			foreach($tracks as $track)
			{
				$x = MP_Ip::get_latlng($track->ip);
				if ($x)
				{
					$x['ip'] = $track->ip;
					$m['u006'][] = $x;
				}
			}
		} 
	
		$eol = "";
		foreach ( $m as $var => $val ) {
			echo "var $var = " . MP_AdminPage::print_scripts_l10n_val($val);
			$eol = ",\n\t\t";
		}
		echo ";\n";
?>
/* ]]> */
</script>
		<div id='ip_info_div' style='height:300px;width:auto;'></div>
<?php
	}
?>