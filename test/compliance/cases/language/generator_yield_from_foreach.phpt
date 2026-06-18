--TEST--
Language: foreach over generator with yield then yield from — both values visited (#9015, zend_generators.c)
--FILE--
<?php
$gen = (function () {
    yield 1;
    yield from (function () {
        yield 2;
    })();
})();
foreach ($gen as $k => $v) {
    echo $k, '=>', $v, "\n";
}
--EXPECT--
0=>1
0=>2
