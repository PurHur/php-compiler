--TEST--
Language: foreach ($undefined as …) — Undefined variable then type warning (zend_vm_def.h, #26148)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

foreach ($undefined as $v) {
    echo "body\n";
}
echo "after\n";

$x = null;
foreach ($x as $v) {
    echo "null-body\n";
}
echo "null-after\n";
--EXPECT--
W:Undefined variable $undefined
W:foreach() argument must be of type array|object, null given
after
W:foreach() argument must be of type array|object, null given
null-after
