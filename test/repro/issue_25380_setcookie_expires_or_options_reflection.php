<?php
/**
 * #25380 — setcookie/setrawcookie Reflection: expires_or_options must be array|int
 * php-src: ext/standard/head.stub.php
 */
foreach (['setcookie', 'setrawcookie'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        if ($p->getName() !== 'expires_or_options') {
            continue;
        }
        echo $fn, '|', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
    }
}
// Runtime already accepts options-array 3rd arg (no TypeError); CLI may return false if headers sent.
try {
    @setcookie('n', 'v', ['expires' => 0]);
    echo "options_array_call=ok\n";
} catch (Throwable $e) {
    echo 'options_array_call=', $e::class, "\n";
}
