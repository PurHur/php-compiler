<?php
$s = new SplObjectStorage();
$a = new stdClass();
$b = new stdClass();
$s->attach($a);
$s->attach($b);
$s->rewind();
echo 'k0=', var_export($s->key(), true), "\n";
$s->next();
echo 'k1=', var_export($s->key(), true), "\n";
$s->next();
echo 'k2=', var_export($s->key(), true), ' valid=', var_export($s->valid(), true), "\n";
