<?php
// AOT RecursiveTreeIterator module verify (re-#27584 / #33974)
echo 'Parent:';
$p = new ParentIterator(new RecursiveArrayIterator(['a' => 1, 'b' => ['c' => 2, 'd' => 3]]));
$pk = [];
foreach ($p as $k => $v) {
    $pk[] = (string) $k;
}
echo implode(',', $pk), "\n";
echo 'Multi:';
$m = new MultipleIterator();
$m->attachIterator(new ArrayIterator(['a', 'b']), 'L');
$m->attachIterator(new ArrayIterator([1, 2]), 'N');
$mv = [];
foreach ($m as $v) {
    $mv[] = implode(':', $v);
}
echo implode(' ', $mv), "\n";
echo 'Tree:';
$t = new RecursiveTreeIterator(new RecursiveArrayIterator(['a' => ['b' => 1, 'c' => 2]]));
$tv = [];
foreach ($t as $v) {
    $tv[] = $v;
}
echo implode('|', $tv), "|\n";
