<?php
/**
 * #30511 — str_increment/str_decrement callability must match function_exists under profile gate.
 * Default / PROFILE=8.2: undefined; PROFILE=8.3+: callable like Zend 8.3+.
 */
$profile = getenv('PHP_COMPILER_PROFILE');
$label = (\is_string($profile) && '' !== trim($profile)) ? trim($profile) : 'default';
echo "profile={$label}\n";
echo 'exists_inc=', function_exists('str_increment') ? '1' : '0', "\n";
echo 'exists_dec=', function_exists('str_decrement') ? '1' : '0', "\n";
try {
    $v = str_increment('a');
    echo 'call_inc=', $v, "\n";
} catch (Throwable $e) {
    echo 'call_inc=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $v = str_decrement('b');
    echo 'call_dec=', $v, "\n";
} catch (Throwable $e) {
    echo 'call_dec=', get_class($e), ': ', $e->getMessage(), "\n";
}
