--TEST--
JIT: glob()/fnmatch() null pattern DEP cites parameter #1 on 8.4 (#20554, #21366, #29659, #29660, ext/standard/file.c, fnmatch.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$g = glob(null);
echo 'glob COERCED ', var_export($g, true), "\n";
$f = fnmatch(null, 'a');
echo 'fnmatch COERCED ', var_export($f, true), "\n";
?>
--EXPECT--
ERR[8192]: glob(): Passing null to parameter #1 ($pattern) of type string is deprecated
glob COERCED array (
)
ERR[8192]: fnmatch(): Passing null to parameter #1 ($pattern) of type string is deprecated
fnmatch COERCED false
