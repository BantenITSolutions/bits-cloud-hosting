<?php

/*
Copyright (c) 2007 - 2011, Till Brehm, projektfarm Gmbh
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

--UPDATED 08.2009--
Full SOAP support for ISPConfig 3.1.4 b
Updated by Arkadiusz Roch & Artur Edelman
Copyright (c) Tri-Plex technology

*/

class remoting {

	//* remote session timeout in seconds
	private $session_timeout = 600;

	protected $server;
	public $oldDataRecord;
	public $dataRecord;
	public $id;

	/*
	These variables shall stay global.
	Please do not make them private variables.

	private $app;
    private $conf;
    */

	public function __construct()
	{
		global $server;
		$this->server = $server;
		/*
        $this->app = $app;
        $this->conf = $conf;
		*/
	}

	//* remote login function
	public function login($username, $password)
	{
		global $app, $conf, $server;

		// Maintenance mode
		$app->uses('ini_parser,getconf');
		$server_config_array = $app->getconf->get_global_config('misc');
		if($server_config_array['maintenance_mode'] == 'y'){
			$this->server->fault('maintenance_mode', 'This ISPConfig installation is currently under maintenance. We should be back shortly. Thank you for your patience.');
			return false;
		}

		if(empty($username)) {
			$this->server->fault('login_username_empty', 'The login username is empty.');
			return false;
		}

		if(empty($password)) {
			$this->server->fault('login_password_empty', 'The login password is empty.');
			return false;
		}

		//* Delete old remoting sessions
		$sql = "DELETE FROM remote_session WHERE tstamp < ".time();
		$app->db->query($sql);

		$username = $app->db->quote($username);
		$password = $app->db->quote($password);

		$sql = "SELECT * FROM remote_user WHERE remote_username = '$username' and remote_password = md5('$password')";
		$remote_user = $app->db->queryOneRecord($sql);
		if($remote_user['remote_userid'] > 0) {
			//* Create a remote user session
			srand((double)microtime()*1000000);
			$remote_session = md5(rand());
			$remote_userid = $remote_user['remote_userid'];
			$remote_functions = $remote_user['remote_functions'];
			$tstamp = time() + $this->session_timeout;
			$sql = 'INSERT INTO remote_session (remote_session,remote_userid,remote_functions,tstamp'
				.') VALUES ('
				." '$remote_session',$remote_userid,'$remote_functions',$tstamp)";
			$app->db->query($sql);
			return $remote_session;
		} else {
			$this->server->fault('login_failed', 'The login failed. Username or password wrong.');
			return false;
		}

	}

	//* remote logout function
	public function logout($session_id)
	{
		global $app;

		if(empty($session_id)) {
			$this->server->fault('session_id_empty', 'The SessionID is empty.');
			return false;
		}

		$session_id = $app->db->quote($session_id);

		$sql = "DELETE FROM remote_session WHERE remote_session = '$session_id'";
		if($app->db->query($sql) != false) {
			return true;
		} else {
			return false;
		}
	}


	/**
	 Gets the server configuration
	 @param int session id
	 @param int server id
	 @param string  section of the config field in the server table. Could be 'web', 'dns', 'mail', 'dns', 'cron', etc
	 @author Julio Montoya <gugli100@gmail.com> BeezNest 2010, extended by M. Cramer <m.cramer@pixcept.de> 2014
	 */


	public function server_get($session_id, $server_id = null, $section ='') {
		global $app;
		if(!$this->checkPerm($session_id, 'server_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		if (!empty($session_id)) {
			$app->uses('remoting_lib , getconf');
			if(!empty($server_id)) {
				$section_config =  $app->getconf->get_server_config($server_id, $section);
				return $section_config;
			} else {
				$servers = array();
				$sql = "SELECT server_id FROM server WHERE 1";
				$all = $app->db->queryAllRecords($sql);
				foreach($all as $s) {
					$servers[$s['server_id']] = $app->getconf->get_server_config($s['server_id'], $section);
				}
				unset($all);
				unset($s);
				return $servers;
			}
		} else {
			return false;
		}
	}
	
	/**
	    Gets a list of all servers
	    @param int session_id
	    @param int server_name
	    @author Marius Cramer <m.cramer@pixcept.de> 2014
    */
	public function server_get_all($session_id)
    {
        global $app;
		if(!$this->checkPerm($session_id, 'server_get')) {
        	$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
            return false;
		}
		if (!empty($session_id)) {
			$sql = "SELECT server_id, server_name FROM server WHERE 1";
			$servers = $app->db->queryAllRecords($sql);
			return $servers;
		} else {
			return false;
		}
	}
	
	/**
	    Gets the server_id by server_name
	    @param int session_id
	    @param int server_name
	    @author Sascha Bay <info@space2place.de> TheCry 2013
    */
	public function server_get_serverid_by_name($session_id, $server_name)
    {
        global $app;
		if(!$this->checkPerm($session_id, 'server_get')) {
        	$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
            return false;
		}
		if (!empty($session_id) && !empty($server_name)) {
			$sql = "SELECT server_id FROM server WHERE server_name  = '$server_name' LIMIT 1 ";
			$all = $app->db->queryAllRecords($sql);
			return $all;
		} else {
			return false;
		}
	}
	
	/**
	    Gets the functions of a server by server_id
	    @param int session_id
	    @param int server_id
	    @author Sascha Bay <info@space2place.de> TheCry 2013
    */
	public function server_get_functions($session_id, $server_id)
    {
        global $app;
		if(!$this->checkPerm($session_id, 'server_get')) {
        	$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
            return false;
		}
		if (!empty($session_id) && !empty($server_id)) { 
			$sql = "SELECT mail_server, web_server, dns_server, file_server, db_server, vserver_server, proxy_server, firewall_server FROM server WHERE server_id  = '$server_id' LIMIT 1 ";
			$all = $app->db->queryAllRecords($sql);
			return $all;
		} else {
			return false;
		}
	}
	
	/**
	 * set record permissions in any table
	 * @param string session_id
	 * @param string index_field
	 * @param string index_value
	 * @param array permissions
	 * @author "ispcomm", improved by M. Cramer <m.cramer@pixcept.de>
	 */
	public function update_record_permissions($tablename, $index_field, $index_value, $permissions) {
		global $app;
		
		if(!$this->checkPerm($session_id, 'admin_record_permissions')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		
		foreach($permissions as $key => $value) {  // make sure only sys_ fields are updated
			switch($key) {
				case 'sys_userid':
					// check if userid is valid
					$check = $app->db->queryOneRecord('SELECT userid FROM sys_user WHERE userid = ' . $app->functions->intval($value));
					if(!$check || !$check['userid']) {
						$this->server->fault('invalid parameters', $value . ' is no valid sys_userid.');
						return false;
					}
					$permissions[$key] = $app->functions->intval($value);
					break;
				case 'sys_groupid':
					// check if groupid is valid
					$check = $app->db->queryOneRecord('SELECT groupid FROM sys_group WHERE groupid = ' . $app->functions->intval($value));
					if(!$check || !$check['groupid']) {
						$this->server->fault('invalid parameters', $value . ' is no valid sys_groupid.');
						return false;
					}
					$permissions[$key] = $app->functions->intval($value);
					break;
				case 'sys_perm_user':
				case 'sys_perm_group':
					// check if permissions are valid
					$value = strtolower($value);
					if(!preg_match('/^[riud]+$/', $value)) {
						$this->server->fault('invalid parameters', $value . ' is no valid permission string.');
						return false;
					}
					
					$newvalue = '';
					if(strpos($value, 'r') !== false) $newvalue .= 'r';
					if(strpos($value, 'i') !== false) $newvalue .= 'i';
					if(strpos($value, 'u') !== false) $newvalue .= 'u';
					if(strpos($value, 'd') !== false) $newvalue .= 'd';
					$permissions[$key] = $newvalue;
					unset($newvalue);
					
					break;
				default:
					$this->server->fault('invalid parameters', 'Only sys_userid, sys_groupid, sys_perm_user and sys_perm_group parameters can be changed with this function.');
					break;
			}
		}
		
		return $app->db->datalogUpdate( $tablename, $permissions, $index_field, $index_value ) ;
	}
	
	/**
	    Gets the ISPconfig version of the server
	    @param int session_id
	    @author Sascha Bay <info@space2place.de> TheCry 2013
    */
	public function server_get_app_version($session_id)
    {
        global $app;
		if(!$this->checkPerm($session_id, 'server_get')) {
        	$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
            return false;
		}
		if (!empty($session_id)) { 
			$ispc_app_version = array('ispc_app_version' => ISPC_APP_VERSION);
			return $ispc_app_version;
		} else {
			return false;
		}
	}

	public function server_get_serverid_by_ip($session_id, $ipaddress)
	{
		global $app;
		if(!$this->checkPerm($session_id, 'server_get_serverid_by_ip')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$sql = "SELECT server_id FROM server_ip WHERE ip_address  = '$ipaddress' LIMIT 1 ";
		$all = $app->db->queryAllRecords($sql);
		return $all;
	}

	//* Get server ips
	public function server_ip_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'server_ip_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../admin/form/server_ip.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}
	
	//* Add a IP address record
	public function server_ip_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'server_ip_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../admin/form/server_ip.tform.php', $client_id, $params);
	}

	//* Update IP address record
	public function server_ip_update($session_id, $client_id, $ip_id, $params)
	{
		if(!$this->checkPerm($session_id, 'server_ip_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../admin/form/server_ip.tform.php', $client_id, $ip_id, $params);
		return $affected_rows;
	}

	//* Delete IP address record
	public function server_ip_delete($session_id, $ip_id)
	{
		if(!$this->checkPerm($session_id, 'server_ip_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../admin/form/server_ip.tform.php', $ip_id);
		return $affected_rows;
	}

	//* Get mail domain details
	public function mail_domain_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_domain_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_domain.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a mail domain
	public function mail_domain_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'mail_domain_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$primary_id = $this->insertQuery('../mail/form/mail_domain.tform.php', $client_id, $params);
		return $primary_id;
	}

	//* Update a mail domain
	public function mail_domain_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'mail_domain_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_domain.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a mail domain
	public function mail_domain_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'mail_domain_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_domain.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get alias details
	public function mail_aliasdomain_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_aliasdomain_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_aliasdomain.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* aliasy email
	public function mail_aliasdomain_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_aliasdomain_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/mail_aliasdomain.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_aliasdomain_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_aliasdomain_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_aliasdomain.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	public function mail_aliasdomain_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_aliasdomain_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_aliasdomain.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get mail mailinglist details
	public function mail_mailinglist_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_mailinglist_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_mailinglist.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a mail mailinglist
	public function mail_mailinglist_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'mail_mailinglist_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$primary_id = $this->insertQuery('../mail/form/mail_mailinglist.tform.php', $client_id, $params);
		return $primary_id;
	}

	//* Update a mail mailinglist
	public function mail_mailinglist_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'mail_mailinglist_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_mailinglist.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a mail mailinglist
	public function mail_mailinglist_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'mail_mailinglist_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_mailinglist.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get mail user details
	public function mail_user_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_user_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_user.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}


	//* Add mail domain
	public function mail_user_add($session_id, $client_id, $params){
		global $app;

		if (!$this->checkPerm($session_id, 'mail_user_add')){
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		//* Check if mail domain exists
		$email_parts = explode('@', $params['email']);
		$tmp = $app->db->queryOneRecord("SELECT domain FROM mail_domain WHERE domain = '".$app->db->quote($email_parts[1])."'");
		if($tmp['domain'] != $email_parts[1]) {
			$this->server->fault('mail_domain_does_not_exist', 'Mail domain - '.$email_parts[1].' - does not exist.');
			return false;
		}

		$affected_rows = $this->insertQuery('../mail/form/mail_user.tform.php', $client_id, $params);
		return $affected_rows;
	}

	//* Update mail user
	public function mail_user_update($session_id, $client_id, $primary_id, $params)
	{
		global $app;

		if (!$this->checkPerm($session_id, 'mail_user_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		//* Check if mail domain exists
		$email_parts = explode('@', $params['email']);
		$tmp = $app->db->queryOneRecord("SELECT domain FROM mail_domain WHERE domain = '".$app->db->quote($email_parts[1])."'");
		if($tmp['domain'] != $email_parts[1]) {
			$this->server->fault('mail_domain_does_not_exist', 'Mail domain - '.$email_parts[1].' - does not exist.');
			return false;
		}

		$affected_rows = $this->updateQuery('../mail/form/mail_user.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	//* Delete mail user
	public function mail_user_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_user_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_user.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get mail user filter details
	public function mail_user_filter_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_user_filter_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_user_filter.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	public function mail_user_filter_add($session_id, $client_id, $params)
	{
		global $app;
		if (!$this->checkPerm($session_id, 'mail_user_filter_add')){
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/mail_user_filter.tform.php', $client_id, $params, 'mail:mail_user_filter:on_after_insert');
		// $app->plugin->raiseEvent('mail:mail_user_filter:on_after_insert',$this);
		return $affected_rows;
	}

	public function mail_user_filter_update($session_id, $client_id, $primary_id, $params)
	{
		global $app;
		if (!$this->checkPerm($session_id, 'mail_user_filter_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_user_filter.tform.php', $client_id, $primary_id, $params, 'mail:mail_user_filter:on_after_update');
		// $app->plugin->raiseEvent('mail:mail_user_filter:on_after_update',$this);
		return $affected_rows;
	}

	public function mail_user_filter_delete($session_id, $primary_id)
	{
		global $app;
		if (!$this->checkPerm($session_id, 'mail_user_filter_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_user_filter.tform.php', $primary_id, 'mail:mail_user_filter:on_after_delete');
		// $app->plugin->raiseEvent('mail:mail_user_filter:on_after_delete',$this);
		return $affected_rows;
	}

	//* Get alias details
	public function mail_alias_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_alias_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_alias.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* aliasy email
	public function mail_alias_add($session_id, $client_id, $params)
	{
		global $app;

		if (!$this->checkPerm($session_id, 'mail_alias_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		//* Check if there is no active mailbox with this address
		$tmp = $app->db->queryOneRecord("SELECT count(mailuser_id) as number FROM mail_user WHERE postfix = 'y' AND email = '".$app->db->quote($params["source"])."'");
		if($tmp['number'] > 0) {
			$this->server->fault('duplicate', 'There is already a mailbox with this email address.');
		}
		unset($tmp);

		$affected_rows = $this->insertQuery('../mail/form/mail_alias.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_alias_update($session_id, $client_id, $primary_id, $params)
	{
		global $app;

		if (!$this->checkPerm($session_id, 'mail_alias_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		//* Check if there is no active mailbox with this address
		$tmp = $app->db->queryOneRecord("SELECT count(mailuser_id) as number FROM mail_user WHERE postfix = 'y' AND email = '".$app->db->quote($params["source"])."'");
		if($tmp['number'] > 0) {
			$this->server->fault('duplicate', 'There is already a mailbox with this email address.');
		}
		unset($tmp);

		$affected_rows = $this->updateQuery('../mail/form/mail_alias.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	public function mail_alias_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_alias_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_alias.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get mail forwarding details
	public function mail_forward_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_forward_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_forward.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* przekierowania email
	public function mail_forward_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_forward_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/mail_forward.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_forward_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_forward_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_forward.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	public function mail_forward_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_forward_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_forward.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get catchall details
	public function mail_catchall_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_catchall_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_domain_catchall.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* catchall e-mail
	public function mail_catchall_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_catchall_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/mail_domain_catchall.tform.php', $client_id, $params);
		return $affected_rows;
	}

	public function mail_catchall_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_catchall_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_domain_catchall.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	public function mail_catchall_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_catchall_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_domain_catchall.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get transport details
	public function mail_transport_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_transport_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_transport.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* przeniesienia e-mail
	public function mail_transport_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_transport_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/mail_transport.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_transport_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_transport_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_transport.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	public function mail_transport_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_transport_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_transport.tform.php', $primary_id);
		return $affected_rows;
	}


	//* Get mail relay_recipient details
	public function mail_relay_recipient_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_relay_get')) {
				$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
				return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_relay_recipient.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}


	//* relay recipient email
	public function mail_relay_recipient_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_relay_add'))
		{
			$this->server->fault('permission_denied','You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/mail_relay_recipient.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_relay_recipient_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_relay_update'))
		{
			$this->server->fault('permission_denied','You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_relay_recipient.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	public function mail_relay_recipient_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_relay_delete'))
		{
			$this->server->fault('permission_denied','You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_relay_recipient.tform.php', $primary_id);
		return $affected_rows;
	}


	//* Get spamfilter whitelist details
	public function mail_spamfilter_whitelist_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_spamfilter_whitelist_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/spamfilter_whitelist.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* biała lista e-mail
	public function mail_spamfilter_whitelist_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_spamfilter_whitelist_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/spamfilter_whitelist.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_spamfilter_whitelist_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_spamfilter_whitelist_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/spamfilter_whitelist.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	public function mail_spamfilter_whitelist_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_spamfilter_whitelist_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/spamfilter_whitelist.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get spamfilter blacklist details
	public function mail_spamfilter_blacklist_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_spamfilter_blacklist_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/spamfilter_blacklist.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* czarna lista e-mail
	public function mail_spamfilter_blacklist_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_spamfilter_blacklist_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/spamfilter_blacklist.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_spamfilter_blacklist_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_spamfilter_blacklist_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/spamfilter_blacklist.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	public function mail_spamfilter_blacklist_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_spamfilter_blacklist_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/spamfilter_blacklist.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get spamfilter user details
	public function mail_spamfilter_user_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_spamfilter_user_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/spamfilter_users.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* filtr spamu użytkowników e-mail
	public function mail_spamfilter_user_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_spamfilter_user_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/spamfilter_users.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_spamfilter_user_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_spamfilter_user_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/spamfilter_users.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	public function mail_spamfilter_user_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_spamfilter_user_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/spamfilter_users.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get policy details
	public function mail_policy_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_policy_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/spamfilter_policy.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* polityki filtrów spamu e-mail
	public function mail_policy_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_policy_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/spamfilter_policy.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_policy_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_policy_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/spamfilter_policy.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	public function mail_policy_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_policy_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/spamfilter_policy.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get fetchmail details
	public function mail_fetchmail_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_fetchmail_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_get.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* fetchmail
	public function mail_fetchmail_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_fetchmail_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/mail_get.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_fetchmail_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_fetchmail_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_get.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	public function mail_fetchmail_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_fetchmail_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_get.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get whitelist details
	public function mail_whitelist_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_whitelist_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_whitelist.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* wpisy białej listy
	public function mail_whitelist_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_whitelist_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/mail_whitelist.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_whitelist_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_whitelist_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_whitelist.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	public function mail_whitelist_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_whitelist_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_whitelist.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get Blacklist details
	public function mail_blacklist_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_blacklist_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_blacklist.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* wpisy białej listy
	public function mail_blacklist_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_blacklist_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/mail_blacklist.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_blacklist_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_blacklist_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_blacklist.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	public function mail_blacklist_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_blacklist_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_blacklist.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get filter details
	public function mail_filter_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'mail_filter_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../mail/form/mail_content_filter.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* wpisy filtrow e-mail
	public function mail_filter_add($session_id, $client_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_filter_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->insertQuery('../mail/form/mail_content_filter.tform.php', $client_id, $params);
		return $affected_rows;
	}


	public function mail_filter_update($session_id, $client_id, $primary_id, $params)
	{
		if (!$this->checkPerm($session_id, 'mail_filter_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../mail/form/mail_content_filter.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}


	public function mail_filter_delete($session_id, $primary_id)
	{
		if (!$this->checkPerm($session_id, 'mail_filter_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../mail/form/mail_content_filter.tform.php', $primary_id);
		return $affected_rows;
	}




	/*
 *
 *
 *
 * 	 * Client functions
 *
 *
 */
	//* Get client details
	public function client_get($session_id, $client_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'client_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../client/form/client.tform.php');
		$data = $app->remoting_lib->getDataRecord($client_id);

		// we need to get the new-style templates for backwards-compatibility - maybe we remove this in a later version
		if(is_array($data) && count($data) > 0) {
			if(isset($data['client_id'])) {
				// this is a single record
				if($data['template_additional'] == '') {
					$tpls = $app->db->queryAllRecords('SELECT CONCAT(`assigned_template_id`, \':\', `client_template_id`) as `item` FROM `client_template_assigned` WHERE `client_id` = ' . $data['client_id']);
					$tpl_arr = array();
					if($tpls) {
						foreach($tpls as $tpl) $tpl_arr[] = $tpl['item'];
					}
					$data['template_additional'] = implode('/', $tpl_arr);
					unset($tpl_arr);
					unset($tpls);
				}
			} elseif(isset($data[0]['client_id'])) {
				// multiple client records
				foreach($data as $index => $client) {
					if($client['template_additional'] == '') {
						$tpls = $app->db->queryAllRecords('SELECT CONCAT(`assigned_template_id`, \':\', `client_template_id`) as `item` FROM `client_template_assigned` WHERE `client_id` = ' . $client['client_id']);
						$tpl_arr = array();
						if($tpls) {
							foreach($tpls as $tpl) $tpl_arr[] = $tpl['item'];
						}
						$data[$index]['template_additional'] = implode('/', $tpl_arr); // dont use the $client array here - changes would not be returned to soap
					}
					unset($tpl_arr);
					unset($tpls);
				}
			}
		}

		return $data;
	}

	public function client_get_id($session_id, $sys_userid)
	{
		global $app;
		if(!$this->checkPerm($session_id, 'client_get_id')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$sys_userid = $app->functions->intval($sys_userid);

		$rec = $app->db->queryOneRecord("SELECT client_id FROM sys_user WHERE userid = ".$sys_userid);
		if(isset($rec['client_id'])) {
			return $app->functions->intval($rec['client_id']);
		} else {
			$this->server->fault('no_client_found', 'There is no sysuser account for this client ID.');
			return false;
		}

	}

	public function client_get_groupid($session_id, $client_id)
	{
		global $app;
		if(!$this->checkPerm($session_id, 'client_get_id')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$client_id = $app->functions->intval($client_id);

		$rec = $app->db->queryOneRecord("SELECT groupid FROM sys_group WHERE client_id = ".$client_id);
		if(isset($rec['groupid'])) {
			return $app->functions->intval($rec['groupid']);
		} else {
			$this->server->fault('no_group_found', 'There is no group for this client ID.');
			return false;
		}

	}


	public function client_add($session_id, $reseller_id, $params)
	{
		global $app;
		
		if (!$this->checkPerm($session_id, 'client_add'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		if(!isset($params['parent_client_id']) || $params['parent_client_id'] == 0) $params['parent_client_id'] = $reseller_id;
		
		if($params['parent_client_id']) {
			// check if this one is reseller
			$check = $app->db->queryOneRecord('SELECT `limit_client` FROM `client` WHERE `client_id` = ' . intval($params['parent_client_id']));
			if($check['limit_client'] == 0) {
				$this->server->fault('Invalid reseller', 'Selected client is not a reseller.');
				return false;
			}
			
			if(isset($params['limit_client']) && $params['limit_client'] != 0) {
				$this->server->fault('Invalid reseller', 'Reseller cannot be client of another reseller.');
				return false;
			}
		}
		
		$affected_rows = $this->klientadd('../client/form/' . (isset($params['limit_client']) && $params['limit_client'] != 0 ? 'reseller' : 'client') . '.tform.php', $reseller_id, $params);
		return $affected_rows;

	}

	public function client_update($session_id, $client_id, $reseller_id, $params)
	{
		global $app;

		if (!$this->checkPerm($session_id, 'client_update'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../client/form/' . (isset($params['limit_client']) && $params['limit_client'] != 0 ? 'reseller' : 'client') . '.tform.php');
		$old_rec = $app->remoting_lib->getDataRecord($client_id);

		if(!isset($params['parent_client_id']) || $params['parent_client_id'] == 0) $params['parent_client_id'] = $reseller_id;

		if($params['parent_client_id']) {
			// check if this one is reseller
			$check = $app->db->queryOneRecord('SELECT `limit_client` FROM `client` WHERE `client_id` = ' . intval($params['parent_client_id']));
			if($check['limit_client'] == 0) {
				$this->server->fault('Invalid reseller', 'Selected client is not a reseller.');
				return false;
			}
			
			if(isset($params['limit_client']) && $params['limit_client'] != 0) {
				$this->server->fault('Invalid reseller', 'Reseller cannot be client of another reseller.');
				return false;
			}
		}

		// we need the previuos templates assigned here
		$this->oldTemplatesAssigned = $app->db->queryAllRecords('SELECT * FROM `client_template_assigned` WHERE `client_id` = ' . $client_id);
		if(!is_array($this->oldTemplatesAssigned) || count($this->oldTemplatesAssigned) < 1) {
			// check previous type of storing templates
			$tpls = explode('/', $old_rec['template_additional']);
			$this->oldTemplatesAssigned = array();
			foreach($tpls as $item) {
				$item = trim($item);
				if(!$item) continue;
				$this->oldTemplatesAssigned[] = array('assigned_template_id' => 0, 'client_template_id' => $item, 'client_id' => $client_id);
			}
			unset($tpls);
		}
		if(isset($params['template_additional'])) {
			$app->uses('client_templates');
			$templates = explode('/', $params['template_additional']);
			$params['template_additional'] = '';
			$app->client_templates->update_client_templates($client_id, $templates);
			unset($templates);
		}


		$affected_rows = $this->updateQuery('../client/form/' . (isset($params['limit_client']) && $params['limit_client'] != 0 ? 'reseller' : 'client') . '.tform.php', $reseller_id, $client_id, $params, 'client:' . ($params['parent_client_id'] ? 'reseller' : 'client') . ':on_after_update');

		$app->remoting_lib->ispconfig_sysuser_update($params, $client_id);

		return $affected_rows;
	}

	public function client_template_additional_get($session_id, $client_id) {
		global $app;

		if(!$this->checkPerm($session_id, 'client_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		if(@is_numeric($client_id)) {
			$sql = "SELECT * FROM `client_template_assigned` WHERE `client_id` = ".$client_id;
			return $app->db->queryOneRecord($sql);
		} else {
			$this->server->fault('The ID must be an integer.');
			return array();
		}
	}

	private function _set_client_formdata($client_id) {
		global $app;

		$this->id = $client_id;
		$this->dataRecord = $app->db->queryOneRecord('SELECT * FROM `client` WHERE `client_id` = ' . $client_id);
		$this->oldDataRecord = $this->dataRecord;

		$this->oldTemplatesAssigned = $app->db->queryAllRecords('SELECT * FROM `client_template_assigned` WHERE `client_id` = ' . $client_id);
		if(!is_array($this->oldTemplatesAssigned) || count($this->oldTemplatesAssigned) < 1) {
			// check previous type of storing templates
			$tpls = explode('/', $this->oldDataRecord['template_additional']);
			$this->oldTemplatesAssigned = array();
			foreach($tpls as $item) {
				$item = trim($item);
				if(!$item) continue;
				$this->oldTemplatesAssigned[] = array('assigned_template_id' => 0, 'client_template_id' => $item, 'client_id' => $client_id);
			}
			unset($tpls);
		}
	}

	public function client_template_additional_add($session_id, $client_id, $template_id) {
		global $app;

		if(!$this->checkPerm($session_id, 'client_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		if(@is_numeric($client_id) && @is_numeric($template_id)) {
			// check if client exists
			$check = $app->db->queryOneRecord('SELECT `client_id` FROM `client` WHERE `client_id` = ' . $client_id);
			if(!$check) {
				$this->server->fault('Invalid client');
				return false;
			}
			// check if template exists
			$check = $app->db->queryOneRecord('SELECT `template_id` FROM `client_template` WHERE `template_id` = ' . $template_id);
			if(!$check) {
				$this->server->fault('Invalid template');
				return false;
			}

			// for the update event we have to cheat a bit
			$this->_set_client_formdata($client_id);

			$sql = "INSERT INTO `client_template_assigned` (`client_id`, `client_template_id`) VALUES (" . $client_id . ", " . $template_id . ")";
			$app->db->query($sql);
			$insert_id = $app->db->insertID();

			$app->plugin->raiseEvent('client:client:on_after_update', $this);

			return $insert_id;
		} else {
			$this->server->fault('The IDs must be of type integer.');
			return false;
		}
	}

	public function client_template_additional_delete($session_id, $client_id, $assigned_template_id) {
		global $app;

		if(!$this->checkPerm($session_id, 'client_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		if(@is_numeric($client_id) && @is_numeric($template_id)) {
			// check if client exists
			$check = $app->db->queryOneRecord('SELECT `client_id` FROM `client` WHERE `client_id` = ' . $client_id);
			if(!$check) {
				$this->server->fault('Invalid client');
				return false;
			}
			// check if template exists
			$check = $app->db->queryOneRecord('SELECT `assigned_template_id` FROM `client_template_assigned` WHERE `assigned_template_id` = ' . $assigned_template_id);
			if(!$check) {
				$this->server->fault('Invalid template');
				return false;
			}

			// for the update event we have to cheat a bit
			$this->_set_client_formdata($client_id);

			$sql = "DELETE FROM `client_template_assigned` WHERE `assigned_template_id` = " . $template_id . " AND `client_id` = " . $client_id;
			$app->db->query($sql);
			$affected_rows = $app->db->affectedRows();

			$app->plugin->raiseEvent('client:client:on_after_update', $this);

			return $affected_rows;
		} else {
			$this->server->fault('The IDs must be of type integer.');
			return false;
		}
	}

	public function client_delete($session_id, $client_id)
	{
		global $app;

		if (!$this->checkPerm($session_id, 'client_delete'))
		{
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../client/form/client.tform.php', $client_id);

		$app->remoting_lib->ispconfig_sysuser_delete($client_id);

		return $affected_rows;
	}

	// -----------------------------------------------------------------------------------------------

	public function client_delete_everything($session_id, $client_id)
	{
		global $app, $conf;

		if(!$this->checkPerm($session_id, 'client_delete_everything')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$client_id = $app->functions->intval($client_id);

		if($client_id > 0) {
			//* remove the group of the client from the resellers group
			$parent_client_id = $app->functions->intval($this->dataRecord['parent_client_id']);
			$parent_user = $app->db->queryOneRecord("SELECT userid FROM sys_user WHERE client_id = $parent_client_id");
			$client_group = $app->db->queryOneRecord("SELECT groupid FROM sys_group WHERE client_id = $client_id");
			$app->auth->remove_group_from_user($parent_user['userid'], $client_group['groupid']);

			//* delete the group of the client
			$app->db->query("DELETE FROM sys_group WHERE client_id = $client_id");

			//* delete the sys user(s) of the client
			$app->db->query("DELETE FROM sys_user WHERE client_id = $client_id");

			//* Delete all records (sub-clients, mail, web, etc....)  of this client.
			$tables = 'client,dns_rr,dns_soa,dns_slave,ftp_user,mail_access,mail_content_filter,mail_domain,mail_forwarding,mail_get,mail_user,mail_user_filter,shell_user,spamfilter_users,support_message,web_database,web_database_user,web_domain,web_traffic';
			$tables_array = explode(',', $tables);
			$client_group_id = $app->functions->intval($client_group['groupid']);

			if($client_group_id > 1) {
				foreach($tables_array as $table) {
					if($table != '') {
						$records = $app->db->queryAllRecords("SELECT * FROM $table WHERE sys_groupid = ".$client_group_id);
						//* find the primary ID of the table
						$table_info = $app->db->tableInfo($table);
						$index_field = '';
						foreach($table_info as $tmp) {
							if($tmp['option'] == 'primary') $index_field = $tmp['name'];
						}

						//* Delete the records
						if($index_field != '') {
							if(is_array($records)) {
								foreach($records as $rec) {
									$app->db->datalogDelete($table, $index_field, $rec[$index_field]);
									//* Delete traffic records that dont have a sys_groupid column
									if($table == 'web_domain') {
										$app->db->query("DELETE FROM web_traffic WHERE hostname = '".$app->db->quote($rec['domain'])."'");
									}
									//* Delete mail_traffic records that dont have a sys_groupid
									if($table == 'mail_user') {
										$app->db->query("DELETE FROM mail_traffic WHERE mailuser_id = '".$app->db->quote($rec['mailuser_id'])."'");
									}
								}
							}
						}

					}
				}
			}

		}

		if (!$this->checkPerm($session_id, 'client_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../client/form/client.tform.php', $client_id);

		return $affected_rows;
	}

	// Website functions ---------------------------------------------------------------------------------------

	//* Get cron details
	public function sites_cron_get($session_id, $cron_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_cron_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/cron.tform.php');
		return $app->remoting_lib->getDataRecord($cron_id);
	}

	//* Add a cron record
	public function sites_cron_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_cron_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../sites/form/cron.tform.php', $client_id, $params);
	}

	//* Update cron record
	public function sites_cron_update($session_id, $client_id, $cron_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_cron_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../sites/form/cron.tform.php', $client_id, $cron_id, $params);
		return $affected_rows;
	}

	//* Delete cron record
	public function sites_cron_delete($session_id, $cron_id)
	{
		if(!$this->checkPerm($session_id, 'sites_cron_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../sites/form/cron.tform.php', $cron_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------

	//* Get record details
	public function sites_database_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_database_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/database.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function sites_database_add($session_id, $client_id, $params)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_database_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		//* Check for duplicates
		$tmp = $app->db->queryOneRecord("SELECT count(database_id) as dbnum FROM web_database WHERE database_name = '".$app->db->quote($params['database_name'])."' AND server_id = '".intval($params["server_id"])."'");
		if($tmp['dbnum'] > 0) {
			$this->server->fault('database_name_error_unique', 'There is already a database with that name on the same server.');
			return false;
		}

		$sql = $this->insertQueryPrepare('../sites/form/database.tform.php', $client_id, $params);
		if($sql !== false) {
			$app->uses('sites_database_plugin');

			$this->id = 0;
			$this->dataRecord = $params;
			$app->sites_database_plugin->processDatabaseInsert($this);

			$retval = $this->insertQueryExecute($sql, $params);
			
			// set correct values for backup_interval and backup_copies
			if(isset($params['backup_interval']) || isset($params['backup_copies'])){
				$sql_set = array();
				if(isset($params['backup_interval'])) $sql_set[] = "backup_interval = '".$app->db->quote($params['backup_interval'])."'";
				if(isset($params['backup_copies'])) $sql_set[] = "backup_copies = ".$app->functions->intval($params['backup_copies']);
				//$app->db->query("UPDATE web_database SET ".implode(', ', $sql_set)." WHERE database_id = ".$retval);
				$this->updateQueryExecute("UPDATE web_database SET ".implode(', ', $sql_set)." WHERE database_id = ".$retval, $retval, $params);
			}
			
			return $retval;
		}

		return false;
	}

	//* Update a record
	public function sites_database_update($session_id, $client_id, $primary_id, $params)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_database_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$sql = $this->updateQueryPrepare('../sites/form/database.tform.php', $client_id, $primary_id, $params);
		if($sql !== false) {
			$app->uses('sites_database_plugin');

			$this->id = $primary_id;
			$this->dataRecord = $params;
			$app->sites_database_plugin->processDatabaseUpdate($this);
			$retval = $this->updateQueryExecute($sql, $primary_id, $params);
			
			// set correct values for backup_interval and backup_copies
			if(isset($params['backup_interval']) || isset($params['backup_copies'])){
				$sql_set = array();
				if(isset($params['backup_interval'])) $sql_set[] = "backup_interval = '".$app->db->quote($params['backup_interval'])."'";
				if(isset($params['backup_copies'])) $sql_set[] = "backup_copies = ".$app->functions->intval($params['backup_copies']);
				//$app->db->query("UPDATE web_database SET ".implode(', ', $sql_set)." WHERE database_id = ".$primary_id);
				$this->updateQueryExecute("UPDATE web_database SET ".implode(', ', $sql_set)." WHERE database_id = ".$primary_id, $primary_id, $params);
			}
			
			return $retval;
		}

		return false;
	}

	//* Delete a record
	public function sites_database_delete($session_id, $primary_id)
	{
		global $app;
		if(!$this->checkPerm($session_id, 'sites_database_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$app->uses('sites_database_plugin');
		$app->sites_database_plugin->processDatabaseDelete($primary_id);

		$affected_rows = $this->deleteQuery('../sites/form/database.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------

	//* Get record details
	public function sites_database_user_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_database_user_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/database_user.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function sites_database_user_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_database_user_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		return $this->insertQuery('../sites/form/database_user.tform.php', $client_id, $params);
	}

	//* Update a record
	public function sites_database_user_update($session_id, $client_id, $primary_id, $params)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_database_user_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/database_user.tform.php');
		$old_rec = $app->remoting_lib->getDataRecord($primary_id);

		$result = $this->updateQuery('../sites/form/database_user.tform.php', $client_id, $primary_id, $params);

		$new_rec = $app->remoting_lib->getDataRecord($primary_id);

		$records = $app->db->queryAllRecords("SELECT DISTINCT server_id FROM web_database WHERE database_user_id = '".$app->functions->intval($primary_id)."' UNION SELECT DISTINCT server_id FROM web_database WHERE database_ro_user_id = '".$app->functions->intval($primary_id)."'");
		foreach($records as $rec) {
			$tmp_rec = $new_rec;
			$tmp_rec['server_id'] = $rec['server_id'];
			$app->remoting_lib->datalogSave('UPDATE', $primary_id, $old_rec, $tmp_rec);
		}
		unset($new_rec);
		unset($old_rec);
		unset($records);

		return $result;
	}

	//* Delete a record
	public function sites_database_user_delete($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_database_user_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$app->db->datalogDelete('web_database_user', 'database_user_id', $primary_id);
		$affected_rows = $this->deleteQuery('../sites/form/database_user.tform.php', $primary_id);

		$records = $app->db->queryAllRecords("SELECT database_id FROM web_database WHERE database_user_id = '".$app->functions->intval($primary_id)."'");
		foreach($records as $rec) {
			$app->db->datalogUpdate('web_database', 'database_user_id=NULL', 'database_id', $rec['database_id']);

		}
		$records = $app->db->queryAllRecords("SELECT database_id FROM web_database WHERE database_ro_user_id = '".$app->functions->intval($primary_id)."'");
		foreach($records as $rec) {
			$app->db->datalogUpdate('web_database', 'database_ro_user_id=NULL', 'database_id', $rec['database_id']);
		}

		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------

	//* Get record details
	public function sites_ftp_user_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_ftp_user_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/ftp_user.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function sites_ftp_user_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_ftp_user_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../sites/form/ftp_user.tform.php', $client_id, $params);
	}

	//* Update a record
	public function sites_ftp_user_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_ftp_user_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../sites/form/ftp_user.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function sites_ftp_user_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'sites_ftp_user_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../sites/form/ftp_user.tform.php', $primary_id);
		return $affected_rows;
	}

	//* Get server for an ftp user
	public function sites_ftp_user_server_get($session_id, $ftp_user)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_ftp_user_server_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$data = $app->db->queryOneRecord("SELECT server_id FROM ftp_user WHERE username = '".$app->db->quote($ftp_user)."'");
		//file_put_contents('/tmp/test.txt', serialize($data));
		if(!isset($data['server_id'])) return false;

		$server = $this->server_get($session_id, $data['server_id'], 'server');
		//file_put_contents('/tmp/test2.txt', serialize($server));

		return $server;
	}

	// ----------------------------------------------------------------------------------------------------------

	//* Get record details
	public function sites_shell_user_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_shell_user_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/shell_user.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function sites_shell_user_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_shell_user_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../sites/form/shell_user.tform.php', $client_id, $params);
	}

	//* Update a record
	public function sites_shell_user_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_shell_user_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../sites/form/shell_user.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function sites_shell_user_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'sites_shell_user_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../sites/form/shell_user.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------

	//* Get record details
	public function sites_web_domain_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_web_domain_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/web_domain.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function sites_web_domain_add($session_id, $client_id, $params, $readonly = false)
	{
		global $app;
		if(!$this->checkPerm($session_id, 'sites_web_domain_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		if(!isset($params['client_group_id']) or (isset($params['client_group_id']) && empty($params['client_group_id']))) {
			$rec = $app->db->queryOneRecord("SELECT groupid FROM sys_group WHERE client_id = ".$app->functions->intval($client_id));
			$params['client_group_id'] = $rec['groupid'];
		}

		//* Set a few params to "not empty" values which get overwritten by the sites_web_domain_plugin
		if($params['document_root'] == '') $params['document_root'] = '-';
		if($params['system_user'] == '') $params['system_user'] = '-';
		if($params['system_group'] == '') $params['system_group'] = '-';

		//* Set a few defaults for nginx servers
		if($params['pm_max_children'] == '') $params['pm_max_children'] = 1;
		if($params['pm_start_servers'] == '') $params['pm_start_servers'] = 1;
		if($params['pm_min_spare_servers'] == '') $params['pm_min_spare_servers'] = 1;
		if($params['pm_max_spare_servers'] == '') $params['pm_max_spare_servers'] = 1;

		$domain_id = $this->insertQuery('../sites/form/web_domain.tform.php', $client_id, $params, 'sites:web_domain:on_after_insert');
		if ($readonly === true)
			$app->db->query("UPDATE web_domain SET `sys_userid` = '1' WHERE domain_id = ".$domain_id);
		return $domain_id;
	}

	//* Update a record
	public function sites_web_domain_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_web_domain_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		//* Set a few defaults for nginx servers
		if($params['pm_max_children'] == '') $params['pm_max_children'] = 1;
		if($params['pm_start_servers'] == '') $params['pm_start_servers'] = 1;
		if($params['pm_min_spare_servers'] == '') $params['pm_min_spare_servers'] = 1;
		if($params['pm_max_spare_servers'] == '') $params['pm_max_spare_servers'] = 1;

		$affected_rows = $this->updateQuery('../sites/form/web_domain.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function sites_web_domain_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'sites_web_domain_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../sites/form/web_domain.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------

	//* Get record details
	public function sites_web_vhost_subdomain_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_web_subdomain_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/web_vhost_subdomain.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function sites_web_vhost_subdomain_add($session_id, $client_id, $params)
	{
		global $app;
		if(!$this->checkPerm($session_id, 'sites_web_subdomain_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		//* Set a few params to "not empty" values which get overwritten by the sites_web_domain_plugin
		if($params['document_root'] == '') $params['document_root'] = '-';
		if($params['system_user'] == '') $params['system_user'] = '-';
		if($params['system_group'] == '') $params['system_group'] = '-';

		//* Set a few defaults for nginx servers
		if($params['pm_max_children'] == '') $params['pm_max_children'] = 1;
		if($params['pm_start_servers'] == '') $params['pm_start_servers'] = 1;
		if($params['pm_min_spare_servers'] == '') $params['pm_min_spare_servers'] = 1;
		if($params['pm_max_spare_servers'] == '') $params['pm_max_spare_servers'] = 1;

		$domain_id = $this->insertQuery('../sites/form/web_vhost_subdomain.tform.php', $client_id, $params, 'sites:web_vhost_subdomain:on_after_insert');
		return $domain_id;
	}

	//* Update a record
	public function sites_web_vhost_subdomain_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_web_subdomain_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		//* Set a few defaults for nginx servers
		if($params['pm_max_children'] == '') $params['pm_max_children'] = 1;
		if($params['pm_start_servers'] == '') $params['pm_start_servers'] = 1;
		if($params['pm_min_spare_servers'] == '') $params['pm_min_spare_servers'] = 1;
		if($params['pm_max_spare_servers'] == '') $params['pm_max_spare_servers'] = 1;

		$affected_rows = $this->updateQuery('../sites/form/web_vhost_subdomain.tform.php', $client_id, $primary_id, $params, 'sites:web_vhost_subdomain:on_after_insert');
		return $affected_rows;
	}

	//* Delete a record
	public function sites_web_vhost_subdomain_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'sites_web_subdomain_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../sites/form/web_vhost_subdomain.tform.php', $primary_id);
		return $affected_rows;
	}

	// -----------------------------------------------------------------------------------------------

	//* Get record details
	public function sites_web_aliasdomain_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_web_aliasdomain_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/web_aliasdomain.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function sites_web_aliasdomain_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_web_aliasdomain_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../sites/form/web_aliasdomain.tform.php', $client_id, $params);
	}

	//* Update a record
	public function sites_web_aliasdomain_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_web_aliasdomain_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../sites/form/web_aliasdomain.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function sites_web_aliasdomain_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'sites_web_aliasdomain_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../sites/form/web_aliasdomain.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------

	//* Get record details
	public function sites_web_subdomain_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_web_subdomain_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/web_subdomain.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function sites_web_subdomain_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_web_subdomain_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../sites/form/web_subdomain.tform.php', $client_id, $params);
	}

	//* Update a record
	public function sites_web_subdomain_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_web_subdomain_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../sites/form/web_subdomain.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function sites_web_subdomain_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'sites_web_subdomain_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../sites/form/web_subdomain.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------

	//* Get record details
	public function sites_web_folder_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_web_folder_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/web_folder.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function sites_web_folder_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_web_folder_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../sites/form/web_folder.tform.php', $client_id, $params);
	}

	//* Update a record
	public function sites_web_folder_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_web_folder_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../sites/form/web_folder.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function sites_web_folder_delete($session_id, $primary_id)
	{
		global $app;
		if(!$this->checkPerm($session_id, 'sites_web_folder_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		// Delete all users that belong to this folder. - taken from web_folder_delete.php
		$records = $app->db->queryAllRecords("SELECT web_folder_user_id FROM web_folder_user WHERE web_folder_id = '".$app->functions->intval($primary_id)."'");
		foreach($records as $rec) {
			$this->deleteQuery('../sites/form/web_folder_user.tform.php', $rec['web_folder_user_id']);
			//$app->db->datalogDelete('web_folder_user','web_folder_user_id',$rec['web_folder_user_id']);
		}
		unset($records);

		$affected_rows = $this->deleteQuery('../sites/form/web_folder.tform.php', $primary_id);
		return $affected_rows;
	}

	// -----------------------------------------------------------------------------------------------

	//* Get record details
	public function sites_web_folder_user_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'sites_web_folder_user_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../sites/form/web_folder_user.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function sites_web_folder_user_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_web_folder_user_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../sites/form/web_folder_user.tform.php', $client_id, $params);
	}

	//* Update a record
	public function sites_web_folder_user_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'sites_web_folder_user_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../sites/form/web_folder_user.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function sites_web_folder_user_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'sites_web_folder_user_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../sites/form/web_folder_user.tform.php', $primary_id);
		return $affected_rows;
	}

	// -----------------------------------------------------------------------------------------------

	//* Get record details
	public function domains_domain_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'domains_domain_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../client/form/domain.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function domains_domain_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'domains_domain_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../client/form/domain.tform.php', $client_id, $params);
	}

	//* Delete a record
	public function domains_domain_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'domains_domain_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../client/form/domain.tform.php', $primary_id);
		return $affected_rows;
	}

	// -----------------------------------------------------------------------------------------------

	public function domains_get_all_by_user($session_id, $group_id)
	{
		global $app;
		if(!$this->checkPerm($session_id, 'domains_get_all_by_user')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$group_id = $app->functions->intval($group_id);
		$sql = "SELECT domain_id, domain FROM domain WHERE sys_groupid  = $group_id ";
		$all = $app->db->queryAllRecords($sql);
		return $all;
	}


	// DNS Function --------------------------------------------------------------------------------------------------

	//* Create Zone with Template
	public function dns_templatezone_add($session_id, $client_id, $template_id, $domain, $ip, $ns1, $ns2, $email)
	{
		global $app, $conf;
		if(!$this->checkPerm($session_id, 'dns_templatezone_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$client = $app->db->queryOneRecord("SELECT default_dnsserver FROM client WHERE client_id = ".$app->functions->intval($client_id));
		$server_id = $client["default_dnsserver"];
		$template_record = $app->db->queryOneRecord("SELECT * FROM dns_template WHERE template_id = '$template_id'");
		$fields = explode(',', $template_record['fields']);
		$tform_def_file = "../../web/dns/form/dns_soa.tform.php";
		$app->uses('tform');
		$app->tform->loadFormDef($tform_def_file);
		$app->uses('tpl,validate_dns');

		//* replace template placeholders
		$tpl_content = $template_record['template'];
		if($domain != '') $tpl_content = str_replace('{DOMAIN}', $domain, $tpl_content);
		if($ip != '') $tpl_content = str_replace('{IP}', $ip, $tpl_content);
		if($ns1 != '') $tpl_content = str_replace('{NS1}', $ns1, $tpl_content);
		if($ns2 != '') $tpl_content = str_replace('{NS2}', $ns2, $tpl_content);
		if($email != '') $tpl_content = str_replace('{EMAIL}', $email, $tpl_content);

		//* Parse the template
		$tpl_rows = explode("\n", $tpl_content);
		$section = '';
		$vars = array();
		$dns_rr = array();
		foreach($tpl_rows as $row) {
			$row = trim($row);
			if(substr($row, 0, 1) == '[') {
				if($row == '[ZONE]') {
					$section = 'zone';
				} elseif($row == '[DNS_RECORDS]') {
					$section = 'dns_records';
				} else {
					die('Unknown section type');
				}
			} else {
				if($row != '') {
					//* Handle zone section
					if($section == 'zone') {
						$parts = explode('=', $row);
						$key = trim($parts[0]);
						$val = trim($parts[1]);
						if($key != '') $vars[$key] = $val;
					}
					//* Handle DNS Record rows
					if($section == 'dns_records') {
						$parts = explode('|', $row);
						$dns_rr[] = array(
							'name' => $app->db->quote($parts[1]),
							'type' => $app->db->quote($parts[0]),
							'data' => $app->db->quote($parts[2]),
							'aux'  => $app->db->quote($parts[3]),
							'ttl'  => $app->db->quote($parts[4])
						);
					}
				}
			}
		} // end foreach

		if($vars['origin'] == '') $error .= $app->lng('error_origin_empty').'<br />';
		if($vars['ns'] == '') $error .= $app->lng('error_ns_empty').'<br />';
		if($vars['mbox'] == '') $error .= $app->lng('error_mbox_empty').'<br />';
		if($vars['refresh'] == '') $error .= $app->lng('error_refresh_empty').'<br />';
		if($vars['retry'] == '') $error .= $app->lng('error_retry_empty').'<br />';
		if($vars['expire'] == '') $error .= $app->lng('error_expire_empty').'<br />';
		if($vars['minimum'] == '') $error .= $app->lng('error_minimum_empty').'<br />';
		if($vars['ttl'] == '') $error .= $app->lng('error_ttl_empty').'<br />';

		if($error == '') {
			// Insert the soa record
			$tmp = $app->db->queryOneRecord("SELECT userid,default_group FROM sys_user WHERE client_id = ".$app->functions->intval($client_id));
			$sys_userid = $tmp['userid'];
			$sys_groupid = $tmp['default_group'];
			unset($tmp);
			$origin = $app->db->quote($vars['origin']);
			$ns = $app->db->quote($vars['ns']);
			$mbox = $app->db->quote(str_replace('@', '.', $vars['mbox']));
			$refresh = $app->db->quote($vars['refresh']);
			$retry = $app->db->quote($vars['retry']);
			$expire = $app->db->quote($vars['expire']);
			$minimum = $app->db->quote($vars['minimum']);
			$ttl = $app->db->quote($vars['ttl']);
			$xfer = $app->db->quote($vars['xfer']);
			$also_notify = $app->db->quote($vars['also_notify']);
			$update_acl = $app->db->quote($vars['update_acl']);
			$serial = $app->validate_dns->increase_serial(0);
			$insert_data = "(`sys_userid`, `sys_groupid`, `sys_perm_user`, `sys_perm_group`, `sys_perm_other`, `server_id`, `origin`, `ns`, `mbox`, `serial`, `refresh`, `retry`, `expire`, `minimum`, `ttl`, `active`, `xfer`, `also_notify`, `update_acl`) VALUES
			('$sys_userid', '$sys_groupid', 'riud', 'riud', '', '$server_id', '$origin', '$ns', '$mbox', '$serial', '$refresh', '$retry', '$expire', '$minimum', '$ttl', 'Y', '$xfer', '$also_notify', '$update_acl')";
			$dns_soa_id = $app->db->datalogInsert('dns_soa', $insert_data, 'id');
			// Insert the dns_rr records
			if(is_array($dns_rr) && $dns_soa_id > 0) {
				foreach($dns_rr as $rr) {
					$insert_data = "(`sys_userid`, `sys_groupid`, `sys_perm_user`, `sys_perm_group`, `sys_perm_other`, `server_id`, `zone`, `name`, `type`, `data`, `aux`, `ttl`, `active`) VALUES
					('$sys_userid', '$sys_groupid', 'riud', 'riud', '', '$server_id', '$dns_soa_id', '$rr[name]', '$rr[type]', '$rr[data]', '$rr[aux]', '$rr[ttl]', 'Y')";
					$dns_rr_id = $app->db->datalogInsert('dns_rr', $insert_data, 'id');
				}
			}
			exit;
		} else {
			$this->server->fault('permission_denied', $error);
		}
	}


	//* Get record details
	public function dns_zone_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_zone_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_soa.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Get record id by origin
	public function dns_zone_get_id($session_id, $origin)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_zone_get_id')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		if(!preg_match('/^[a-z0-9][a-z0-9\-]+[a-z0-9](\.[a-z]{2,4})+$/i', $origin)){
			$this->server->fault('no_domain_found', 'Invalid domain name.');
			return false;
		}

		$rec = $app->db->queryOneRecord("SELECT id FROM dns_soa WHERE origin like '".$origin."%'");
		if(isset($rec['id'])) {
			return $app->functions->intval($rec['id']);
		} else {
			$this->server->fault('no_domain_found', 'There is no domain ID with informed domain name.');
			return false;
		}
	}

	//* Add a record
	public function dns_zone_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_zone_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_soa.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_zone_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_zone_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_soa.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_zone_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_zone_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_soa.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------------

	//* Get record details
	public function dns_aaaa_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_aaaa_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_aaaa.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function dns_aaaa_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_aaaa_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_aaaa.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_aaaa_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_aaaa_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_aaaa.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_aaaa_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_aaaa_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_aaaa.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------------

	//* Get record details
	public function dns_a_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_a_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_a.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function dns_a_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_a_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_a.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_a_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_a_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_a.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_a_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_a_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_a.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------------

	//* Get record details
	public function dns_alias_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_alias_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_alias.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function dns_alias_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_alias_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_alias.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_alias_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_alias_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_alias.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_alias_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_alias_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_alias.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------------

	//* Get record details
	public function dns_cname_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_cname_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_cname.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function dns_cname_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_cname_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_cname.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_cname_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_cname_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_cname.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_cname_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_cname_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_cname.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------------

	//* Get record details
	public function dns_hinfo_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_hinfo_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_hinfo.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function dns_hinfo_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_hinfo_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_hinfo.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_hinfo_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_hinfo_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_hinfo.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_hinfo_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_hinfo_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_hinfo.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------------

	//* Get record details
	public function dns_mx_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_mx_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_mx.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function dns_mx_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_mx_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_mx.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_mx_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_mx_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_mx.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_mx_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_mx_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_mx.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------------

	//* Get record details
	public function dns_ns_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_ns_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_ns.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function dns_ns_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_ns_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_ns.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_ns_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_ns_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_ns.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_ns_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_ns_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_ns.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------------

	//* Get record details
	public function dns_ptr_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_ptr_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_ptr.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function dns_ptr_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_ptr_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_ptr.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_ptr_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_ptr_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_ptr.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_ptr_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_ptr_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_ptr.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------------

	//* Get record details
	public function dns_rp_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_rp_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_rp.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function dns_rp_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_rp_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_rp.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_rp_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_rp_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_rp.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_rp_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_rp_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_rp.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------------

	//* Get record details
	public function dns_srv_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_srv_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_srv.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function dns_srv_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_srv_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_srv.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_srv_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_srv_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_srv.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_srv_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_srv_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_srv.tform.php', $primary_id);
		return $affected_rows;
	}

	// ----------------------------------------------------------------------------------------------------------------

	//* Get record details
	public function dns_txt_get($session_id, $primary_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'dns_txt_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../dns/form/dns_txt.tform.php');
		return $app->remoting_lib->getDataRecord($primary_id);
	}

	//* Add a record
	public function dns_txt_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_txt_add')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../dns/form/dns_txt.tform.php', $client_id, $params);
	}

	//* Update a record
	public function dns_txt_update($session_id, $client_id, $primary_id, $params)
	{
		if(!$this->checkPerm($session_id, 'dns_txt_update')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../dns/form/dns_txt.tform.php', $client_id, $primary_id, $params);
		return $affected_rows;
	}

	//* Delete a record
	public function dns_txt_delete($session_id, $primary_id)
	{
		if(!$this->checkPerm($session_id, 'dns_txt_delete')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../dns/form/dns_txt.tform.php', $primary_id);
		return $affected_rows;
	}













	//** protected functions -----------------------------------------------------------------------------------




	protected function klientadd($formdef_file, $reseller_id, $params)
	{
		global $app;
		$app->uses('remoting_lib');

		//* Load the form definition
		$app->remoting_lib->loadFormDef($formdef_file);

		//* load the user profile of the client
		$app->remoting_lib->loadUserProfile($reseller_id);

		//* Get the SQL query
		$sql = $app->remoting_lib->getSQL($params, 'INSERT', 0);

		//* Check if no system user with that username exists
		$username = $app->db->quote($params["username"]);
		$tmp = $app->db->queryOneRecord("SELECT count(userid) as number FROM sys_user WHERE username = '$username'");
		if($tmp['number'] > 0) $app->remoting_lib->errorMessage .= "Duplicate username<br />";

		//* Stop on error while preparing the sql query
		if($app->remoting_lib->errorMessage != '') {
			$this->server->fault('data_processing_error', $app->remoting_lib->errorMessage);
			return false;
		}

		//* Execute the SQL query
		$app->db->query($sql);
		$insert_id = $app->db->insertID();


		//* Stop on error while executing the sql query
		if($app->remoting_lib->errorMessage != '') {
			$this->server->fault('data_processing_error', $app->remoting_lib->errorMessage);
			return false;
		}

		$this->id = $insert_id;
		$this->dataRecord = $params;

		$app->plugin->raiseEvent('client:' . (isset($params['limit_client']) && $params['limit_client'] != 0 ? 'reseller' : 'client') . ':on_after_insert', $this);

		/*
		if($app->db->errorMessage != '') {
			$this->server->fault('database_error', $app->db->errorMessage . ' '.$sql);
			return false;
		}
		*/

		/* copied from the client_edit php */
		exec('ssh-keygen -t rsa -C '.$username.'-rsa-key-'.time().' -f /tmp/id_rsa -N ""');
		$app->db->query("UPDATE client SET created_at = ".time().", id_rsa = '".$app->db->quote(@file_get_contents('/tmp/id_rsa'))."', ssh_rsa = '".$app->db->quote(@file_get_contents('/tmp/id_rsa.pub'))."' WHERE client_id = ".$this->id);
		exec('rm -f /tmp/id_rsa /tmp/id_rsa.pub');



		//$app->uses('tform');
		//* Save changes to Datalog
		if($app->remoting_lib->formDef["db_history"] == 'yes') {
			$new_rec = $app->remoting_lib->getDataRecord($insert_id);
			$app->remoting_lib->datalogSave('INSERT', $primary_id, array(), $new_rec);
			$app->remoting_lib->ispconfig_sysuser_add($params, $insert_id);

			if($reseller_id) {
				$client_group = $app->db->queryOneRecord("SELECT * FROM sys_group WHERE client_id = ".$insert_id);
				$reseller_user = $app->db->queryOneRecord("SELECT * FROM sys_user WHERE client_id = ".$reseller_id);
				$app->auth->add_group_to_user($reseller_user['userid'], $client_group['groupid']);
				$app->db->query("UPDATE client SET parent_client_id = ".$reseller_id." WHERE client_id = ".$insert_id);
			}

		}
		return $insert_id;
	}

	protected function insertQuery($formdef_file, $client_id, $params, $event_identifier = '')
	{
		$sql = $this->insertQueryPrepare($formdef_file, $client_id, $params);
		if($sql !== false) return $this->insertQueryExecute($sql, $params, $event_identifier);
		else return false;
	}

	protected function insertQueryPrepare($formdef_file, $client_id, $params)
	{
		global $app;

		$app->uses('remoting_lib');

		//* load the user profile of the client
		$app->remoting_lib->loadUserProfile($client_id);

		//* Load the form definition
		$app->remoting_lib->loadFormDef($formdef_file);

		//* Get the SQL query
		$sql = $app->remoting_lib->getSQL($params, 'INSERT', 0);
		if($app->remoting_lib->errorMessage != '') {
			$this->server->fault('data_processing_error', $app->remoting_lib->errorMessage);
			return false;
		}
		$app->log('Executed insertQueryPrepare', LOGLEVEL_DEBUG);
		return $sql;
	}

	protected function insertQueryExecute($sql, $params, $event_identifier = '')
	{
		global $app;

		$app->uses('remoting_lib');

		$app->db->query($sql);

		if($app->db->errorMessage != '') {
			$this->server->fault('database_error', $app->db->errorMessage . ' '.$sql);
			return false;
		}

		$insert_id = $app->db->insertID();

		// set a few values for compatibility with tform actions, mostly used by plugins
		$this->id = $insert_id;
		$this->dataRecord = $params;
		$app->log('Executed insertQueryExecute, raising events now if any: ' . $event_identifier, LOGLEVEL_DEBUG);
		if($event_identifier != '') $app->plugin->raiseEvent($event_identifier, $this);

		//$app->uses('tform');
		//* Save changes to Datalog
		if($app->remoting_lib->formDef["db_history"] == 'yes') {
			$new_rec = $app->remoting_lib->getDataRecord($insert_id);
			$app->remoting_lib->datalogSave('INSERT', $primary_id, array(), $new_rec);
		}
		return $insert_id;
	}

	protected function updateQuery($formdef_file, $client_id, $primary_id, $params, $event_identifier = '')
	{
		global $app;

		$sql = $this->updateQueryPrepare($formdef_file, $client_id, $primary_id, $params);
		if($sql !== false) return $this->updateQueryExecute($sql, $primary_id, $params, $event_identifier);
		else return false;
	}

	protected function updateQueryPrepare($formdef_file, $client_id, $primary_id, $params)
	{
		global $app;

		$app->uses('remoting_lib');

		//* load the user profile of the client
		$app->remoting_lib->loadUserProfile($client_id);

		//* Load the form definition
		$app->remoting_lib->loadFormDef($formdef_file);

		//* Get the SQL query
		$sql = $app->remoting_lib->getSQL($params, 'UPDATE', $primary_id);
		// $this->server->fault('debug', $sql);
		if($app->remoting_lib->errorMessage != '') {
			$this->server->fault('data_processing_error', $app->remoting_lib->errorMessage);
			return false;
		}

		return $sql;
	}

	protected function updateQueryExecute($sql, $primary_id, $params, $event_identifier = '')
	{
		global $app;

		$app->uses('remoting_lib');

		$old_rec = $app->remoting_lib->getDataRecord($primary_id);

		// set a few values for compatibility with tform actions, mostly used by plugins
		$this->oldDataRecord = $old_rec;
		$this->id = $primary_id;
		$this->dataRecord = $params;

		$app->db->query($sql);

		if($app->db->errorMessage != '') {
			$this->server->fault('database_error', $app->db->errorMessage . ' '.$sql);
			return false;
		}

		$affected_rows = $app->db->affectedRows();
		$app->log('Executed updateQueryExecute, raising events now if any: ' . $event_identifier, LOGLEVEL_DEBUG);

		if($event_identifier != '') $app->plugin->raiseEvent($event_identifier, $this);

		//* Save changes to Datalog
		if($app->remoting_lib->formDef["db_history"] == 'yes') {
			$new_rec = $app->remoting_lib->getDataRecord($primary_id);
			$app->remoting_lib->datalogSave('UPDATE', $primary_id, $old_rec, $new_rec);
		}

		return $affected_rows;
	}

	protected function deleteQuery($formdef_file, $primary_id, $event_identifier = '')
	{
		global $app;

		$app->uses('remoting_lib');

		//* load the user profile of the client
		$app->remoting_lib->loadUserProfile(0);

		//* Load the form definition
		$app->remoting_lib->loadFormDef($formdef_file);

		$old_rec = $app->remoting_lib->getDataRecord($primary_id);

		// set a few values for compatibility with tform actions, mostly used by plugins
		$this->oldDataRecord = $old_rec;
		$this->id = $primary_id;
		$this->dataRecord = $old_rec;
		$app->log('Executed deleteQuery, raising events now if any: ' . $event_identifier, LOGLEVEL_DEBUG);
		//$this->dataRecord = $params;

		//* Get the SQL query
		$sql = $app->remoting_lib->getDeleteSQL($primary_id);
		$app->db->errorMessage = '';
		$app->db->query($sql);
		$affected_rows = $app->db->affectedRows();

		if($app->db->errorMessage != '') {
			$this->server->fault('database_error', $app->db->errorMessage . ' '.$sql);
			return false;
		}

		if($event_identifier != '') {
			$app->plugin->raiseEvent($event_identifier, $this);
		}

		//* Save changes to Datalog
		if($app->remoting_lib->formDef["db_history"] == 'yes') {
			$app->remoting_lib->datalogSave('DELETE', $primary_id, $old_rec, array());
		}


		return $affected_rows;
	}


	protected function checkPerm($session_id, $function_name)
	{
		global $app;
		$dobre=array();
		$session = $this->getSession($session_id);
		if(!$session){
			return false;
		}

		$dobre= str_replace(';', ',', $session['remote_functions']);
		$check = in_array($function_name, explode(',', $dobre) );
		if(!$check) {
			$app->log("REMOTE-LIB DENY: ".$session_id ." /". $function_name, LOGLEVEL_WARN);
		}
		return $check;
	}


	protected function getSession($session_id)
	{
		global $app;

		if(empty($session_id)) {
			$this->server->fault('session_id_empty', 'The SessionID is empty.');
			return false;
		}

		$session_id = $app->db->quote($session_id);

		$now = time();
		$sql = "SELECT * FROM remote_session WHERE remote_session = '$session_id' AND tstamp >= $now";
		$session = $app->db->queryOneRecord($sql);
		if($session['remote_userid'] > 0) {
			return $session;
		} else {
			$this->server->fault('session_does_not_exist', 'The Session is expired or does not exist.');
			return false;
		}
	}

	//---


	/**
	 * Gets sites by $sys_userid & $sys_groupid
	 * @param int  session id
	 * @param int  user id
	 * @param array list of groups
	 * @return mixed array with sites by user
	 * @author Julio Montoya <gugli100@gmail.com> BeezNest 2010
	 */
	public function client_get_sites_by_user($session_id, $sys_userid, $sys_groupid) {
		global $app;
		if(!$this->checkPerm($session_id, 'client_get_sites_by_user')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$sys_userid  = $app->functions->intval($sys_userid);
		$sys_groupid = explode(',', $sys_groupid);
		$new_group = array();
		foreach($sys_groupid as $group_id) {
			$new_group[] = $app->functions->intval( $group_id);
		}
		$group_list = implode(',', $new_group);
		$sql ="SELECT domain, domain_id, document_root, active FROM web_domain WHERE ( (sys_userid = $sys_userid  AND sys_perm_user LIKE '%r%') OR (sys_groupid IN ($group_list) AND sys_perm_group LIKE '%r%') OR  sys_perm_other LIKE '%r%') AND type = 'vhost'";
		$result = $app->db->queryAllRecords($sql);
		if(isset($result)) {
			return $result;
		} else {
			$this->server->fault('no_client_found', 'There is no site for this user');
			return false;
		}
	}



	/**
	 * Change domains status
	 * @param int  session id
	 * @param int  site id
	 * @param string active or inactive string
	 * @return mixed false if error
	 * @author Julio Montoya <gugli100@gmail.com> BeezNest 2010
	 */
	public function sites_web_domain_set_status($session_id, $primary_id, $status) {
		global $app;
		if(!$this->checkPerm($session_id, 'sites_web_domain_set_status')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		
		if(in_array($status, array('active', 'inactive'))) {
			if ($status == 'active') {
				$status = 'y';
			} else {
				$status = 'n';
			}
			$app->remoting_lib->loadFormDef('../sites/form/web_domain.tform.php');
			$params = $app->remoting_lib->getDataRecord($primary_id);
			$params['active'] = $status;
			
			$affected_rows = $this->updateQuery('../sites/form/web_domain.tform.php', 0, $primary_id, $params);
			return $affected_rows;
		} else {
			$this->server->fault('status_undefined', 'The status is not available');
			return false;
		}
	}



	/**
	 * Get sys_user information by username
	 * @param int  session id
	 * @param string user's name
	 * @return mixed false if error
	 * @author Julio Montoya <gugli100@gmail.com> BeezNest 2010
	 */
	public function client_get_by_username($session_id, $username) {
		global $app;
		if(!$this->checkPerm($session_id, 'client_get_by_username')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$username = $app->db->quote($username);
		$rec = $app->db->queryOneRecord("SELECT * FROM sys_user WHERE username = '".$username."'");
		if (isset($rec)) {
			return $rec;
		} else {
			$this->server->fault('no_client_found', 'There is no user account for this user name.');
			return false;
		}
	}

	/**
	 * Get All client_id's from database
	 * @param int session_id
	 * @return Array of all client_id's
	 */
	public function client_get_all($session_id) {
		global $app;
		if(!$this->checkPerm($session_id, 'client_get_all')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$result = $app->db->queryAllRecords("SELECT client_id FROM client WHERE 1");
		if(!$result) {
			return false;
		}
		foreach( $result as $record) {
			$rarrary[] = $record['client_id'];
		}
		return $rarrary;
	}

	/**
	 * Changes client password
	 *
	 * @param int  session id
	 * @param int  client id
	 * @param string new password
	 * @return bool true if success
	 * @author Julio Montoya <gugli100@gmail.com> BeezNest 2010
	 *
	 */
	public function client_change_password($session_id, $client_id, $new_password) {
		global $app;

		if(!$this->checkPerm($session_id, 'client_change_password')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$client_id = $app->functions->intval($client_id);
		$client = $app->db->queryOneRecord("SELECT client_id FROM client WHERE client_id = ".$client_id);
		if($client['client_id'] > 0) {
			$new_password = $app->db->quote($new_password);
			$sql = "UPDATE client SET password = md5('".($new_password)."') 	WHERE client_id = ".$client_id;
			$app->db->query($sql);
			$sql = "UPDATE sys_user SET passwort = md5('".($new_password)."') 	WHERE client_id = ".$client_id;
			$app->db->query($sql);
			return true;
		} else {
			$this->server->fault('no_client_found', 'There is no user account for this client_id');
			return false;
		}
	}

	/**
	 * Fetch the mail_domain record for the provided domain.
	 * @param int session_id
	 * @param string the fully qualified domain (or subdomain)
	 * @return array array of arrays corresponding to the mail_domain table's records
	 * @author till, benlake
	 */
	public function mail_domain_get_by_domain($session_id, $domain) {
		global $app;
		if(!$this->checkPerm($session_id, 'mail_domain_get_by_domain')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		if (!empty($domain)) {
			$domain       = $app->db->quote($domain);
			$sql            = "SELECT * FROM mail_domain WHERE domain = '$domain'";
			$result         = $app->db->queryAllRecords($sql);
			return          $result;
		}
		return false;
	}

	/**
	 * Get a list of functions
	 * @param  int  session id
	 * @return mixed array of the available functions
	 * @author Julio Montoya <gugli100@gmail.com> BeezNest 2010
	 */
	public function get_function_list($session_id)
	{
		if(!$this->checkPerm($session_id, 'get_function_list')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return get_class_methods($this);
	}



	/**
	 * Get all databases by user
	 * @author Julio Montoya <gugli100@gmail.com> BeezNest 2010
	 */
	public function sites_database_get_all_by_user($session_id, $client_id)
	{
		global $app;
		if(!$this->checkPerm($session_id, 'sites_database_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$client_id = $app->functions->intval($client_id);
		$sql = "SELECT d.database_id, d.database_name, d.database_user_id, d.database_ro_user_id, du.database_user, du.database_password FROM web_database d LEFT JOIN web_database_user du ON (du.database_user_id = d.database_user_id) INNER JOIN sys_user s on(d.sys_groupid = s.default_group) WHERE client_id = $client_id";
		$all = $app->db->queryAllRecords($sql);
		return $all;
	}



	/**
	 *  Get all client templates
	 * @param  int  session id
	 * @author Julio Montoya <gugli100@gmail.com> BeezNest 2010
	 */
	public function client_templates_get_all($session_id) {
		global $app;
		if(!$this->checkPerm($session_id, 'client_templates_get_all')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$sql    = "SELECT * FROM client_template";
		$result = $app->db->queryAllRecords($sql);
		return $result;
	}



	/**
	 * Get all DNS zone by user
	 *@author Julio Montoya <gugli100@gmail.com> BeezNest 2010
	 */
	public function dns_zone_get_by_user($session_id, $client_id, $server_id) {
		global $app;
		if(!$this->checkPerm($session_id, 'dns_zone_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		if (!empty($client_id) && !empty($server_id)) {
			$server_id      = $app->functions->intval($server_id);
			$client_id      = $app->functions->intval($client_id);
			$sql            = "SELECT id, origin FROM dns_soa d INNER JOIN sys_user s on(d.sys_groupid = s.default_group) WHERE client_id = $client_id AND server_id = $server_id";
			$result         = $app->db->queryAllRecords($sql);
			return          $result;
		}
		return false;
	}



	/**
	 *  Get all dns records for a zone
	 * @param  int  session id
	 * @param  int  dns zone id
	 * @author Sebastian Mogilowski <sebastian@mogilowski.net> 2011
	 */
	public function dns_rr_get_all_by_zone($session_id, $zone_id) {
		global $app;
		if(!$this->checkPerm($session_id, 'dns_zone_get')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$sql    = "SELECT * FROM dns_rr WHERE zone = ".$app->functions->intval($zone_id);;
		$result = $app->db->queryAllRecords($sql);
		return $result;
	}

	/**
	 * Changes DNS zone status
	 * @param  int  session id
	 * @param int  dns soa id
	 * @param string status active or inactive string
	 * @author Julio Montoya <gugli100@gmail.com> BeezNest 2010
	 */
	public function dns_zone_set_status($session_id, $primary_id, $status) {
		global $app;
		if(!$this->checkPerm($session_id, 'dns_zone_set_status')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		if(in_array($status, array('active', 'inactive'))) {
			if ($status == 'active') {
				$status = 'Y';
			} else {
				$status = 'N';
			}
			$sql = "UPDATE dns_soa SET active = '$status' WHERE id = ".$app->functions->intval($primary_id);
			$app->db->query($sql);
			$result = $app->db->affectedRows();
			return $result;
		} else {
			$this->server->fault('status_undefined', 'The status is not available');
			return false;
		}
	}

	public function mail_domain_set_status($session_id, $primary_id, $status) {
		global $app;
		if(!$this->checkPerm($session_id, 'mail_domain_set_status')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		if(in_array($status, array('active', 'inactive'))) {
			if ($status == 'active') {
				$status = 'y';
			} else {
				$status = 'n';
			}
			$sql = "UPDATE mail_domain SET active = '$status' WHERE domain_id = ".$app->functions->intval($primary_id);
			$app->db->query($sql);
			$result = $app->db->affectedRows();
			return $result;
		} else {
			$this->server->fault('status_undefined', 'The status is not available');
			return false;
		}
	}

	//* Functions for virtual machine management

	//* Get OpenVZ OStemplate details
	public function openvz_ostemplate_get($session_id, $ostemplate_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../vm/form/openvz_ostemplate.tform.php');
		return $app->remoting_lib->getDataRecord($ostemplate_id);
	}

	//* Add a openvz ostemplate record
	public function openvz_ostemplate_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../vm/form/openvz_ostemplate.tform.php', $client_id, $params);
	}

	//* Update openvz ostemplate record
	public function openvz_ostemplate_update($session_id, $client_id, $ostemplate_id, $params)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../vm/form/openvz_ostemplate.tform.php', $client_id, $ostemplate_id, $params);
		return $affected_rows;
	}

	//* Delete openvz ostemplate record
	public function openvz_ostemplate_delete($session_id, $ostemplate_id)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../vm/form/openvz_ostemplate.tform.php', $ostemplate_id);
		return $affected_rows;
	}

	//* Get OpenVZ template details
	public function openvz_template_get($session_id, $template_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../vm/form/openvz_template.tform.php');
		return $app->remoting_lib->getDataRecord($template_id);
	}

	//* Add a openvz template record
	public function openvz_template_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../vm/form/openvz_template.tform.php', $client_id, $params);
	}

	//* Update openvz template record
	public function openvz_template_update($session_id, $client_id, $template_id, $params)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../vm/form/openvz_template.tform.php', $client_id, $template_id, $params);
		return $affected_rows;
	}

	//* Delete openvz template record
	public function openvz_template_delete($session_id, $template_id)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../vm/form/openvz_template.tform.php', $template_id);
		return $affected_rows;
	}

	//* Get OpenVZ ip details
	public function openvz_ip_get($session_id, $ip_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../vm/form/openvz_ip.tform.php');
		return $app->remoting_lib->getDataRecord($ip_id);
	}

	//* Get OpenVZ a free IP address
	public function openvz_get_free_ip($session_id, $server_id = 0)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$server_id = $app->functions->intval($server_id);

		if($server_id > 0) {
			$tmp = $app->db->queryOneRecord("SELECT ip_address_id, server_id, ip_address FROM openvz_ip WHERE reserved = 'n' AND vm_id = 0 AND server_id = $server_id LIMIT 0,1");
		} else {
			$tmp = $app->db->queryOneRecord("SELECT ip_address_id, server_id, ip_address FROM openvz_ip WHERE reserved = 'n' AND vm_id = 0 LIMIT 0,1");
		}

		if(count($tmp) > 0) {
			return $tmp;
		} else {
			$this->server->fault('no_free_ip', 'There is no free IP available.');
		}
	}

	//* Add a openvz ip record
	public function openvz_ip_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../vm/form/openvz_ip.tform.php', $client_id, $params);
	}

	//* Update openvz ip record
	public function openvz_ip_update($session_id, $client_id, $ip_id, $params)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../vm/form/openvz_ip.tform.php', $client_id, $ip_id, $params);
		return $affected_rows;
	}

	//* Delete openvz ip record
	public function openvz_ip_delete($session_id, $ip_id)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../vm/form/openvz_ip.tform.php', $ip_id);
		return $affected_rows;
	}

	//* Get OpenVZ vm details
	public function openvz_vm_get($session_id, $vm_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../vm/form/openvz_vm.tform.php');
		return $app->remoting_lib->getDataRecord($vm_id);
	}

	//* Get OpenVZ list
	public function openvz_vm_get_by_client($session_id, $client_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		if (!empty($client_id)) {
			$client_id      = $app->functions->intval($client_id);
			$tmp    = $app->db->queryOneRecord("SELECT groupid FROM sys_group WHERE client_id = $client_id");
			$sql            = "SELECT * FROM openvz_vm WHERE sys_groupid = ".$app->functions->intval($tmp['groupid']);
			$result         = $app->db->queryAllRecords($sql);
			return          $result;
		}
		return false;
	}

	//* Add a openvz vm record
	public function openvz_vm_add($session_id, $client_id, $params)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		return $this->insertQuery('../vm/form/openvz_vm.tform.php', $client_id, $params);
	}

	//* Add a openvz vm record from template
	public function openvz_vm_add_from_template($session_id, $client_id, $ostemplate_id, $template_id, $override_params = array())
	{
		global $app;

		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}


		$template_id = $app->functions->intval($template_id);
		$ostemplate_id = $app->functions->intval($ostemplate_id);

		//* Verify parameters
		if($template_id == 0) {
			$this->server->fault('template_id_error', 'Template ID must be > 0.');
			return false;
		}
		if($ostemplate_id == 0) {
			$this->server->fault('ostemplate_id_error', 'OSTemplate ID must be > 0.');
			return false;
		}

		// Verify if template and ostemplate exist
		$tmp = $app->db->queryOneRecord("SELECT template_id FROM openvz_template WHERE template_id = $template_id");
		if(!is_array($tmp)) {
			$this->server->fault('template_id_error', 'Template does not exist.');
			return false;
		}
		$tmp = $app->db->queryOneRecord("SELECT ostemplate_id FROM openvz_ostemplate WHERE ostemplate_id = $ostemplate_id");
		if(!is_array($tmp)) {
			$this->server->fault('ostemplate_id_error', 'OSTemplate does not exist.');
			return false;
		}

		//* Get the template
		$vtpl = $app->db->queryOneRecord("SELECT * FROM openvz_template WHERE template_id = $template_id");

		//* Get the IP address and server_id
		if($override_params['server_id'] > 0) {
			$vmip = $app->db->queryOneRecord("SELECT ip_address_id, server_id, ip_address FROM openvz_ip WHERE reserved = 'n' AND vm_id = 0 AND server_id = ".$override_params['server_id']." LIMIT 0,1");
		} else {
			$vmip = $app->db->queryOneRecord("SELECT ip_address_id, server_id, ip_address FROM openvz_ip WHERE reserved = 'n' AND vm_id = 0 LIMIT 0,1");
		}
		if(!is_array($vmip)) {
			$this->server->fault('vm_ip_error', 'Unable to get a free VM IP.');
			return false;
		}

		//* Build the $params array
		$params = array();
		$params['server_id'] = $vmip['server_id'];
		$params['ostemplate_id'] = $ostemplate_id;
		$params['template_id'] = $template_id;
		$params['ip_address'] = $vmip['ip_address'];
		$params['hostname'] = (isset($override_params['hostname']))?$override_params['hostname']:$vtpl['hostname'];
		$params['vm_password'] = (isset($override_params['vm_password']))?$override_params['vm_password']:$app->auth->get_random_password(10);
		$params['start_boot'] = (isset($override_params['start_boot']))?$override_params['start_boot']:'y';
		$params['active'] = (isset($override_params['active']))?$override_params['active']:'y';
		$params['active_until_date'] = (isset($override_params['active_until_date']))?$override_params['active_until_date']:'0000-00-00';
		$params['description'] = (isset($override_params['description']))?$override_params['description']:'';

		//* The next params get filled with pseudo values, as the get replaced
		//* by the openvz event plugin anyway with values from the template
		$params['veid'] = 1;
		$params['diskspace'] = 1;
		$params['ram'] = 1;
		$params['ram_burst'] = 1;
		$params['cpu_units'] = 1;
		$params['cpu_num'] = 1;
		$params['cpu_limit'] = 1;
		$params['io_priority'] = 1;
		$params['nameserver'] = '8.8.8.8 8.8.4.4';
		$params['create_dns'] = 'n';
		$params['capability'] = '';

		return $this->insertQuery('../vm/form/openvz_vm.tform.php', $client_id, $params, 'vm:openvz_vm:on_after_insert');
	}

	//* Update openvz vm record
	public function openvz_vm_update($session_id, $client_id, $vm_id, $params)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->updateQuery('../vm/form/openvz_vm.tform.php', $client_id, $vm_id, $params, 'vm:openvz_vm:on_after_update');
		return $affected_rows;
	}

	//* Delete openvz vm record
	public function openvz_vm_delete($session_id, $vm_id)
	{
		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}
		$affected_rows = $this->deleteQuery('../vm/form/openvz_vm.tform.php', $vm_id, 'vm:openvz_vm:on_after_delete');
		return $affected_rows;
	}

	//* Start VM
	public function openvz_vm_start($session_id, $vm_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../vm/form/openvz_vm.tform.php');
		$vm = $app->remoting_lib->getDataRecord($vm_id);

		if(!is_array($vm)) {
			$this->server->fault('action_pending', 'No VM with this ID available.');
			return false;
		}

		if($vm['active'] == 'n') {
			$this->server->fault('action_pending', 'VM is not in active state.');
			return false;
		}

		$action = 'openvz_start_vm';

		$tmp = $app->db->queryOneRecord("SELECT count(action_id) as actions FROM sys_remoteaction
				WHERE server_id = '".$vm['server_id']."'
				AND action_type = '$action'
				AND action_param = '".$vm['veid']."'
				AND action_state = 'pending'");

		if($tmp['actions'] > 0) {
			$this->server->fault('action_pending', 'There is already a action pending for this VM.');
			return false;
		} else {
			$sql =  "INSERT INTO sys_remoteaction (server_id, tstamp, action_type, action_param, action_state, response) " .
				"VALUES (".
				(int)$vm['server_id'] . ", ".
				time() . ", ".
				"'".$action."', ".
				$vm['veid'].", ".
				"'pending', ".
				"''".
				")";
			$app->db->query($sql);
		}
	}

	//* Stop VM
	public function openvz_vm_stop($session_id, $vm_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../vm/form/openvz_vm.tform.php');
		$vm = $app->remoting_lib->getDataRecord($vm_id);

		if(!is_array($vm)) {
			$this->server->fault('action_pending', 'No VM with this ID available.');
			return false;
		}

		if($vm['active'] == 'n') {
			$this->server->fault('action_pending', 'VM is not in active state.');
			return false;
		}

		$action = 'openvz_stop_vm';

		$tmp = $app->db->queryOneRecord("SELECT count(action_id) as actions FROM sys_remoteaction
				WHERE server_id = '".$vm['server_id']."'
				AND action_type = '$action'
				AND action_param = '".$vm['veid']."'
				AND action_state = 'pending'");

		if($tmp['actions'] > 0) {
			$this->server->fault('action_pending', 'There is already a action pending for this VM.');
			return false;
		} else {
			$sql =  "INSERT INTO sys_remoteaction (server_id, tstamp, action_type, action_param, action_state, response) " .
				"VALUES (".
				(int)$vm['server_id'] . ", ".
				time() . ", ".
				"'".$action."', ".
				$vm['veid'].", ".
				"'pending', ".
				"''".
				")";
			$app->db->query($sql);
		}
	}

	//* Restart VM
	public function openvz_vm_restart($session_id, $vm_id)
	{
		global $app;

		if(!$this->checkPerm($session_id, 'vm_openvz')) {
			$this->server->fault('permission_denied', 'You do not have the permissions to access this function.');
			return false;
		}

		$app->uses('remoting_lib');
		$app->remoting_lib->loadFormDef('../vm/form/openvz_vm.tform.php');
		$vm = $app->remoting_lib->getDataRecord($vm_id);

		if(!is_array($vm)) {
			$this->server->fault('action_pending', 'No VM with this ID available.');
			return false;
		}

		if($vm['active'] == 'n') {
			$this->server->fault('action_pending', 'VM is not in active state.');
			return false;
		}

		$action = 'openvz_restart_vm';

		$tmp = $app->db->queryOneRecord("SELECT count(action_id) as actions FROM sys_remoteaction
				WHERE server_id = '".$vm['server_id']."'
				AND action_type = '$action'
				AND action_param = '".$vm['veid']."'
				AND action_state = 'pending'");

		if($tmp['actions'] > 0) {
			$this->server->fault('action_pending', 'There is already a action pending for this VM.');
			return false;
		} else {
			$sql =  "INSERT INTO sys_remoteaction (server_id, tstamp, action_type, action_param, action_state, response) " .
				"VALUES (".
				(int)$vm['server_id'] . ", ".
				time() . ", ".
				"'".$action."', ".
				$vm['veid'].", ".
				"'pending', ".
				"''".
				")";
			$app->db->query($sql);
		}
	}




}

?>
