--TEST--
stdlib ldap withheld under PROFILE=8.4 without host ldap (#24536)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo 'ext=', (int) extension_loaded('ldap'), "\n";
echo 'fn=', (int) function_exists('ldap_connect'), "\n";
$c = get_defined_constants(true);
echo 'bucket=', (int) isset($c['ldap']), "\n";
?>
--EXPECT--
ext=0
fn=0
bucket=0
