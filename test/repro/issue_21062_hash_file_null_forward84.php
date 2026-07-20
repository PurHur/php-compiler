<?php
/**
 * Issue #21235 — null path soft-null DEP+coerce then empty-path ValueError on PROFILE=8.4
 * (php-src file.c / image.c / hash.c; supersedes #21062 / #21076 TypeError expectations).
 */
foreach ([
    'md5_file' => fn () => md5_file(null),
    'sha1_file' => fn () => sha1_file(null),
    'hash_file' => fn () => hash_file('md5', null),
    'fopen' => fn () => fopen(null, 'r'),
    'file_get_contents' => fn () => file_get_contents(null),
    'getimagesize' => fn () => getimagesize(null),
] as $n => $f) {
    try {
        $f();
        echo "$n=OK\n";
    } catch (TypeError $e) {
        echo "$n=TYPEERROR\n";
    } catch (ValueError $e) {
        echo "$n=VALUEERROR\n";
    }
}
try {
    // Non-literal empty so AOT does not abort at compile time on rejectEmpty().
    $empty = substr('x', 1);
    md5_file($empty);
    echo "md5_file_empty=OK\n";
} catch (ValueError $e) {
    echo "md5_file_empty=VALUEERROR\n";
} catch (TypeError $e) {
    echo "md5_file_empty=TYPEERROR\n";
}
