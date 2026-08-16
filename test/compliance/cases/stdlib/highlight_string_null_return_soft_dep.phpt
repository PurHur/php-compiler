--TEST--
stdlib highlight_string(null $return) soft DEP+coerce outside strict_types (#31383, ext/standard/url_scanner_ex.re)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    $r = highlight_string('<?php', null);
    echo true === $r ? "ok\n" : "bad\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: highlight_string(): Passing null to parameter #2 ($return) of type bool is deprecated in %s on line %d
%Aok
