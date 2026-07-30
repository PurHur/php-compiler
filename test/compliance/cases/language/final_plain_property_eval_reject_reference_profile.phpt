--TEST--
Language: eval() final plain property rejected on reference profile (#25535, re-#25322, Zend/zend_compile.c)
--SKIPIF--
<?php
// Key off PROFILE env — not supportsFinalProperties(). If the gate wrongly
// returns true on the reference profile, skipping would hide the regression (#24895).
$raw = getenv('PHP_COMPILER_PROFILE');
if (is_string($raw) && '' !== trim($raw)) {
    $v = trim($raw);
    if (preg_match('/^\d+\.\d+$/', $v)) {
        $v .= '.0';
    }
    if (version_compare($v, '8.4.0', '>=')) {
        die('skip final properties enabled on PHP 8.4+ forward profile');
    }
}
?>
--FILE--
<?php
try {
    eval('class T { final public int $x = 1; }');
    echo "parsed_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Cannot declare property T::$x final, the final modifier is allowed only for methods, classes, and class constants in %s : eval()'d code on line %d
