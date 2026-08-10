--TEST--
DOMXPath::query/evaluate(null) — Deprecated then Invalid expression + false (#30041)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    if (E_WARNING === $no) {
        echo "WARN:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});
$d = new DOMDocument();
$d->loadXML('<r/>');
$xp = new DOMXPath($d);
foreach (['query', 'evaluate'] as $m) {
    try {
        var_export($xp->$m(null));
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
DEP:DOMXPath::query(): Passing null to parameter #1 ($expression) of type string is deprecated
WARN:DOMXPath::query(): Invalid expression
false
DEP:DOMXPath::evaluate(): Passing null to parameter #1 ($expression) of type string is deprecated
WARN:DOMXPath::evaluate(): Invalid expression
false
