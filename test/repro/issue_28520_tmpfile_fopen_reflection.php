<?php
foreach (['tmpfile', 'fopen'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ', $r->hasReturnType() ? (string) $r->getReturnType() : 'untyped', "\n";
}
