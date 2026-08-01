<?php
declare(strict_types=1);

// #26235 — utf8_encode/utf8_decode Reflection param string (php-src basic_functions.stub.php)
try {
    echo bin2hex(utf8_encode(string: "\xA0")), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo bin2hex(utf8_decode(string: "\xc2\xa0")), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
foreach (['utf8_encode', 'utf8_decode'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, '=', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
}
