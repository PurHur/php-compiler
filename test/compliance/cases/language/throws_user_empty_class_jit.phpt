--TEST--
Language: JIT throw/catch on empty user class (007 ValidationError scope, #2167)
--FILE--
<?php
class ValidationError {}
try {
    throw new ValidationError();
} catch (ValidationError $e) {
    echo "ok\n";
}
--EXPECT--
ok
