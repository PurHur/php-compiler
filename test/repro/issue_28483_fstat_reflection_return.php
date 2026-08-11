<?php
$r = new ReflectionFunction('fstat');
echo $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
