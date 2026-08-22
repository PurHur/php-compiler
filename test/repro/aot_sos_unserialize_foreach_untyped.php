<?php
// AOT: foreach after unserialize(serialize(SOS)) without typed param must not SEGV (#33876 residual)
$s = new SplObjectStorage();
$o = new stdClass();
$s->attach($o, 42);
$u = unserialize(serialize($s));
echo count($u), "\n";
foreach ($u as $obj) {
    echo get_class($obj), ":", $u[$obj], "\n";
}
