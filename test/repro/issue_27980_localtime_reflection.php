<?php
declare(strict_types=1);

/**
 * Repro for #27980 — localtime Reflection ?int $timestamp = null (ext/date/php_date.stub.php).
 */
$r = new ReflectionFunction('localtime');
foreach ($r->getParameters() as $p) {
    $d = '';
    if ($p->isOptional()) {
        try {
            $d = '='.var_export($p->getDefaultValue(), true);
        } catch (Throwable $e) {
            $d = '=<?>';
        }
    }
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : '?', $d, "\n";
}
$a = localtime(timestamp: null, associative: true);
echo 'named_ok=', array_key_exists('tm_year', $a) ? '1' : '0', "\n";
