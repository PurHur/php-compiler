--TEST--
stdlib array_filter() inline FuncCall haystack + closure callback (#17948, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$r = array_filter(explode(',', 'a,b'), static fn ($x): bool => true);
if ($r !== ['a', 'b']) {
    echo 'fail';
    exit(1);
}

echo "ok\n";
?>
--EXPECT--
ok
