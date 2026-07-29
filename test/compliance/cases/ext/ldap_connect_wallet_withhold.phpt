--TEST--
stdlib ldap_connect_wallet withheld without Oracle LDAP (#20638, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$hasWallet = function_exists('ldap_connect_wallet');
$hasLdap = extension_loaded('ldap');
echo $hasLdap ? "ldap_yes\n" : "ldap_no\n";
if ($hasWallet) {
    echo "wallet_yes\n";
    echo defined('GSLC_SSL_NO_AUTH') ? "gslc_yes\n" : "gslc_no\n";
} else {
    echo "wallet_no\n";
    echo defined('GSLC_SSL_NO_AUTH') ? "gslc_yes\n" : "gslc_no\n";
}
?>
--EXPECT--
ldap_yes
wallet_no
gslc_no
