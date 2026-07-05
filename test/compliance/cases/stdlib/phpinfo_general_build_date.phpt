--TEST--
stdlib phpinfo(INFO_GENERAL) includes Build Date row (#12141, ext/standard/info.c)
--FILE--
<?php
ob_start();
phpinfo(INFO_GENERAL);
$out = ob_get_clean();
echo str_contains($out, 'Build Date') ? "build_date ok\n" : "build_date missing\n";
$hasValue = preg_match('/Build Date\s*<\/td><td class="v">([^<]+)/', $out, $m) && '' !== trim($m[1]);
if (!$hasValue) {
    $hasValue = preg_match('/Build Date => (.*)$/m', $out, $m) && '' !== trim($m[1]);
}
echo $hasValue ? "value nonempty\n" : "value empty\n";
--EXPECT--
build_date ok
value empty
