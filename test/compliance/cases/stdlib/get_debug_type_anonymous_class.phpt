--TEST--
stdlib get_debug_type() on anonymous class — class@anonymous not internal NUL suffix (#17443, ext/standard/type.c)
--FILE--
<?php
declare(strict_types=1);

var_dump(get_debug_type(new class {}));
echo get_debug_type(new class {}) === 'class@anonymous' ? "ok\n" : "fail\n";
--EXPECT--
string(15) "class@anonymous"
ok
