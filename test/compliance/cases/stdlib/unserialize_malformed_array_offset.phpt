--TEST--
stdlib unserialize() malformed array header — notice offset 0 not 2 (#18471, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

$payload = 'a:' . str_repeat('i:0;', 3) . 'i:0;';
$len = strlen($payload);
$result = @unserialize($payload);
$last = error_get_last();
echo 'result=', var_export($result, true), "\n";
echo 'message=', is_array($last) ? ($last['message'] ?? '') : '', "\n";
?>
--EXPECT--
result=false
message=unserialize(): Error at offset 0 of 18 bytes
