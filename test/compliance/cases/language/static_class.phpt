--TEST--
Language: static class (forward profile) parses and rejects instantiation (#6929, #24894, #28518)
--SKIPIF--
<?php
// Key off PROFILE env — not supportsStaticClass(). If the gate wrongly
// returns true on the reference profile, skipping would hide accept-path coverage.
$raw = getenv('PHP_COMPILER_PROFILE');
if (!is_string($raw) || '' === trim($raw)) {
    die('skip static class requires PHP_COMPILER_PROFILE=8.4');
}
$v = trim($raw);
if (preg_match('/^\d+\.\d+$/', $v)) {
    $v .= '.0';
}
if (version_compare($v, '8.4.0', '<')) {
    die('skip static class requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
static class S {
    public static int $x = 42;
    public static function m(): int {
        return self::$x;
    }
}
echo S::m(), "\n";
$rc = new ReflectionClass(S::class);
// ReflectionClass::isStatic is absent from php-src (static-class RFC unmerged) (#28518).
echo method_exists($rc, 'isStatic') ? "has-isStatic\n" : "no-isStatic\n";
try {
    new S();
    echo "instantiated\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
42
no-isStatic
Cannot instantiate static class S
