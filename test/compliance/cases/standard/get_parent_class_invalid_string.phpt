--TEST--
get_parent_class() invalid string throws TypeError not ValueError (#17904, ext/standard/class.c)
--FILE--
<?php
try {
    get_parent_class('x');
    echo "bad\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'string given') ? 'type' : 'msg-bad', "\n";
} catch (ValueError $e) {
    echo "value\n";
}
--EXPECT--
type
