--TEST--
stdlib json_decode() JSON_INVALID_UTF8_IGNORE strips malformed bytes in strings (issue #21724, ext/json/json_scanner.c)
--FILE--
<?php
declare(strict_types=1);

$b = 'a' . chr(0x80) . 'b';
$ignore = json_decode('"' . $b . '"', false, 512, 1048576);
echo 'ignore:', $ignore, ':', json_last_error(), "\n";
$sub = json_decode('"' . $b . '"', false, 512, 2097152);
echo 'sub:', bin2hex($sub), ':', json_last_error(), "\n";
?>
--EXPECT--
ignore:ab:0
sub:61efbfbd62:0
