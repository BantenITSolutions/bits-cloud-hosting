<?php
/*
Copyright (c) 2007, Till Brehm, projektfarm Gmbh
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


/******************************************
* Begin Form configuration
******************************************/

$tform_def_file = "form/web_aliasdomain.tform.php";

/******************************************
* End Form configuration
******************************************/

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';

//* Check permissions for module
$app->auth->check_module_permissions('sites');

// Loading classes
$app->uses('tpl,tform,tform_actions,tools_sites');
$app->load('tform_actions');

class page_action extends tform_actions {

	var $parent_domain_record;

	function onShowNew() {
		global $app, $conf;

		// we will check only users, not admins
		if($_SESSION["s"]["user"]["typ"] == 'user') {
			if(!$app->tform->checkClientLimit('limit_web_aliasdomain', "type = 'alias'")) {
				$app->error($app->tform->wordbook["limit_web_aliasdomain_txt"]);
			}
			if(!$app->tform->checkResellerLimit('limit_web_aliasdomain', "type = 'alias'")) {
				$app->error('Reseller: '.$app->tform->wordbook["limit_web_aliasdomain_txt"]);
			}
		}

		parent::onShowNew();
	}

	function onShowEnd() {
		global $app, $conf;

		/*
		 * Now we have to check, if we should use the domain-module to select the domain
		 * or not
		 */
		$app->uses('ini_parser,getconf');
		$settings = $app->getconf->get_global_config('domains');
		if ($settings['use_domain_module'] == 'y') {
			/*
			 * The domain-module is in use.
			*/
			$domains = $app->tools_sites->getDomainModuleDomains();
			$domain_select = '';
			if(is_array($domains) && sizeof($domains) > 0) {
				/* We have domains in the list, so create the drop-down-list */
				foreach( $domains as $domain) {
					$domain_select .= "<option value=" . $domain['domain_id'] ;
					if ($domain['domain'] == $this->dataRecord["domain"]) {
						$domain_select .= " selected";
					}
					$domain_select .= ">" . $app->functions->idn_decode($domain['domain']) . "</option>\r\n";
				}
			}
			else {
				/*
				 * We have no domains in the domain-list. This means, we can not add ANY new domain.
				 * To avoid, that the variable "domain_option" is empty and so the user can
				 * free enter a domain, we have to create a empty option!
				*/
				$domain_select .= "<option value=''></option>\r\n";
			}
			$app->tpl->setVar("domain_option", $domain_select);
		}

		if($_SESSION["s"]["user"]["typ"] == 'admin') {
			// Directive Snippets
			$proxy_directive_snippets = $app->db->queryAllRecords("SELECT * FROM directive_snippets WHERE type = 'proxy' AND active = 'y'");
			$proxy_directive_snippets_txt = '';
			if(is_array($proxy_directive_snippets) && !empty($proxy_directive_snippets)){
				foreach($proxy_directive_snippets as $proxy_directive_snippet){
					$proxy_directive_snippets_txt .= '<a href="javascript:void(0);" class="addPlaceholderContent">['.$proxy_directive_snippet['name'].']<pre class="addPlaceholderContent" style="display:none;">'.$proxy_directive_snippet['snippet'].'</pre></a> ';
				}
			}
			if($proxy_directive_snippets_txt == '') $proxy_directive_snippets_txt = '------';
			$app->tpl->setVar("proxy_directive_snippets_txt", $proxy_directive_snippets_txt);
		}

		parent::onShowEnd();

	}

	function onSubmit() {
		global $app, $conf;
		
		// Get the record of the parent domain
		if(!@$this->dataRecord["parent_domain_id"] && $this->id) {
			$tmp = $app->db->queryOneRecord("SELECT parent_domain_id FROM web_domain WHERE domain_id = ".$app->functions->intval($this->id));
			if($tmp) $this->dataRecord["parent_domain_id"] = $tmp['parent_domain_id'];
			unset($tmp);
		}

		// Get the record of the parent domain
		$parent_domain = $app->db->queryOneRecord("SELECT * FROM web_domain WHERE domain_id = ".$app->functions->intval(@$this->dataRecord["parent_domain_id"]) . " AND ".$app->tform->getAuthSQL('r'));
		
		if(!$parent_domain || $parent_domain['domain_id'] != @$this->dataRecord['parent_domain_id']) $app->tform->errorMessage .= $app->tform->lng("no_domain_perm");
		/* check if the domain module is used - and check if the selected domain can be used! */
		$app->uses('ini_parser,getconf');
		$settings = $app->getconf->get_global_config('domains');
		if ($settings['use_domain_module'] == 'y') {
			$domain_check = $app->tools_sites->checkDomainModuleDomain($this->dataRecord['domain']);
			if(!$domain_check) {
				// invalid domain selected
				$app->tform->errorMessage .= $app->tform->lng("domain_error_empty")."<br />";
			} else {
				$this->dataRecord['domain'] = $domain_check;
			}
		}

		// nginx: if redirect type is proxy and redirect path is no URL, display error
		if($this->dataRecord["redirect_type"] == 'proxy' && substr($this->dataRecord['redirect_path'], 0, 1) == '/'){
			$app->tform->errorMessage .= $app->tform->lng("error_proxy_requires_url")."<br />";
		}

		// Set a few fixed values
		$this->dataRecord["type"] = 'alias';
		$this->dataRecord["server_id"] = $parent_domain["server_id"];

		$this->parent_domain_record = $parent_domain;

		//* make sure that the domain is lowercase
		if(isset($this->dataRecord["domain"])) $this->dataRecord["domain"] = strtolower($this->dataRecord["domain"]);
		
		parent::onSubmit();
	}

	function onAfterInsert() {
		global $app, $conf;

		$app->db->query('UPDATE web_domain SET sys_groupid = '.$app->functions->intval($this->parent_domain_record['sys_groupid']).' WHERE domain_id = '.$this->id);

	}

	function onAfterUpdate() {
		global $app, $conf;

		//* Check if parent domain has been changed
		if($this->dataRecord['parent_domain_id'] != $this->oldDataRecord['parent_domain_id']) {

			//* Update the domain owner
			$app->db->query('UPDATE web_domain SET sys_groupid = '.$app->functions->intval($this->parent_domain_record['sys_groupid']).' WHERE domain_id = '.$this->id);

			//* Update the old website, so that the vhost alias gets removed
			//* We force the update by inserting a transaction record without changes manually.
			$old_website = $app->db->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '.$app->functions->intval($this->oldDataRecord['domain_id']));
			$app->db->datalogSave('web_domain', 'UPDATE', 'domain_id', $app->functions->intval($this->oldDataRecord['parent_domain_id']), $old_website, $old_website, true);
		}

	}

}

$page = new page_action;
$page->onLoad();

?>
