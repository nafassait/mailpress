<?php
if (isset($_GET['mp_import']))
{
	self::require_class('Import_importers');
	$importers = MP_Import_importers::get_all();

	$importer = $_GET['mp_import'];

	// Allow plugins to define importers as well
	if (! is_callable($importers[$importer][2]))
	{
		if (! file_exists(MP_TMP . "mp-admin/includes/options/import/importers/$importer.php"))
		{
			wp_die(__('Cannot load importer.', MP_TXTDOM));
		}
		include(MP_TMP . "mp-admin/includes/options/import/importers/$importer.php");
	}

	define('MP_IMPORTING', true);

	self::require_class('Log');
	call_user_func($importers[$importer][2]);
}
else
{
	$importers = self::get_list(); 
?>
<div class="wrap nosubsub">
	<div id="icon-mailpress-tools" class="icon32"><br /></div>
	<h2><?php _e('Import'); ?></h2>
<?php
	if ($importers)
	{
?>
		<p><?php _e('If you have emails in another system, MailPress can import those into this blog. To get started, choose a system to import from below:', MP_TXTDOM); ?></p>
		<table class="widefat">
			<thead>
				<tr>
<?php 	self::columns_list(); ?>
				</tr>
			</thead>
			<tfoot>
				<tr>
<?php 	self::columns_list(false); ?>
				</tr>
			</tfoot>
			<tbody>
<?php 	foreach ($importers as $id => $data) echo self::get_row( $id, $data ); ?>
			</tbody>
		</table>
<?php
	} else {
?>
		<p><?php _e('No importers available.', MP_TXTDOM); ?></p>
<?php
	}
}
?>
</div>