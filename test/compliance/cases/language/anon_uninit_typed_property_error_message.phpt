--TEST--
Language: uninitialized typed property Error on anonymous class uses class@anonymous (no NUL/path) (#31117, zend_object_handlers.c)
--FILE--
<?php
$c = new class { public int $x; };
try {
    echo $c->x;
    echo "UNEXPECTED_OK\n";
} catch (Error $e) {
    $msg = $e->getMessage();
    echo $msg, "\n";
    echo "has_nul=", (strpos($msg, "\0") !== false ? "yes" : "no"), "\n";
}
--EXPECT--
Typed property class@anonymous::$x must not be accessed before initialization
has_nul=no
