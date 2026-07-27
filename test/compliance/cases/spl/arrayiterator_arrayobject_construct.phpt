--TEST--
SPL ArrayIterator/RecursiveArrayIterator accept ArrayObject (#23886, ext/spl/spl_array.c)
--FILE--
<?php
declare(strict_types=1);

$ao = new ArrayObject(['a' => 1, 'b' => 2]);
$it = new ArrayIterator($ao);
$n = 0;
$first = null;
foreach ($it as $v) {
    if (null === $first) {
        $first = $v;
    }
    $n++;
}
echo "count={$n} first={$first}\n";
$ao['c'] = 3;
echo 'shared=', ($it->offsetExists('c') ? 'yes' : 'no'), "\n";

$flagsInherited = (new ArrayIterator(new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS)))->getFlags();
echo 'flags_inherit=', $flagsInherited, "\n";
$flagsExplicit = (new ArrayIterator(new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS), 0))->getFlags();
echo 'flags_explicit=', $flagsExplicit, "\n";

$plain = new ArrayIterator((object) ['p' => 5, 'q' => 6]);
$pv = [];
foreach ($plain as $k => $v) {
    $pv[] = "{$k}={$v}";
}
echo 'plain=', implode(',', $pv), "\n";

$rao = new ArrayObject([1, [2, 3], 4]);
$rit = new RecursiveArrayIterator($rao);
$vals = [];
foreach (new RecursiveIteratorIterator($rit) as $v) {
    $vals[] = $v;
}
echo 'recursive=', implode(',', $vals), "\n";

try {
    new ArrayIterator(1);
    echo "no throw\n";
} catch (TypeError $e) {
    echo 'typeerror=', (str_contains($e->getMessage(), 'array') ? 'ok' : $e->getMessage()), "\n";
}
--EXPECT--
count=2 first=1
shared=yes
flags_inherit=2
flags_explicit=0
plain=p=5,q=6
recursive=1,2,3,4
typeerror=ok
