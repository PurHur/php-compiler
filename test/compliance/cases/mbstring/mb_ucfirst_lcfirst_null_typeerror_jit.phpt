--TEST--
mbstring mb_ucfirst()/mb_lcfirst() null $string — TypeError JIT (#19433, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$ok = true;
foreach (['mb_ucfirst', 'mb_lcfirst'] as $f) {
    try {
        $f(null);
        $ok = false;
    } catch (TypeError $e) {
        $expect = $f.'(): Argument #1 ($string) must be of type string, null given';
        if ($e->getMessage() !== $expect) {
            $ok = false;
        }
    }
}
if ('Über' !== mb_ucfirst('über')) {
    $ok = false;
}
echo $ok ? "ok\n" : "bad\n";
--EXPECT--
ok
