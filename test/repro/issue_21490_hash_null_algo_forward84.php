<?php
/**
 * Repro #21490 — hash()/hash_hmac(null $algo) soft-null under PROFILE=8.4
 * (not TypeError; Zend 8.4.23 php-src-strict).
 *
 * VM/JIT: DEP + ValueError (invalid algo).
 * AOT smoke: test/fixtures/aot/cases/hash_null_algo_forward84.phpt
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
foreach (['hash', 'hash_hmac'] as $f) {
    echo $f, ':';
    try {
        if ('hash' === $f) {
            hash(null, 'x');
        } else {
            hash_hmac(null, 'x', 'k');
        }
        echo "OK\n";
    } catch (ValueError $e) {
        echo "VE\n";
    } catch (TypeError $e) {
        echo "TE\n";
    }
}
echo 'depr=', (int) ($seen >= 2), "\n";
