<?php
/**
 * Repro #23920 — str_word_count() Zend stub named $string/$format/$characters
 * (php-src ext/standard/string.stub.php). InternalArgInfo still says charlist.
 */
$names = [];
$opts = [];
foreach ((new ReflectionFunction('str_word_count'))->getParameters() as $p) {
    $names[] = $p->getName();
    $opts[] = $p->isOptional() ? '=' : '';
}
$named = str_word_count(string: 'a-b', format: 0, characters: '-');
$pos = str_word_count('a-b', 0, '-');
$legacy = 'uncaught';
try {
    str_word_count(string: 'a-b', format: 0, charlist: '-');
    $legacy = 'accepted';
} catch (Throwable $e) {
    $legacy = $e->getMessage();
}
$ok = ['string', 'format', 'characters'] === $names
    && ['', '=', '='] === $opts
    && 1 === $named
    && 1 === $pos
    && str_contains($legacy, 'Unknown named parameter $charlist');
echo $ok ? "ok\n" : ('fail names='.implode(',', $names)
    .' opts='.implode('', $opts)
    ." named=$named pos=$pos legacy=$legacy\n");
