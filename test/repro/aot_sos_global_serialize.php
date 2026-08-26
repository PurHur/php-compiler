<?php
// #35122 — global serialize(SplObjectStorage) under thin AOT
echo serialize(new SplObjectStorage()), "\n";
$s = new SplObjectStorage();
$s->attach(new stdClass(), 42);
echo serialize($s), "\n";
$s2 = new SplObjectStorage();
$s2->attach(new stdClass(), 'info');
echo serialize($s2), "\n";
