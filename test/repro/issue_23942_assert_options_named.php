<?php
// #23942 — assert_options named $option matches Zend (php-src assert.stub.php)
$rf = new ReflectionFunction('assert_options');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), "\n";
echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
echo 'named=', var_export(assert_options(option: ASSERT_ACTIVE), true), "\n";
try {
    assert_options(what: ASSERT_ACTIVE);
    echo "legacy_what=accepted\n";
} catch (Throwable $e) {
    echo 'legacy_what=', get_class($e), "\n";
}
