--TEST--
filter FILTER_THROW_ON_FAILURE + Filter\* exceptions under PROFILE≥8.5 (#28131)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.5');
$_ENV['PHP_COMPILER_PROFILE'] = '8.5';
if (!PHPCompiler\CompilerVersion::supportsFilterThrowOnFailure()) {
    die('skip requires PHP_COMPILER_PROFILE≥8.5 FILTER_THROW_ON_FAILURE');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
echo 'defined=', defined('FILTER_THROW_ON_FAILURE') ? 'Y' : 'N', "\n";
echo 'FilterException=', class_exists('Filter\\FilterException', false) ? 'Y' : 'N', "\n";
echo 'FilterFailedException=', class_exists('Filter\\FilterFailedException', false) ? 'Y' : 'N', "\n";

try {
    $v = filter_var('nope', FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE);
    echo 'fail_path=', var_export($v, true), " NO_THROW\n";
} catch (Throwable $e) {
    echo 'fail_path=THROW ', $e::class, ':', $e->getMessage(), "\n";
}

$ok = filter_var('12', FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE);
echo 'ok_path=', var_export($ok, true), "\n";

try {
    filter_var('12', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE | FILTER_THROW_ON_FAILURE);
    echo "combo NO_THROW\n";
} catch (Throwable $e) {
    echo 'combo=THROW ', $e::class, ':', $e->getMessage(), "\n";
}
--EXPECT--
defined=Y
FilterException=Y
FilterFailedException=Y
fail_path=THROW Filter\FilterFailedException:filter validation failed: filter int not satisfied by 'nope'
ok_path=12
combo=THROW ValueError:filter_var(): Argument #3 ($options) cannot use both FILTER_NULL_ON_FAILURE and FILTER_THROW_ON_FAILURE
