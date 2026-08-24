--TEST--
AOT: try { unset inherited typed; .= } throws Error like Zend (#34431)
--FILE--
<?php
class ParentS { public string $p = 'hi'; }
class ChildS extends ParentS {}
$b = new ChildS();
try {
    unset($b->p);
    $b->p .= 'x';
    echo "survived:", $b->p, "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo "DONE\n";
--EXPECT--
Typed property ParentS::$p must not be accessed before initialization
DONE
