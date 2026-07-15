--TEST--
stdlib phpinfo(INFO_GENERAL) CLI byte size matches Zend — no duplicate runtime rows (#19041, ext/standard/info.c)
--FILE--
<?php
ob_start();
phpinfo(INFO_GENERAL);
$out = ob_get_clean();
$len = strlen($out);
echo substr_count($out, 'Zend Signal Handling =>') === 1 ? "signal-once\n" : "signal-dup\n";
echo substr_count($out, 'Registered PHP Streams =>') === 1 ? "streams-once\n" : "streams-dup\n";
echo $len >= 2400 && $len <= 2500 ? "size-ok\n" : "size-bad:$len\n";
?>
--EXPECT--
signal-once
streams-once
size-ok
