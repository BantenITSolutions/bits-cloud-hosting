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

/*
	ISPConfig 3 uninstaller.
*/

error_reporting(E_ALL|E_STRICT);

//** The banner on the command line
echo "\n\n".str_repeat('-', 80)."\n";
echo " _____ ___________   _____              __ _         ____
|_   _/  ___| ___ \ /  __ \            / _(_)       /__  \
  | | \ `--.| |_/ / | /  \/ ___  _ __ | |_ _  __ _    _/ /
  | |  `--. \  __/  | |    / _ \| '_ \|  _| |/ _` |  |_ |
 _| |_/\__/ / |     | \__/\ (_) | | | | | | | (_| | ___\ \
 \___/\____/\_|      \____/\___/|_| |_|_| |_|\__, | \____/
                                              __/ |
                                             |___/ ";
echo "\n".str_repeat('-', 80)."\n";
echo "\n\n>> Uninstall  \n\n";

require "/usr/local/ispconfig/server/lib/config.inc.php";
require "/usr/local/ispconfig/server/lib/app.inc.php";

// Delete the ISPConfig database
// $app->db->query("DROP DATABASE '".$conf["db_database"]."'");
// $app->db->query("DELETE FROM mysql.user WHERE User = 'ispconfig'");


exec("/etc/init.d/mysql stop");
exec("rm -rf /var/lib/mysql/".$conf["db_database"]);
exec("/etc/init.d/mysql start >/dev/null 2>&1");

// Deleting the symlink in /home/www
// Apache
@unlink("/etc/apache2/sites-enabled/000-ispconfig.vhost");
@unlink("/etc/apache2/sites-available/ispconfig.vhost");
@unlink("/etc/apache2/sites-enabled/000-apps.vhost");
@unlink("/etc/apache2/sites-available/apps.vhost");

// nginx
@unlink("/etc/nginx/sites-enabled/000-ispconfig.vhost");
@unlink("/etc/nginx/sites-available/ispconfig.vhost");
@unlink("/etc/nginx/sites-enabled/000-apps.vhost");
@unlink("/etc/nginx/sites-available/apps.vhost");

// Delete the ispconfig files
exec('rm -rf /usr/local/ispconfig');

// Delete various other files
@unlink("/usr/local/bin/ispconfig_update.sh");
@unlink("/usr/local/bin/ispconfig_update_from_svn.sh");
@unlink("/var/spool/mail/ispconfig");
@unlink("/home/www/ispconfig");
@unlink("/home/www/php-fcgi-scripts/ispconfig");
@unlink("/home/www/php-fcgi-scripts/ispconfig/.php-fcgi-starter");

echo "Backups in /var/backup/ and log files in /var/log/ispconfig are not deleted.";
echo "Please do not forget to delete the ispconfig user in the mysql.user table.\n\n";

echo "Finished.\n";

?>
