--TEST--
Language: #28437 issue-body — isFinal=1 then eval child cannot override final plain under PROFILE=8.4
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A { public final int $x = 1; }
$r = new ReflectionProperty(A::class, 'x');
echo 'isFinal=', $r->isFinal() ? '1' : '0', "\n";
try {
    eval('class B extends A { public int $x = 2; }');
    echo "redef_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECTF--
isFinal=1
PHP Fatal error:  Cannot override final property A::$x in %s : eval()'d code on line %d
--EXPECT_EXIT--
255
