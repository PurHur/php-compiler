--TEST--
DNF intersection-in-union types compile and run (#6817)
--FILE--
<?php
interface A {}
interface B {}
class Both implements A, B {}

class Box {
    public (A&B)|null $it = null;
}

function accept((A&B)|null $x): string {
    return null === $x ? 'null' : 'ok';
}

$box = new Box();
$box->it = new Both();
echo accept($box->it);
echo "\n";
echo accept(null);
echo "\n";
try {
    accept('bad');
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
ok
null
TypeError
