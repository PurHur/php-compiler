--TEST--
stdlib — enum operands throw catchable TypeError not aborting LogicException (#6267)
--FILE--
<?php
enum E: string { case A = 'x'; }
$e = E::A;
$fns = ['link', 'substr_compare', 'clearstatcache', 'gethostbynamel'];
foreach ($fns as $fn) {
    try {
        if ('link' === $fn) {
            link($e, '/tmp/t');
        } elseif ('substr_compare' === $fn) {
            substr_compare('a', $e, 0);
        } elseif ('clearstatcache' === $fn) {
            clearstatcache(true, $e);
        } else {
            gethostbynamel($e);
        }
        echo $fn, " uncaught\n";
    } catch (TypeError $t) {
        echo $fn, " TypeError\n";
    } catch (LogicException $t) {
        echo $fn, " LogicException\n";
    }
}
--EXPECT--
link TypeError
substr_compare TypeError
clearstatcache TypeError
gethostbynamel TypeError
