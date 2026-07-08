--TEST--
stdlib var_dump()/print_r() on anonymous class — class@anonymous not internal NUL suffix (#17444, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

$o = new class {};
var_dump($o);
print_r($o);
--EXPECTF--
object(class@anonymous)#%d (0) {
}
class@anonymous Object
(
)
