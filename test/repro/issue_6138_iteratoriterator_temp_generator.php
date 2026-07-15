<?php

declare(strict_types=1);

/**
 * #6138 — IteratorIterator / LimitIterator must keep temporary Generator receivers alive.
 *
 * php-src: ext/spl/spl_iterators.c — outer wrappers store inner for later rewind/valid.
 */
function gen(): Generator
{
    yield 'a';
    yield 'b';
}

$it = new IteratorIterator(gen());
$out = '';
foreach ($it as $v) {
    $out .= $v;
}
echo $out === 'ab' ? "ok\n" : "fail: got {$out}\n";

$limited = new LimitIterator(gen(), 0, 2);
$out = '';
foreach ($limited as $v) {
    $out .= $v;
}
echo $out === 'ab' ? "ok\n" : "fail limit: got {$out}\n";

$noRewind = new NoRewindIterator(gen());
$out = '';
foreach ($noRewind as $v) {
    $out .= $v;
}
echo $out === 'ab' ? "ok\n" : "fail norewind: got {$out}\n";
