--TEST--
stdlib phpinfo(INFO_GENERAL) includes Build Date row (#12141, ext/standard/info.c)
--FILE--
<?php
ob_start();
phpinfo(INFO_GENERAL);
$out = ob_get_clean();
echo str_contains($out, 'Build Date') ? "build_date ok\n" : "build_date missing\n";
echo preg_match('/Build Date\s*<\/td><td class="v">([^<]+)/', $out, $m) && '' !== trim($m[1])
    ? "value ok\n"
    : "value missing\n";
--EXPECT--
build_date ok
value ok
