--TEST--
stdlib copy() null path under strict_types throws TypeError (#17075, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);

try {
    copy(null, '/tmp/x');
    echo "from: uncaught\n";
} catch (TypeError $e) {
    echo 'from: ', $e->getMessage(), "\n";
}

try {
    copy('/tmp/x', null);
    echo "to: uncaught\n";
} catch (TypeError $e) {
    echo 'to: ', $e->getMessage(), "\n";
}
--EXPECT--
from: copy(): Argument #1 ($from) must be of type string, null given
to: copy(): Argument #2 ($to) must be of type string, null given
