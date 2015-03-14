<?php
/**
 * client_templates
 *
 * @author Marius Cramer <m.cramer@pixcept.de> pixcept KG
 * @author (original tools.inc.php) Till Brehm, projektfarm Gmbh
 * @author (original tools.inc.php) Oliver Vogel www.muv.com
 */


class client_templates {


	/**
	 *  - check for old-style templates and change to new style
	 *  - update assigned templates
	 */
	function update_client_templates($clientId, $templates = array()) {
		global $app, $conf;

		if(!is_array($templates)) return false;

		$new_tpl = array();
		$used_assigned = array();
		$needed_types = array();
		$old_style = true;
		foreach($templates as $item) {
			$item = trim($item);
			if($item == '') continue;

			$tpl_id = 0;
			$assigned_id = 0;
			if(strpos($item, ':') === false) {
				$tpl_id = $item;
			} else {
				$old_style = false; // has new-style assigns
				list($assigned_id, $tpl_id) = explode(':', $item, 2);
				if(substr($assigned_id, 0, 1) === 'n') $assigned_id = 0; // newly inserted items
			}
			if(array_key_exists($tpl_id, $needed_types) == false) $needed_types[$tpl_id] = 0;
			$needed_types[$tpl_id]++;

			if($assigned_id > 0) {
				$used_assigned[] = $assigned_id; // for comparison with database
			} else {
				$new_tpl[] = $tpl_id;
			}
		}

		if($old_style == true) {
			// we have to take care of this in an other way
			$in_db = $app->db->queryAllRecords('SELECT `assigned_template_id`, `client_template_id` FROM `client_template_assigned` WHERE `client_id` = ' . $app->functions->intval($clientId));
			if(is_array($in_db) && count($in_db) > 0) {
				foreach($in_db as $item) {
					if(array_key_exists($item['client_template_id'], $needed_types) == false) $needed_types[$item['client_template_id']] = 0;
					$needed_types[$item['client_template_id']]--;
				}
			}

			foreach($needed_types as $tpl_id => $count) {
				if($count > 0) {
					// add new template to client (includes those from old-style without assigned_template_id)
					for($i = $count; $i > 0; $i--) {
						$app->db->query('INSERT INTO `client_template_assigned` (`client_id`, `client_template_id`) VALUES (' . $app->functions->intval($clientId) . ', ' . $app->functions->intval($tpl_id) . ')');
					}
				} elseif($count < 0) {
					// remove old ones
					for($i = $count; $i < 0; $i++) {
						$app->db->query('DELETE FROM `client_template_assigned` WHERE client_id = ' . $app->functions->intval($clientId) . ' AND client_template_id = ' . $app->functions->intval($tpl_id) . ' LIMIT 1');
					}
				}
			}
		} else {
			// we have to take care of this in an other way
			$in_db = $app->db->queryAllRecords('SELECT `assigned_template_id`, `client_template_id` FROM `client_template_assigned` WHERE `client_id` = ' . $app->functions->intval($clientId));
			if(is_array($in_db) && count($in_db) > 0) {
				// check which templates were removed from this client
				foreach($in_db as $item) {
					if(in_array($item['assigned_template_id'], $used_assigned) == false) {
						// delete this one
						$app->db->query('DELETE FROM `client_template_assigned` WHERE `assigned_template_id` = ' . $app->functions->intval($item['assigned_template_id']));
					}
				}
			}

			if(count($new_tpl) > 0) {
				foreach($new_tpl as $item) {
					// add new template to client (includes those from old-style without assigned_template_id)
					$app->db->query('INSERT INTO `client_template_assigned` (`client_id`, `client_template_id`) VALUES (' . $app->functions->intval($clientId) . ', ' . $app->functions->intval($item) . ')');
				}
			}
		}

		unset($new_tpl);
		unset($in_db);
		unset($templates);
		unset($used_assigned);
		return true;
	}

	function apply_client_templates($clientId) {
		global $app;

		include '../client/form/client.tform.php';

		/*
         * Get the master-template for the client
         */
		$sql = "SELECT template_master, template_additional,limit_client FROM client WHERE client_id = " . $app->functions->intval($clientId);
		$record = $app->db->queryOneRecord($sql);
		$masterTemplateId = $record['template_master'];
		$is_reseller = ($record['limit_client'] > 0)?true:false;

		if($record['template_additional'] != '') {
			// we have to call the update_client_templates function
			$templates = explode('/', $record['template_additional']);
			$this->update_client_templates($clientId, $templates);
			$app->db->query('UPDATE `client` SET `template_additional` = \'\' WHERE `client_id` = ' . $app->functions->intval($clientId));
		}

		/*
         * if the master-Template is custom there is NO changing
         */
		if ($masterTemplateId > 0){
			$sql = "SELECT * FROM client_template WHERE template_id = " . $app->functions->intval($masterTemplateId);
			$limits = $app->db->queryOneRecord($sql);
		} else {
			// if there is no master template it makes NO SENSE adding sub templates.
			// adding subtemplates are stored in client limits, so they would add up
			// on every save action for the client -> too high limits!
			return;
		}

		/*
         * Process the additional tempaltes here (add them to the limits
         * if != -1)
         */
		$addTpl = explode('/', $additionalTemplateStr);
		$addTpls = $app->db->queryAllRecords('SELECT `client_template_id` FROM `client_template_assigned` WHERE `client_id` = ' . $app->functions->intval($clientId));
		foreach ($addTpls as $addTpl){
			$item = $addTpl['client_template_id'];
			$sql = "SELECT * FROM client_template WHERE template_id = " . $app->functions->intval($item);
			$addLimits = $app->db->queryOneRecord($sql);
			$app->log('Template processing subtemplate ' . $item . ' for client ' . $clientId, LOGLEVEL_DEBUG);
			/* maybe the template is deleted in the meantime */
			if (is_array($addLimits)){
				foreach($addLimits as $k => $v){
					/* we can remove this condition, but it is easier to debug with it (don't add ids and other non-limit values) */
					if (strpos($k, 'limit') !== false or $k == 'ssh_chroot' or $k == 'web_php_options' or $k == 'force_suexec'){
						$app->log('Template processing key ' . $k . ' for client ' . $clientId, LOGLEVEL_DEBUG);

						/* process the numerical limits */
						if (is_numeric($v)){
							/* switch for special cases */
							switch ($k){
							case 'limit_cron_frequency':
								if ($v < $limits[$k]) $limits[$k] = $v;
								/* silent adjustment of the minimum cron frequency to 1 minute */
								/* maybe this control test should be done via validator definition in tform.php file, but I don't know how */
								if ($limits[$k] < 1) $limits[$k] = 1;
								break;

							default:
								if ($limits[$k] > -1){
									if ($v == -1){
										$limits[$k] = -1;
									}
									else {
										$limits[$k] += $v;
									}
								}
							}
						}
						/* process the string limits (CHECKBOXARRAY, SELECT etc.) */
						elseif (is_string($v)){
							switch ($form["tabs"]["limits"]["fields"][$k]['formtype']){
							case 'CHECKBOXARRAY':
								if (!isset($limits[$k])){
									$limits[$k] = array();
								}

								$limits_values = $limits[$k];
								if (is_string($limits[$k])){
									$limits_values = explode($form["tabs"]["limits"]["fields"][$k]["separator"], $limits[$k]);
								}
								$additional_values = explode($form["tabs"]["limits"]["fields"][$k]["separator"], $v);
								$app->log('Template processing key ' . $k . ' type CHECKBOXARRAY, lim / add: ' . implode(',', $limits_values) . ' / ' . implode(',', $additional_values) . ' for client ' . $clientId, LOGLEVEL_DEBUG);
								/* unification of limits_values (master template) and additional_values (additional template) */
								$limits_unified = array();
								foreach($form["tabs"]["limits"]["fields"][$k]["value"] as $key => $val){
									if (in_array($key, $limits_values) || in_array($key, $additional_values)) $limits_unified[] = $key;
								}
								$limits[$k] = implode($form["tabs"]["limits"]["fields"][$k]["separator"], $limits_unified);
								break;
							case 'CHECKBOX':
								if($k == 'force_suexec') {
									// 'n' is less limited than y
									if (!isset($limits[$k])){
										$limits[$k] = 'y';
									}
									if($limits[$k] == 'n' || $v == 'n') $limits[$k] = 'n';
								} else {
									// 'y' is less limited than n
									if (!isset($limits[$k])){
										$limits[$k] = 'n';
									}
									if($limits[$k] == 'y' || $v == 'y') $limits[$k] = 'y';
								}
								break;
							case 'SELECT':
								$limit_values = array_keys($form["tabs"]["limits"]["fields"][$k]["value"]);
								/* choose the lower index of the two SELECT items */
								$limits[$k] = $limit_values[min(array_search($limits[$k], $limit_values), array_search($v, $limit_values))];
								break;
							}
						}
					}
				}
			}
		}

		/*
         * Write all back to the database
         */
		$update = '';
		if(!$is_reseller) unset($limits['limit_client']); // Only Resellers may have limit_client set in template to ensure that we do not convert a client to reseller accidently.
		foreach($limits as $k => $v){
			if ((strpos($k, 'limit') !== false or $k == 'ssh_chroot' or $k == 'web_php_options' or $k == 'force_suexec') && !is_array($v)){
				if ($update != '') $update .= ', ';
				$update .= '`' . $k . "`='" . $v . "'";
			}
		}
		$app->log('Template processed for client ' . $clientId . ', update string: ' . $update, LOGLEVEL_DEBUG);
		if($update != '') {
			$sql = 'UPDATE client SET ' . $update . " WHERE client_id = " . $app->functions->intval($clientId);
			$app->db->query($sql);
		}
		unset($form);
	}

}
