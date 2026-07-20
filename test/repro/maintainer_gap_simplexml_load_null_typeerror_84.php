<?php
// Historical #20352 TypeError polarity superseded by #21502 (Zend soft-null DEP+false).
// Kept as a soft-null smoke so old command lines still exit 0 under PROFILE=8.4.
error_reporting(E_ALL);
set_error_handler(static function (): bool {
    return true;
});
foreach (['simplexml_load_string', 'simplexml_load_file'] as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ':', ($r === false ? 'false' : 'other'), "\n";
    } catch (TypeError $e) {
        echo "fail {$fn} still TypeError\n";
    }
}
