<?php
/**
 * Repro #23919 — str_shuffle() Zend stub named $string (php-src string.stub.php).
 * Shuffle is non-deterministic; assert length + permutation, not exact bytes.
 */
$names = [];
foreach ((new ReflectionFunction('str_shuffle'))->getParameters() as $p) {
    $names[] = $p->getName();
}
$named = str_shuffle(string: 'ab');
$pos = str_shuffle('ab');
$sig = static function (string $s): string {
    $parts = str_split($s);
    sort($parts);

    return implode('', $parts);
};
$legacy = 'uncaught';
try {
    str_shuffle(str: 'ab');
    $legacy = 'accepted';
} catch (Throwable $e) {
    $legacy = $e->getMessage();
}
$ok = ['string'] === $names
    && 2 === strlen($named)
    && 2 === strlen($pos)
    && $sig('ab') === $sig($named)
    && $sig('ab') === $sig($pos)
    && str_contains($legacy, 'Unknown named parameter $str');
echo $ok ? "ok\n" : "fail names=".implode(',', $names)." named_len=".strlen($named)." legacy=$legacy\n";
