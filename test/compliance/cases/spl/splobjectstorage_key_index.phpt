--TEST--
SplObjectStorage::key() returns iterator index (#24327, ext/spl/spl_observer.c)
--FILE--
<?php
$s = new SplObjectStorage();
$a = new stdClass();
$b = new stdClass();
$s->attach($a);
$s->attach($b);
$s->rewind();
echo 'k0=', $s->key(), "\n";
$s->next();
echo 'k1=', $s->key(), "\n";
$s->next();
echo 'k2=', $s->key(), ' valid=', (int) $s->valid(), "\n";
?>
--EXPECT--
k0=0
k1=1
k2=2 valid=0
