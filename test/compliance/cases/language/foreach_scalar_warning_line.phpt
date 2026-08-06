--TEST--
Language: foreach(scalar) E_WARNING cites foreach statement line (zend_vm_def.h FE_RESET, #27953)
--FILE--
<?php
$lines = [];
set_error_handler(function (int $errno, string $message, string $file, int $line) use (&$lines): bool {
    $lines[] = $line;
    echo 'W:', $message, ' L:', $line, "\n";

    return false;
});
error_reporting(E_ALL);
ini_set('display_errors', '0');
foreach (123 as $v) {
}
$last = error_get_last();
echo 'handler:', (string) ($lines[0] ?? 0), "\n";
echo 'last:', (string) ($last['line'] ?? 0), "\n";
echo "after\n";
--EXPECT--
W:foreach() argument must be of type array|object, int given L:11
handler:11
last:11
after
