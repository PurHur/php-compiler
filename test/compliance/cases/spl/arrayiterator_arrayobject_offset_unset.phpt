--TEST--
SPL ArrayIterator/ArrayObject offsetUnset clears offsetExists/isset (#22322, ext/spl/spl_array.c)
--FILE--
<?php
$a = new ArrayIterator(['x' => 1, 'w' => 9]);
unset($a['w']);
echo 'AI_isset=', isset($a['w']) ? 'Y' : 'N', ' exists=', $a->offsetExists('w') ? 'Y' : 'N', ' count=', $a->count(), "\n";
$o = new ArrayObject(['x' => 1, 'w' => 9]);
unset($o['w']);
echo 'AO_isset=', isset($o['w']) ? 'Y' : 'N', ' exists=', $o->offsetExists('w') ? 'Y' : 'N', ' count=', $o->count(), "\n";
// Null value still exists for offsetExists; unset must remove it (#22322 HashTable path).
$n = new ArrayObject(['w' => null]);
echo 'null_before=', $n->offsetExists('w') ? 'Y' : 'N', "\n";
unset($n['w']);
echo 'null_after=', $n->offsetExists('w') ? 'Y' : 'N', ' isset=', isset($n['w']) ? 'Y' : 'N', "\n";
?>
--EXPECT--
AI_isset=N exists=N count=1
AO_isset=N exists=N count=1
null_before=Y
null_after=N isset=N
