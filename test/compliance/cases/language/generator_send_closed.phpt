--TEST--
Generator send() on closed generator returns null silently (#7161, Zend zend_generators.c)
--FILE--
<?php
$g = (function (): Generator {
    yield 1;
})();
$g->next();
echo 'valid=', var_export($g->valid(), true), "\n";
$result = $g->send(99);
echo 'send=', var_export($result, true), "\n";
echo 'valid_after=', var_export($g->valid(), true), "\n";
--EXPECT--
valid=false
send=NULL
valid_after=false
