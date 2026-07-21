--TEST--
stdlib PharData metadata get/set/has/del (#21691)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);

foreach (['setMetadata', 'getMetadata', 'hasMetadata', 'delMetadata'] as $m) {
    echo $m, ' ', method_exists(PharData::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/phardata21691_c_' . getmypid() . '_' . mt_rand();
@mkdir($dir, 0777, true);
$tarPath = $dir . '/app.tar';
@unlink($tarPath);

$p = new PharData($tarPath);
$p['a.txt'] = 'hi';
echo 'idle_has=', $p->hasMetadata() ? 'Y' : 'N', "\n";
$p->setMetadata(['k' => 1, 's' => 'v']);
echo 'has=', $p->hasMetadata() ? 'Y' : 'N', "\n";
$meta = $p->getMetadata();
echo 'meta=', is_array($meta) && ($meta['k'] ?? null) === 1 && ($meta['s'] ?? null) === 'v' ? 'Y' : 'N', "\n";
unset($p);

$p2 = new PharData($tarPath);
echo 'reopen_has=', $p2->hasMetadata() ? 'Y' : 'N', "\n";
$meta2 = $p2->getMetadata();
echo 'reopen_meta=', is_array($meta2) && ($meta2['k'] ?? null) === 1 ? 'Y' : 'N', "\n";
$p2->delMetadata();
echo 'del_has=', $p2->hasMetadata() ? 'Y' : 'N', "\n";
unset($p2);

$p3 = new PharData($tarPath);
echo 'after_del_has=', $p3->hasMetadata() ? 'Y' : 'N', "\n";
var_dump($p3->getMetadata());

$p3->setMetadata(null);
echo 'null_has=', $p3->hasMetadata() ? 'Y' : 'N', "\n";
var_dump($p3->getMetadata());

echo "ok\n";
--EXPECT--
setMetadata Y
getMetadata Y
hasMetadata Y
delMetadata Y
idle_has=N
has=Y
meta=Y
reopen_has=Y
reopen_meta=Y
del_has=N
after_del_has=N
NULL
null_has=Y
NULL
ok
