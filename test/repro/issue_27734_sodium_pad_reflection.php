<?php
/**
 * #27734 — sodium_pad()/sodium_unpad() Reflection: string $string, int $block_size → string.
 */
foreach (['sodium_pad', 'sodium_unpad'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' arity=', $r->getNumberOfParameters(),
        ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : '?', "\n";
    }
}
$padded = sodium_pad(string: 'hi', block_size: 16);
echo 'named_len=', strlen($padded), "\n";
echo 'pos_len=', strlen(sodium_pad('hi', 16)), "\n";
echo 'unpad=', sodium_unpad(string: $padded, block_size: 16), "\n";
