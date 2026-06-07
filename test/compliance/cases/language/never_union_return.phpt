--TEST--
Language: never in union return type — compiles (PHP 8.2+, #7414)
--FILE--
<?php
function f(): int|never {
    throw new Exception('done');
}
try {
    f();
} catch (Exception $e) {
    echo $e->getMessage();
}
--EXPECT--
done
