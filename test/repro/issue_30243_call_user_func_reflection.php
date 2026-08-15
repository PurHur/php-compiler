<?php
declare(strict_types=1);

// Issue #30243 — call_user_func* Reflection return mixed + mixed ...$args
foreach (['call_user_func', 'call_user_func_array'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(),
            ' ty=', $p->hasType() ? (string) $p->getType() : '-',
            ' var=', (int) $p->isVariadic(),
            "\n";
    }
}

// Named callback: + runtime invoke (php-src-strict)
echo 'named=', call_user_func(callback: 'phpversion') !== false ? 'ok' : 'no', "\n";
echo 'invoke=', call_user_func('strlen', 'xy'), "\n";
echo 'array=', call_user_func_array(callback: 'strlen', args: ['z']), "\n";
