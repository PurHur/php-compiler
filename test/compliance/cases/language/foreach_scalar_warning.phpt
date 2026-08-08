--TEST--
Language: foreach on scalar — E_WARNING, empty loop, exit 0 (zend_vm_def.h, #4879)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

foreach (true as $v) {
    echo "body\n";
}
echo "done\n";

$ran = false;
foreach (true as $v) {
    $ran = true;
}
echo $ran ? "ran\n" : "skipped\n";

error_reporting(0);
foreach (false as $v) {
    echo "suppressed-body\n";
}
echo "suppressed-done\n";
--EXPECT--
W:foreach() argument must be of type array|object, false given
done
W:foreach() argument must be of type array|object, true given
skipped
W:foreach() argument must be of type array|object, false given
suppressed-done
