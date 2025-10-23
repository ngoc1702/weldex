<?php
/*
 * Name: Comment Reply Notification
 * Version: 1.4
 * Description: When a reply is made to a comment the user has left on the blog, an e-mail shall be sent to the user to notify him of the reply. This will * allow the users to follow up the comment and expand the conversation if desired.
 *
 * Change Log:
 *	- 23/07/21: chỉ hiển thị setting với quyền client-admin trở lên
*/

if(!class_exists('comment_reply_notification')):
class comment_reply_notification{
	var $status = '';
	var $message = '';
	var $options = array();
	var $options_keys = array('mail_notify', 'mail_subject', 'mail_message', 'clean_option', 'dn_hide_note');
	var $db_options = 'commentreplynotification';

	function comment_reply_notification(){
		$this->initoption();
		$this->inithook();
	}

	function defaultoption($key=''){
		if(empty($key))
			return false;

		if($key === 'mail_notify'){
			return 'everyone';
		}elseif($key === 'mail_subject'){
			return __('Bình luận của bạn tại website [[blogname]] đã được trả lời','comment-reply-notification');
		}elseif($key === 'mail_message'){
			return __('<p><strong>[blogname]</strong>: Bình luận của bạn tại bài viết <strong>[postname]</strong> đã được trả lời.</p>'."\n".'<p>Xem lại bình luận của bạn:<br />[pc_content]</p>'."\n".'<p>Đây là câu trả lời:<br />[cc_content]</p>'."\n".'<p>Bạn có thể xem đầy đủ thông tin tại đây:<br /><a href="[commentlink]">[commentlink]</a></p>'."\n".'<p><strong>Cảm ơn bạn đã quan tâm và gửi phản hồi tới chúng tôi. Chúc bạn sức khỏe và thành công!</p>'."\n".'<p><strong>Đây là email tự động, bạn vui lòng không trả lời email này</strong></p>','comment-reply-notification');
		}elseif($key === 'clean_option'){
			return 'no';
		}elseif($key === 'dn_hide_note'){
			return 'no';
		}else{
			return false;
		}
	}

	function resetToDefaultOptions(){
		$this->options = array();

		foreach($this->options_keys as $key){
			$this->options[$key] = $this->defaultoption($key);
		}
		update_option($this->db_options, $this->options);
	}

	function initoption(){
		$optionsFromTable = get_option($this->db_options);
		if (empty($optionsFromTable)){
			$this->resetToDefaultOptions();
		}

		$flag = FALSE;
		foreach($this->options_keys as $key) {
			if(isset($optionsFromTable[$key]) && !empty($optionsFromTable[$key])){
				$this->options[$key] = $optionsFromTable[$key];
			}else{
				$this->options[$key] = $this->defaultoption($key);
				$flag = TRUE;
			}
		}
		if($flag === TRUE){
			update_option($this->db_options, $this->options);
		}
		unset($optionsFromTable,$flag);
	}

	function inithook(){
		add_action('comment_post', array(&$this,'add_mail_reply'),9998);
		add_action('wp_set_comment_status', array(&$this,'status_change'),9999,2);
		add_action('comment_post', array(&$this,'email'),9999);
		add_action('comment_form', array(&$this,'addreplyidformfield'),9999);
		add_action('admin_menu', array(&$this,'wpadmin'));
	}

	function deactivate(){
		if($this->options['clean_option'] === 'yes')
			delete_option($this->db_options);
		return true;
	}

	function status_change($id,$status){
		$id = (int) $id;
		if(isset($GLOBALS['comment']) && ($GLOBALS['comment']->comment_ID == $id)){
			unset($GLOBALS['comment']);
			$comment = get_comment($id);
			$GLOBALS['comment'] = $comment;
		}

		if ($status== 'approve' && intval($comment->comment_parent)>0){
			$this->mailer($id,$comment->comment_parent,$comment->comment_post_ID);
		}

		return $id;
	}

	function email($id){

		global $wpdb;

		if((int)  $wpdb->_real_escape($_POST['comment_parent']) === 0 || (int)  $wpdb->_real_escape($_POST['comment_post_ID']) === 0){
			$sendemail = 0;
			if (isset($_POST['action']) && $_POST['action'] == 'replyto-comment' && isset($_POST['comment_ID'])) {
				$id_parent = $_POST['comment_ID'];
				if($this->options['mail_notify'] === 'parent_check'){
					$request = $wpdb->get_row("SELECT comment_mail_notify FROM $wpdb->comments WHERE comment_ID='$id_parent'");
					$sendemail = $request->comment_mail_notify;
				} else {
					$sendemail = 1;
				}
			}
			if ($sendemail == 0) {
				return $id;
			}
			$comment_parent =  $wpdb->_real_escape($_POST['comment_ID']);
			$comment_post =  $wpdb->_real_escape($_POST['comment_post_ID']);
		} else {
			$comment_parent =  $wpdb->_real_escape($_POST['comment_parent']);
			$comment_post =  $wpdb->_real_escape($_POST['comment_post_ID']);
		}

		if($this->options['mail_notify'] != 'none'){
			$this->mailer($id,$comment_parent,$comment_post);
		}
		return $id;
	}

	function add_mail_reply($id){
		global $wpdb;

		if(isset($_POST['comment_mail_notify'])){
			$i = 0;
			if($wpdb->query("Describe {$wpdb->comments} comment_mail_notify") == 0 && $i < 10){
				$wpdb->query("ALTER TABLE {$wpdb->comments} ADD COLUMN comment_mail_notify TINYINT NOT NULL DEFAULT 0;");
				$i++;
			}
			$wpdb->query("UPDATE {$wpdb->comments} SET comment_mail_notify='1' WHERE comment_ID='$id'");
		}

		return $id;
	}

	function mailer($id,$parent_id,$comment_post_id){
		global $wpdb, $user_ID, $userdata;

		$post = get_post($comment_post_id);

		if(empty($post)){
			unset($post);
			return false;
		}

		if($this->options['mail_notify'] == 'admin'){
			$cap = $wpdb->prefix . 'capabilities';
			if((strtolower((string) array_shift(array_keys((array)($userdata->$cap)))) !== 'administrator') && ((int)$post->post_author !== (int)$user_ID)){
				unset($post, $cap);
				return false;
			}
		}

		//$parent_email = trim($wpdb->get_var("SELECT comment_author_email FROM {$wpdb->comments} WHERE comment_ID='$parent_id'"));
		$pc = get_comment($parent_id);
		if(empty($pc)){
			unset($pc);
			return false;
		}

		if(intval($pc->comment_mail_notify) === 0 && ($this->options['mail_notify'] === 'parent_uncheck' || $this->options['mail_notify'] === 'parent_check')){
			unset($pc);
			return false;
		}

		$parent_email = trim($pc->comment_author_email);

		if(empty($parent_email) || !is_email($parent_email)){
			unset($pc, $parent_email);
			return false;
		}

		$cc = get_comment($id);
		if(empty($cc)){
			unset($pc,$cc);
			return false;
		}

		if ($cc->comment_approved != '1')
		{
			unset($pc,$cc);
			return false;
		}

		if($parent_email === trim($cc->comment_author_email)){
			unset($pc,$cc);
			return false;
		}

		$mail_subject = $this->options['mail_subject'];
		$mail_subject = str_replace('[blogname]', get_option('blogname'), $mail_subject);
		$mail_subject = str_replace('[postname]', $post->post_title, $mail_subject);

		$mail_message = $this->options['mail_message'];
		$mail_message = str_replace('[pc_date]', mysql2date( get_option('date_format'), $pc->comment_date), $mail_message);
		$mail_message = str_replace('[pc_content]', $pc->comment_content, $mail_message);
		$mail_message = str_replace('[pc_author]', $pc->comment_author, $mail_message);

		$mail_message = str_replace('[cc_author]', $cc->comment_author, $mail_message);
		$mail_message = str_replace('[cc_date]', mysql2date( get_option('date_format'), $cc->comment_date), $mail_message);
		$mail_message = str_replace('[cc_url]', $cc->comment_url, $mail_message);
		$mail_message = str_replace('[cc_content]', $cc->comment_content, $mail_message);

		$mail_message = str_replace('[blogname]', get_option('blogname'), $mail_message);
		$mail_message = str_replace('[blogurl]', get_option('home'), $mail_message);
		$mail_message = str_replace('[postname]', $post->post_title, $mail_message);

		//$permalink = get_permalink($comment_post_id);
		$permalink =  get_comment_link($parent_id);

		//$mail_message = str_replace('[commentlink]', $permalink . "#comment-{$parent_id}", $mail_message);
		$mail_message = str_replace('[commentlink]', $permalink, $mail_message);

		$wp_email = 'no-reply@' . preg_replace('#^www\.#', '', strtolower($_SERVER['SERVER_NAME']));
		$from = "From: \"".get_option('blogname')."\" <$wp_email>";

		$mail_headers = "$from\nContent-Type: text/html; charset=" . get_option('blog_charset') . "\n";

		unset($wp_email, $from, $post, $pc, $cc, $cap, $permalink);

		$mail_message = convert_smilies($mail_message);

		$mail_message = apply_filters('comment_notification_text', $mail_message, $id);
		$mail_subject = apply_filters('comment_notification_subject', $mail_subject, $id);
		$mail_headers = apply_filters('comment_notification_headers', $mail_headers, $id);

		wp_mail($parent_email, $mail_subject, $mail_message, $mail_headers);
		unset($mail_subject,$parent_email,$mail_message, $mail_headers);

		return true;
	}

	function addreplyidformfield(){
		if($this->options['mail_notify'] === 'parent_check')
			echo '<p><input type="checkbox" name="comment_mail_notify" id="comment_mail_notify" value="comment_mail_notify" checked="checked" style="width: auto;" /><label for="comment_mail_notify">' . __('Notify me of follow-up comments via e-mail', 'comment-reply-notification') . '</label></p>';
		elseif($this->options['mail_notify'] === 'parent_uncheck')
			echo '<p><input type="checkbox" name="comment_mail_notify" id="comment_mail_notify" value="comment_mail_notify" style="width: auto;" /><label for="comment_mail_notify">' . __('Notify me of follow-up comments via e-mail', 'comment-reply-notification') . '</label></p>';
		else{}
	}

	function displayMessage() {
		if ( $this->message != '') {
			$message = $this->message;
			$status = $this->status;
			$this->message = $this->status = '';
		}

		if ( $message ) {
?>
			<div id="message" class="<?php echo ($status != '') ? $status :'updated '; ?> fade">
				<p><strong><?php echo $message; ?></strong></p>
			</div>
<?php
		}
		unset($message,$status);
	}

	function wpadmin(){
		add_options_page(__('Comment Reply Notification Option','comment-reply-notification'), __('Thông báo bình luận','comment-reply-notification'), 'administrator', 'comment-reply-notification', array(&$this,'options_page'));
	}

	function options_page(){

		if(isset($_POST['updateoptions'])){
			foreach((array) $this->options as $key => $oldvalue) {
				$this->options[$key] = (isset($_POST[$key]) && !empty($_POST[$key])) ? stripslashes($_POST[$key]) : $this->defaultoption($key);
			}
			update_option($this->db_options, $this->options);
			$this->message = __('Options saved','comment-reply-notification');
			$this->status = 'updated';
		}elseif( isset($_POST['reset_options']) ){
			$this->resetToDefaultOptions();
			$this->message = __('Plugin confriguration has been reset back to default!','comment-reply-notification');
		}else{}
		$this->displayMessage();
?>

<div class="wrap">
	<style type="text/css">
		div.clearing{border-top:1px solid #2580B2;} #wpfooter{position: relative !important;}
	</style>

	<h2>Gửi thông báo trả lời bình luận</h2>
	<form method="post" action="">
		<fieldset name="wp_basic_options"  class="options">
		<p>
			<strong><?php _e('Gửi email thông báo cho người đọc khi bình luận của họ được trả lời','comment-reply-notification'); ?></strong>
			<br /><br />
			<input type="radio" name="mail_notify" id="do_none" value="none" <?php if ($this->options['mail_notify'] !== 'admin' || $this->options['mail_notify'] !== 'everyone') { ?> checked="checked"<?php } ?>/><label><?php _e('Disabled','comment-reply-notification'); ?></label>
			<br />
			<input type="radio" name="mail_notify" id="do_everyone" value="everyone" <?php if ($this->options['mail_notify'] === 'everyone') { ?> checked="checked"<?php } ?>/><label><?php _e('Enabled','comment-reply-notification'); ?></label>
			<br />
		</p>
		<div class="clearing"></div>
		<p>
			<strong><?php _e('Cài đặt tiêu đề email thông báo','comment-reply-notification'); ?></strong>
			<br /><br />
			<input type="text" name="mail_subject" id="mail_subject" value="<?php echo $this->options['mail_subject']; ?>" size="80" />
			<br />
			<small><?php _e('Có thể dùng thẻ <strong>[blogname]</strong> thay cho tên Website, và <strong>[postname]</strong> thay cho tiêu đề bài viết','comment-reply-notification'); ?></small>
			<br />
		</p>
		<div class="clearing"></div>
		<p>
			<strong><?php _e('Cài đặt nội dung email thông báo','comment-reply-notification'); ?></strong>
			<br /><br />
			<textarea style="font-size: 90%" name="mail_message" id="mail_message" cols="100%" rows="10" ><?php echo $this->options['mail_message']; ?></textarea>
			<br />
			<small><?php _e('Sử dụng HTML, bạn có thể sử dụng thẻ: <strong>[pc_author]</strong> thay cho tên người bình luận, <strong>[pc_date]</strong> thay cho ngày bình luận, <strong>[pc_content]</strong> thay cho nội dung bình luận gốc, <strong>[cc_author]</strong> thay cho tên người trả lời, <strong>[cc_date]</strong> thay cho ngày trả lời, <strong>[cc_content]</strong> thay cho nội dung trả lời, <strong>[commentlink]</strong> thay cho link tới bình luận, <strong>[blogname]</strong> thay cho tên Website, <strong>[blogurl]</strong> thay cho Website Url, <strong>[postname]</strong> thay cho tiêu đề bài viết. ','comment-reply-notification'); ?></small>
		</p>
		<div class="clearing"></div>
		<p class="submit">
			<input type="submit" name="updateoptions" value="<?php _e('Update Options','comment-reply-notification'); ?> &raquo;" />
			<input type="submit" name="reset_options" onclick="return confirm('<?php _e('Bạn có chắc muốn sử dụng cấu hình ngầm định?','comment-reply-notification'); ?>');" value="<?php _e('Reset Options','comment-reply-notification'); ?>" />
		</p>
		</fieldset>
	</form>
  </div>
</div>
<?php
	}
}
endif;

$new_comment_reply_notification = new comment_reply_notification();