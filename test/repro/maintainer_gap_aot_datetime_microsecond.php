<?php
// #26938 — AOT DateTime::getMicrosecond/setMicrosecond must match VM/JIT.
$d = new DateTime('@0');
var_export($d->getMicrosecond()); echo "\n";
$d->setMicrosecond(123456);
var_export($d->getMicrosecond()); echo "\n";
