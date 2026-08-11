--TEST--
stdlib chown/chgrp/lchown/lchgrp(null) JIT TypeError under strict_types (#30167, ext/standard/filestat.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    chown(null, 0);
    echo "chown: fail\n";
} catch (TypeError $e) {
    echo 'chown: ', $e->getMessage(), "\n";
}
try {
    chgrp(null, 0);
    echo "chgrp: fail\n";
} catch (TypeError $e) {
    echo 'chgrp: ', $e->getMessage(), "\n";
}
try {
    lchown(null, 0);
    echo "lchown: fail\n";
} catch (TypeError $e) {
    echo 'lchown: ', $e->getMessage(), "\n";
}
try {
    lchgrp(null, 0);
    echo "lchgrp: fail\n";
} catch (TypeError $e) {
    echo 'lchgrp: ', $e->getMessage(), "\n";
}
--EXPECT--
chown: chown(): Argument #1 ($filename) must be of type string, null given
chgrp: chgrp(): Argument #1 ($filename) must be of type string, null given
lchown: lchown(): Argument #1 ($filename) must be of type string, null given
lchgrp: lchgrp(): Argument #1 ($filename) must be of type string, null given
