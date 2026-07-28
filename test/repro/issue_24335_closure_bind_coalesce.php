<?php
/**
 * Bound Closure::bind scope must allow private static ?? (#24335).
 * Root cause: Block::getFrame dropped calledClass on CFG edges (?? / if / try).
 */
class A
{
    private static $v = 7;
}

$f = static function () {
    return A::$v ?? 'no';
};
$b = Closure::bind($f, null, A::class);
echo 'coalesce=', $b(), "\n";

$f2 = static function () {
    return A::$v;
};
echo 'direct=', Closure::bind($f2, null, A::class)(), "\n";

$f3 = static function () {
    if (1) {
        return A::$v;
    }

    return 0;
};
echo 'if=', Closure::bind($f3, null, A::class)(), "\n";

$f4 = static function () {
    try {
        return A::$v;
    } catch (Throwable $t) {
        return 'caught';
    }
};
echo 'try=', Closure::bind($f4, null, A::class)(), "\n";
