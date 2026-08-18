--TEST--
locale_lookup() AOT RFC 4647 lookup (#32118)
--SKIPIF--
<?php
if (!extension_loaded('intl')) {
    die('skip intl required');
}
?>
--FILE--
<?php
echo locale_lookup(['de-DE', 'de'], 'de-CH', true, 'en'), "\n";
$tags = ['de-DE', 'fr-FR'];
echo locale_lookup($tags, 'de-CH', false, 'en_US'), "\n";
--EXPECT--
de
en_US
