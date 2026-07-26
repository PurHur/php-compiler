<?php
declare(strict_types=1);
echo 'ldap_exop_sync=', function_exists('ldap_exop_sync') ? '1' : '0', "\n";
echo 'ldap_exop_passwd=', function_exists('ldap_exop_passwd') ? '1' : '0', "\n";
echo 'ldap_exop=', function_exists('ldap_exop') ? '1' : '0', "\n";
