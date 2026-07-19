<?php
/**
 * Issue #21062 / #21076 — null path TypeError on PROFILE=8.4 before empty-path ValueError
 * (php-src basic_functions.stub.php / file.stub.php / hash.stub.php).
 *
 * Empty-string ValueError is checked separately (compile-time '' aborts AOT lowering).
 */
foreach ([
    'md5_file' => fn () => md5_file(null),
    'sha1_file' => fn () => sha1_file(null),
    'hash_file' => fn () => hash_file('md5', null),
    'fopen' => fn () => fopen(null, 'r'),
    'file_get_contents' => fn () => file_get_contents(null),
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
