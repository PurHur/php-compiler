--TEST--
stdlib soundex()/metaphone() JIT — strict_types call-site TypeError (#4193)
--JIT--
--FILE--
<?php
declare(strict_types=1);

try {
    soundex(123);
} catch (Throwable $e) {
    echo 'soundex: ', get_class($e), "\n";
}

try {
    metaphone(true);
} catch (Throwable $e) {
    echo 'metaphone: ', get_class($e), "\n";
}
--EXPECT--
soundex: TypeError
metaphone: TypeError
