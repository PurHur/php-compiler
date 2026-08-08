--TEST--
stdlib SplObjectStorage multi-arg contains+dim+count keeps bool (#28821, ext/spl/spl_observer.c)
--FILE--
<?php
$s = new SplObjectStorage();
$o = new stdClass;
$s[$o] = 'v';
$a = $s->contains($o);
echo get_debug_type($a), "\n";
var_dump($a);
var_dump($s->contains($o), $s[$o], count($s));
var_dump($s->contains($o), $s[$o]);
var_dump($s->offsetExists($o), $s[$o]);
--EXPECT--
bool
bool(true)
bool(true)
string(1) "v"
int(1)
bool(true)
string(1) "v"
bool(true)
string(1) "v"
