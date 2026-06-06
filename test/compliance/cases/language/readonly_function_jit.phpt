--TEST--
Language: readonly() JIT rejects property writes on marked objects (#6485)
--FILE--
<?php
$o = (object)['x' => 1];
readonly($o);
try {
    $o->x = 2;
    echo "mutated\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot modify readonly object of class stdClass
