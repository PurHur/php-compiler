--TEST--
Language: final plain property PROFILE=8.4 matrix — write ok, isFinal, override fatal (#25379, php-src-strict)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Guards must not emit stdout before the inherit fatal (BaseTest #7468).
eval('class P { final public string $x = "z"; }');
$p = new P;
if ($p->x !== 'z') {
    echo "value-bad\n";
}
$p->x = 'w';
if ($p->x !== 'w') {
    echo "write-failed\n";
}
if (!(new ReflectionProperty('P', 'x'))->isFinal()) {
    echo "not-final\n";
}
eval('class C extends P { public string $x = "c"; }');
echo "override=ok\n";
--EXPECTF--
PHP Fatal error:  Cannot override final property P::$x in %s : eval()'d code on line %d
--EXPECT_EXIT--
255
