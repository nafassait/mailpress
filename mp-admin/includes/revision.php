<?php
self::require_class('Mails');

$autosave_data = MP_Mails::autosave_data();

$x = array('revision', 'id', 'left', 'right', 'action');
foreach($x as $xx) global $$xx;
wp_reset_vars($x);

$revision_id= (isset($revision)) 	? absint($revision) : false;
$id		= absint($id);
$diff		= (isset($diff)) 		? absint($diff) : false;
$left		= (isset($left)) 		? absint($left) : false;
$right	= (isset($right)) 	? absint($right) : false;

switch ( $action )
{
	case 'diff' :
		$left_revision  	= MP_Mails::get( $left );
		$right_revision 	= MP_Mails::get( $right);
		$mail    		= MP_Mails::get($id);

		$edit_url  = MailPress_edit . '&id=' . $id;
		$edit_url  = clean_url($edit_url);
		$mail_title = '<a href="' . $edit_url . '">' . $mail->subject . '</a>';
		$h2 = sprintf( __( 'Compare Revisions of &#8220;%1$s&#8221;', 'MailPress' ), $mail_title );

		$left  = $left_revision->id;
		$left_link = ('' == $left_revision->status) ? MailPress_revision . '&id=' . $mail->id . '&revision=' . $left : MailPress_edit . '&id=' . $left;

		$right = $right_revision->id;
		$right_link = ('' == $right_revision->status) ? MailPress_revision . '&id=' . $mail->id . '&revision=' . $right : MailPress_edit . '&id=' . $right;

	break;
	default :
		$revision 	= MP_Mails::get( $revision_id );
		$mail 	= MP_Mails::get( $id );

		$edit_url  = clean_url(MailPress_edit . '&id=' . $id);
		$mail_title = '<a href="' . $edit_url . '">' . $mail->subject . '</a>';

		$revision_title = MP_Mails::mail_revision_title( $revision_id , false );
		$h2 = sprintf( __( 'Mail Revision for &#8220;%1$s&#8221; created on %2$s', 'MailPress' ), $mail_title, $revision_title );

		$left  = $revision->id;
		$right = $mail->id;
	break;
}
?>
<div class="wrap">
	<div id="icon-mailpress-mailnew" class="icon32"><br /></div>
	<h2 class="long-header">
		<?php echo $h2; ?>
	</h2>
	<table class="form-table ie-fixed">
		<col class="th" />
<?php if ( 'diff' == $action ) : ?>
		<tr id="revision">
			<th scope="row"></th>
			<th scope="col" class="th-full">
				<span class="alignleft"><?php  printf( __('Older: %s', 'MailPress'), MP_Mails::mail_revision_title( $left_revision, $left_link ) ); ?></span>
				<span class="alignright"><?php printf( __('Newer: %s', 'MailPress'), MP_Mails::mail_revision_title( $right_revision, $right_link ) ); ?></span>
			</th>
		</tr>
<?php endif;
$identical = true;
foreach ( $autosave_data as $field => $field_title ) :

	if ( 'diff' == $action ) 
	{
		add_filter('_mp_mail_revision_field_toemail', array('MP_Mails', 'display_toemail'), 8, 2);

		$left_content  = apply_filters("_mp_mail_revision_field_$field", $left_revision->$field, $field );
		$right_content = apply_filters("_mp_mail_revision_field_$field", $right_revision->$field, $field );
		if ( !$content = wp_text_diff( $left_content, $right_content ) )
			continue; // There is no difference between left and right
		$identical = false;
	} 
	else 
	{
		add_filter("_mp_mail_revision_field_$field", 'htmlspecialchars' );
		$content = ('toemail' == $field) ? MP_Mails::display_toemail($revision->$field, $revision->toname) : apply_filters("_mp_mail_revision_field_$field", $revision->$field, $field, $revision->toname );
	}
	?>
		<tr id="revision-field-<?php echo $field; ?>">
			<th scope="row"><?php echo wp_specialchars( $field_title ); ?></th>
			<td><div class="pre"><?php echo $content; ?></div></td>
		</tr>
<?php
endforeach;
if ( 'diff' == $action && $identical ) :
?>
		<tr><td colspan="2"><div class="updated"><p><?php _e( 'These revisions are identical.' , 'MailPress'); ?></p></div></td></tr>
<?php endif; ?>
	</table>
	<br class="clear" />
	<h2><?php //echo $title; ?></h2>
<?php
$args = array( 'format' => 'form-table', 'parent' => true, 'right' => $right, 'left' => $left, 'type' => 'autosave' );
MP_Mails::list_mail_revisions( $mail, $args );
?>
</div>