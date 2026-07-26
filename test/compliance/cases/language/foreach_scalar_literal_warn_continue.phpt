--TEST--
Language: foreach on scalar literals — E_WARNING, empty loop, continue (zend_vm_def.h, #23452 / re-#4879)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

foreach ('abc' as $v) {
    echo "body-str\n";
}
echo "survived-str\n";

foreach (123 as $v) {
    echo "body-int\n";
}
echo "survived-int\n";

foreach (1.5 as $v) {
    echo "body-float\n";
}
echo "survived-float\n";

foreach ('abc' as &$v) {
    echo "body-ref\n";
}
echo "survived-ref\n";
--EXPECT--
W:foreach() argument must be of type array|object, string given
survived-str
W:foreach() argument must be of type array|object, int given
survived-int
W:foreach() argument must be of type array|object, float given
survived-float
W:foreach() argument must be of type array|object, string given
survived-ref
