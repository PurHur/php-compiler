<?php

/**
 * Repro #30954 — SplObjectStorage attach/contains/detach/setInfo excess argc.
 * php-src: ext/spl/spl_observer.c ZEND_PARSE_PARAMETERS_*
 */
function show(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": OK\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$s = new SplObjectStorage();
$o = new stdClass();
show('attach', static fn () => $s->attach($o, null, 'x'));
show('contains', static fn () => $s->contains($o, 1));
show('detach', static fn () => $s->detach($o, 1));

$s2 = new SplObjectStorage();
$o2 = new stdClass();
$s2->attach($o2);
$s2->rewind();
show('setInfo', static fn () => $s2->setInfo('x', 1));

show('attach_ok', static function () use ($s2, $o2) {
    $s2->attach($o2, 'info');
});
show('contains_ok', static fn () => $s2->contains($o2) ? null : throw new RuntimeException('missing'));
show('setInfo_ok', static function () use ($s2) {
    $s2->rewind();
    $s2->setInfo('y');
});
show('detach_ok', static function () use ($s2, $o2) {
    $s2->detach($o2);
});
echo 'after_detach=', $s2->contains($o2) ? '1' : '0', "\n";
