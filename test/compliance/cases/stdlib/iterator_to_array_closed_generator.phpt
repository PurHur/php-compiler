--TEST--
iterator_to_array() on consumed Generator — Zend closed-generator message (#18582, ext/spl/php_spl.c)
--FILE--
<?php
$g = (function () {
    yield 1;
})();
$g->next();
try {
    iterator_to_array($g);
    echo "no throw\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}

$g2 = (function () {
    yield 1;
    yield 2;
})();
$g2->next();
try {
    iterator_to_array($g2);
    echo "no throw2\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot traverse an already closed generator
Cannot rewind a generator that was already run
