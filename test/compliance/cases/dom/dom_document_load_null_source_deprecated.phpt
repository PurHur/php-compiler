--TEST--
DOMDocument::loadHTML/loadXML(null) — Deprecated then ValueError empty (#22680)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});
foreach (['loadHTML', 'loadXML'] as $m) {
    $d = new DOMDocument();
    try {
        var_export($d->$m(null));
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
DEP:DOMDocument::loadHTML(): Passing null to parameter #1 ($source) of type string is deprecated
ValueError:DOMDocument::loadHTML(): Argument #1 ($source) must not be empty
DEP:DOMDocument::loadXML(): Passing null to parameter #1 ($source) of type string is deprecated
ValueError:DOMDocument::loadXML(): Argument #1 ($source) must not be empty
