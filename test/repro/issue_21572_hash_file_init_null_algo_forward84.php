<?php
/**
 * Repro #21572 — hash_file()/hash_init(null $algo) DEP+ValueError under PROFILE=8.4
 * (not TypeError; Zend 8.4.23 php-src-strict).
 */
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
        return true;
    }
    return false;
});
foreach (['hash_file', 'hash_init'] as $f) {
    echo $f, ':';
    try {
        if ('hash_file' === $f) {
            hash_file(null, '/etc/hosts');
        } else {
            hash_init(null);
        }
        echo "OK\n";
    } catch (ValueError $e) {
        echo "VE\n";
    } catch (TypeError $e) {
        echo "TE\n";
    }
}
echo 'depr=', (int) ($seen >= 2), "\n";
