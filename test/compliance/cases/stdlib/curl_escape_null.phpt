--TEST--
stdlib curl_escape()/curl_unescape(null) — coerce under 8.2 profile (#20695, ext/curl/interface.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
$ch = curl_init();
var_export(curl_escape($ch, null));
echo "\n";
var_export(curl_unescape($ch, null));
echo "\n";
?>
--EXPECT--
''
''
