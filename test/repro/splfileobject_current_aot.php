<?php
/**
 * #33521 — AOT SplFileObject::current / seek / foreach must Module-verify and match Zend.
 *
 * Three separate objects (issue table). Avoid json_encode of foreach-built arrays —
 * use implode so output matches Zend byte-for-byte under thin AOT.
 */
$p = sys_get_temp_dir().'/phpc_sfo_current_33521_'.getmypid().'.txt';
file_put_contents($p, "a\nb\n");

$o1 = new SplFileObject($p);
echo 'current=['.$o1->current()."]\n";

$o2 = new SplFileObject($p);
$o2->seek(1);
echo 'seek1=['.$o2->current()."]\n";

$o3 = new SplFileObject($p);
$parts = [];
foreach ($o3 as $k => $v) {
    $parts[] = $k.':['.$v.']';
}
echo 'foreach='.implode('|', $parts)."\n";

@unlink($p);
