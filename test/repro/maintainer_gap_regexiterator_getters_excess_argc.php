<?php
/** Maintainer gap: RegexIterator getPregFlags/getMode/getFlags excess argc → ACE (php-src-strict). */
$it = new RegexIterator(new ArrayIterator(['a']), '/a/');
foreach (['getPregFlags', 'getMode', 'getFlags'] as $m) {
    try {
        $v = $it->$m(1);
        echo $m, ' ret=';
        var_export($v);
        echo "\n";
    } catch (Throwable $e) {
        echo $m, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
