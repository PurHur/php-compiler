<?php
$r = new ReflectionFunction('file');
echo $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
