--TEST--
locale_filter_matches() AOT prefix filter (#32119)
--SKIPIF--
<?php
if (!extension_loaded('intl')) {
    die('skip intl required');
}
?>
--FILE--
<?php
echo locale_filter_matches('de-DE', 'de', false) ? 'true' : 'false', "\n";
$tag = 'en_US@currency=usd';
echo (int) locale_filter_matches($tag, 'en_US', true), "\n";
--EXPECT--
true
1
