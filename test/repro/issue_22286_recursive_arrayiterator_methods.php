<?php
// repro #22286 RecursiveArrayIterator ArrayAccess/sort/seek/flags/serialize
$r = new RecursiveArrayIterator(['x' => 1, 'y' => [2, 3], 'z' => 4]);
foreach (['offsetGet', 'append', 'asort', 'seek', 'getFlags', 'serialize', '__serialize'] as $m) {
    echo $m, '=', method_exists($r, $m) ? 'Y' : 'N', "\n";
}
echo 'dim=', $r['x'], "\n";
$r['w'] = 9;
unset($r['w']);
echo 'isset_w=', isset($r['w']) ? 'Y' : 'N', "\n";
$r->append(10);
echo 'count=', $r->count(), "\n";
$r->setFlags(ArrayIterator::ARRAY_AS_PROPS);
echo 'flags=', $r->getFlags(), "\n";
$r->seek(1);
echo 'key=', $r->key(), ' has=', $r->hasChildren() ? 'Y' : 'N', "\n";
echo 'ser=', strlen($r->serialize()) > 0 ? 'Y' : 'N', "\n";
$bag = $r->__serialize();
echo 'bag=', is_array($bag[1]) ? 'Y' : 'N', "\n";
$r3 = new RecursiveArrayIterator(['b' => 2, 'a' => 1]);
$r3->asort();
echo 'asort=', json_encode($r3->getArrayCopy()), "\n";
