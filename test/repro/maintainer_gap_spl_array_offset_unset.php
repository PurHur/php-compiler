<?php
// repro: ArrayIterator/ArrayObject offsetUnset leaves offsetExists true
$a = new ArrayIterator(['x' => 1, 'w' => 9]);
unset($a['w']);
echo 'AI_isset=', isset($a['w']) ? 'Y' : 'N', "\n";
echo 'AI_exists=', $a->offsetExists('w') ? 'Y' : 'N', "\n";
echo 'AI_count=', $a->count(), "\n";
$o = new ArrayObject(['x' => 1, 'w' => 9]);
unset($o['w']);
echo 'AO_isset=', isset($o['w']) ? 'Y' : 'N', "\n";
echo 'AO_exists=', $o->offsetExists('w') ? 'Y' : 'N', "\n";
echo 'AO_count=', $o->count(), "\n";
