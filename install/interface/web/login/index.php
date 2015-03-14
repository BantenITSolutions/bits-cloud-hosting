<?php

/*
Copyright (c) 2005, Till Brehm, projektfarm Gmbh
All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice,
      this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice,
      this list of conditions and the following disclaimer in the documentation
      and/or other materials provided with the distribution.
    * Neither the name of ISPConfig nor the names of its contributors
      may be used to endorse or promote products derived from this software without
      specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

//

class login_index {

	public $status = '';
	private $target = '';
	private $app;
	private $conf;

	public function render() {

		global $app, $conf;

		/* Redirect to page, if login form was NOT send */
		if(count($_POST) == 0) {
			if(isset($_SESSION['s']['user']) && is_array($_SESSION['s']['user']) && is_array($_SESSION['s']['module'])) {
				die('HEADER_REDIRECT:'.$_SESSION['s']['module']['startpage']);
			}
		}

		$app->uses('tpl');
		$app->tpl->newTemplate('form.tpl.htm');

		$error = '';

		$app->load_language_file('web/login/lib/lang/'.$conf["language"].'.lng');

		// Maintenance mode
		$maintenance_mode = false;
		$maintenance_mode_error = '';
		$app->uses('ini_parser,getconf');
		$server_config_array = $app->getconf->get_global_config('misc');
		if($server_config_array['maintenance_mode'] == 'y'){
			$maintenance_mode = true;
			$maintenance_mode_error = $app->lng('error_maintenance_mode');
		}

		//* Login Form was sent
		if(count($_POST) > 0) {

			//** Check variables
			if(!preg_match("/^[\w\.\-\_\@]{1,128}$/", $_POST['username'])) $error = $app->lng('user_regex_error');
			if(!preg_match("/^.{1,64}$/i", $_POST['passwort'])) $error = $app->lng('pw_error_length');

			//** iporting variables
			$ip    = $app->db->quote(ip2long($_SERVER['REMOTE_ADDR']));
			$username = $app->db->quote($_POST['username']);
			$passwort = $app->db->quote($_POST['passwort']);
			$loginAs  = false;
			$time = time();

			if($username != '' && $passwort != '' && $error == '') {
				/*
				 *  Check, if there is a "login as" instead of a "normal" login
				 */
				if (isset($_SESSION['s']['user']) && $_SESSION['s']['user']['active'] == 1){
					/*
					 * only the admin or reseller can "login as" so if the user is NOT an admin or reseller, we
					 * open the startpage (after killing the old session), so the user
					 * is logout and has to start again!
					 */
					if ($_SESSION['s']['user']['typ'] != 'admin' && !$app->auth->has_clients($_SESSION['s']['user']['userid'])) {
						/*
						 * The actual user is NOT a admin or reseller, but maybe he
						 * has logged in as "normal" user before...
						 */
						
						if (isset($_SESSION['s_old'])&& ($_SESSION['s_old']['user']['typ'] == 'admin' || $app->auth->has_clients($_SESSION['s_old']['user']['userid']))){
							/* The "old" user is admin or reseller, so everything is ok
							 * if he is reseller, we need to check if he logs in to one of his clients
							 */
							if($_SESSION['s_old']['user']['typ'] != 'admin') {
								
								/* this is the one currently logged in (normal user) */
								$old_client_group_id = $app->functions->intval($_SESSION["s"]["user"]["default_group"]);
								$old_client = $app->db->queryOneRecord("SELECT client.client_id, client.parent_client_id FROM sys_group, client WHERE sys_group.client_id = client.client_id and sys_group.groupid = $old_client_group_id");
								
								/* this is the reseller, that shall be re-logged in */
								$sql = "SELECT * FROM sys_user WHERE USERNAME = '$username' and PASSWORT = '". $passwort. "'";
								$tmp = $app->db->queryOneRecord($sql);
								$client_group_id = $app->functions->intval($tmp['default_group']);
								$tmp_client = $app->db->queryOneRecord("SELECT client.client_id FROM sys_group, client WHERE sys_group.client_id = client.client_id and sys_group.groupid = $client_group_id");
								
								if(!$tmp_client || $old_client["parent_client_id"] != $tmp_client["client_id"] || $tmp["default_group"] != $_SESSION["s_old"]["user"]["default_group"] ) {
									die("You don't have the right to 'login as' this user!");
								}
								unset($old_client);
								unset($tmp_client);
								unset($tmp);
							}
						}
						else {
							die("You don't have the right to 'login as'!");
						}
					} elseif($_SESSION['s']['user']['typ'] != 'admin' && (!isset($_SESSION['s_old']['user']) || $_SESSION['s_old']['user']['typ'] != 'admin')) {
						/* a reseller wants to 'login as', we need to check if he is allowed to */
						$res_client_group_id = $app->functions->intval($_SESSION["s"]["user"]["default_group"]);
						$res_client = $app->db->queryOneRecord("SELECT client.client_id FROM sys_group, client WHERE sys_group.client_id = client.client_id and sys_group.groupid = $res_client_group_id");
						
						/* this is the user the reseller wants to 'login as' */
						$sql = "SELECT * FROM sys_user WHERE USERNAME = '$username' and PASSWORT = '". $passwort. "'";
						$tmp = $app->db->queryOneRecord($sql);
						$tmp_client = $app->db->queryOneRecord("SELECT client.client_id, client.parent_client_id FROM sys_group, client WHERE sys_group.client_id = client.client_id and sys_group.groupid = " . $app->functions->intval($tmp["default_group"]));
						
						if(!$tmp || $tmp_client["parent_client_id"] != $res_client["client_id"]) {
							die("You don't have the right to login as this user!");
						}
						unset($res_client);
						unset($tmp);
						unset($tmp_client);
					}
					$loginAs = true;
				}
				else {
					/* normal login */
					$loginAs = false;
				}

				//* Check if there are already wrong logins
				$sql = "SELECT * FROM `attempts_login` WHERE `ip`= '{$ip}' AND  `login_time` > (NOW() - INTERVAL 1 MINUTE) LIMIT 1";
				$alreadyfailed = $app->db->queryOneRecord($sql);
				//* too many failedlogins
				if($alreadyfailed['times'] > 5) {
					$error = $app->lng('error_user_too_many_logins');
				} else {

					if ($loginAs){
						$sql = "SELECT * FROM sys_user WHERE USERNAME = '$username' and PASSWORT = '". $passwort. "'";
						$user = $app->db->queryOneRecord($sql);
					} else {
						if(stristr($username, '@')) {
							//* mailuser login
							$sql = "SELECT * FROM mail_user WHERE login = '$username' or email = '$username'";
							$mailuser = $app->db->queryOneRecord($sql);
							$user = false;
							if($mailuser) {
								$saved_password = stripslashes($mailuser['password']);
								$salt = '$1$'.substr($saved_password, 3, 8).'$';
								//* Check if mailuser password is correct
								if(crypt(stripslashes($passwort), $salt) == $saved_password) {
									//* we build a fake user here which has access to the mailuser module only and userid 0
									$user = array();
									$user['userid'] = 0;
									$user['active'] = 1;
									$user['startmodule'] = 'mailuser';
									$user['modules'] = 'mailuser';
									$user['typ'] = 'user';
									$user['email'] = $mailuser['email'];
									$user['username'] = $username;
									$user['language'] = $conf['language'];
									$user['theme'] = $conf['theme'];
									$user['app_theme'] = $conf['theme'];
									$user['mailuser_id'] = $mailuser['mailuser_id'];
									$user['default_group'] = $mailuser['sys_groupid'];
								}
							}

						} else {
							//* normal cp user login
							$sql = "SELECT * FROM sys_user WHERE USERNAME = '$username'";
							$user = $app->db->queryOneRecord($sql);

							if($user) {
								$saved_password = stripslashes($user['passwort']);

								if(substr($saved_password, 0, 3) == '$1$') {
									//* The password is crypt-md5 encrypted
									$salt = '$1$'.substr($saved_password, 3, 8).'$';

									if(crypt(stripslashes($passwort), $salt) != $saved_password) {
										$user = false;
									}
								} elseif(substr($saved_password, 0, 3) == '$5$') {
									//* The password is crypt-sha256 encrypted
									$salt = '$5$'.substr($saved_password, 3, 16).'$';

									if(crypt(stripslashes($passwort), $salt) != $saved_password) {
										$user = false;
									}
								} else {

									//* The password is md5 encrypted
									if(md5($passwort) != $saved_password) {
										$user = false;
									}
								}
							} else {
								$user = false;
							}
						}
					}

					if($user) {
						if($user['active'] == 1) {
							// Maintenance mode - allow logins only when maintenance mode is off or if the user is admin
							if(!$maintenance_mode || $user['typ'] == 'admin'){
								// User login right, so attempts can be deleted
								$sql = "DELETE FROM `attempts_login` WHERE `ip`='{$ip}'";
								$app->db->query($sql);
								$user = $app->db->toLower($user);

								if ($loginAs) $oldSession = $_SESSION['s'];
								session_regenerate_id();
								$_SESSION = array();
								if ($loginAs) $_SESSION['s_old'] = $oldSession; // keep the way back!
								$_SESSION['s']['user'] = $user;
								$_SESSION['s']['user']['theme'] = isset($user['app_theme']) ? $user['app_theme'] : 'default';
								$_SESSION['s']['language'] = $user['language'];
								$_SESSION["s"]['theme'] = $_SESSION['s']['user']['theme'];
								
								if(is_file($_SESSION['s']['user']['startmodule'].'/lib/module.conf.php')) {
									include_once $_SESSION['s']['user']['startmodule'].'/lib/module.conf.php';
									$menu_dir = ISPC_WEB_PATH.'/' . $_SESSION['s']['user']['startmodule'] . '/lib/menu.d';

									if (is_dir($menu_dir)) {
										if ($dh = opendir($menu_dir)) {
											//** Go through all files in the menu dir
											while (($file = readdir($dh)) !== false) {
												if ($file != '.' && $file != '..' && substr($file, -9, 9) == '.menu.php' && $file != 'dns_resync.menu.php') {
													include_once $menu_dir . '/' . $file;
												}
											}
										}
									}
									$_SESSION['s']['module'] = $module;
								}

								// check if the user theme is valid
								if($_SESSION['s']['user']['theme'] != 'default') {
									$tmp_path = ISPC_THEMES_PATH."/".$_SESSION['s']['user']['theme'];
									if(!@is_dir($tmp_path) || !@file_exists($tmp_path."/ispconfig_version") || trim(file_get_contents($tmp_path."/ispconfig_version")) != ISPC_APP_VERSION) {
										// fall back to default theme if this one is not compatible with current ispc version
										$_SESSION['s']['user']['theme'] = 'default';
										$_SESSION['s']['theme'] = 'default';
										$_SESSION['show_error_msg'] = $app->lng('theme_not_compatible');
									}
								}

								$app->plugin->raiseEvent('login', $this);

								//* Save successfull login message to var
								$authlog = 'Successful login for user \''. $username .'\' from '. long2ip($ip) .' at '. date('Y-m-d H:i:s');
								$authlog_handle = fopen($conf['ispconfig_log_dir'].'/auth.log', 'a');
								fwrite($authlog_handle, $authlog ."\n");
								fclose($authlog_handle);

								/*
								* We need LOGIN_REDIRECT instead of HEADER_REDIRECT to load the
								* new theme, if the logged-in user has another
								*/
								echo 'LOGIN_REDIRECT:'.$_SESSION['s']['module']['startpage'];

								exit;
							}
						} else {
							$error = $app->lng('error_user_blocked');
						}

					} else {
						if(!$alreadyfailed['times'] )
						{
							//* user login the first time wrong
							$sql = "INSERT INTO `attempts_login` (`ip`, `times`, `login_time`) VALUES ('{$ip}', 1, NOW())";
							$app->db->query($sql);
						} elseif($alreadyfailed['times'] >= 1) {
							//* update times wrong
							$sql = "UPDATE `attempts_login` SET `times`=`times`+1, `login_time`=NOW() WHERE `login_time` >= '{$time}' LIMIT 1";
							$app->db->query($sql);
						}
						//* Incorrect login - Username and password incorrect
						$error = $app->lng('error_user_password_incorrect');
						if($app->db->errorMessage != '') $error .= '<br />'.$app->db->errorMessage != '';

						$app->plugin->raiseEvent('login_failed', $this);

						//* Save failed login message to var
						$authlog = 'Failed login for user \''. $username .'\' from '. long2ip($ip) .' at '. date('Y-m-d H:i:s');
						$authlog_handle = fopen($conf['ispconfig_log_dir'].'/auth.log', 'a');
						fwrite($authlog_handle, $authlog ."\n");
						fclose($authlog_handle);
					}
				}

			} else {
				//* Username or password empty
				if($error == '') $error = $app->lng('error_user_password_empty');

				$app->plugin->raiseEvent('login_empty', $this);
			}
		}

		// Maintenance mode - show message when people try to log in and also when people are forcedly logged off
		if($maintenance_mode_error != '') $error = '<strong>'.$maintenance_mode_error.'</strong><br><br>'.$error;
		if($error != ''){
			$error = '<div class="box box_error"><h1>Error</h1>'.$error.'</div>';
		}
		
		$app->load('getconf');

		$security_config = $app->getconf->get_security_config('permissions');
		if($security_config['password_reset_allowed'] == 'yes') {
			$app->tpl->setVar('pw_lost_show', 1);
		} else {
			$app->tpl->setVar('pw_lost_show', 0);
		}
		
		$app->tpl->setVar('error', $error);
		$app->tpl->setVar('pw_lost_txt', $app->lng('pw_lost_txt'));
		$app->tpl->setVar('username_txt', $app->lng('username_txt'));
		$app->tpl->setVar('password_txt', $app->lng('password_txt'));
		$app->tpl->setVar('stay_logged_in_txt', $app->lng('stay_logged_in_txt'));
		$app->tpl->setVar('login_button_txt', $app->lng('login_button_txt'));
		$app->tpl->setVar('session_timeout', $server_config_array['session_timeout']);
		$app->tpl->setVar('session_allow_endless', $server_config_array['session_allow_endless']);
		$app->tpl->setInclude('content_tpl', 'login/templates/index.htm');
		$app->tpl_defaults();

		$this->status = 'OK';

		return $app->tpl->grab();

	} // << end function

} // << end class

?>
