--TEST--
Language: ::class on non-object values throws TypeError (#4241)
--FILE--
<?php
declare(strict_types=1);
$x = 'stdClass';
try {
    echo $x::class, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$n = 1;
try {
    echo $n::class, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
class C {}
$o = new C();
echo $o::class, "\n";
echo C::class, "\n";
--EXPECT--
TypeError: Cannot use "::class" on value of type string
TypeError: Cannot use "::class" on value of type int
C
C
