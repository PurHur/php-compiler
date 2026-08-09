<?php
declare(strict_types=1);
foreach (['preg_replace', 'preg_filter', 'preg_replace_callback'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
echo 'named=', preg_replace(pattern: '/a/', replacement: 'b', subject: 'a'), "\n";
