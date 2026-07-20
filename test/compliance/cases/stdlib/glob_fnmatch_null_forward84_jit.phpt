--TEST--
JIT: glob()/fnmatch() null pattern DEP+coerce on 8.4 forward profile (#20554, #21366, ext/standard/file.c, fnmatch.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['glob' => fn () => glob(null), 'fnmatch' => fn () => fnmatch(null, 'a')] as $name => $fn) {
    try {
        $fn();
        echo $name, " COERCED\n";
    } catch (TypeError $e) {
        echo $name, " TypeError\n";
    }
}
?>
--EXPECT--
glob COERCED
fnmatch COERCED
