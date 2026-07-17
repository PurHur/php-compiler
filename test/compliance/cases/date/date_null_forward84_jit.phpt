--TEST--
JIT date idate()/strftime()/date_parse()/mktime()/gmmktime(null) TypeError on 8.4 forward profile (#20227)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    'idate' => static fn () => @idate(null),
    'strftime' => static fn () => @strftime(null),
    'date_parse' => static fn () => date_parse(null),
    'mktime' => static fn () => mktime(null),
    'gmmktime' => static fn () => gmmktime(null),
] as $name => $call) {
    try {
        $call();
        echo "{$name}: COERCE\n";
    } catch (TypeError $e) {
        echo "{$name}: TypeError\n";
    }
}
?>
--EXPECT--
idate: TypeError
strftime: TypeError
date_parse: TypeError
mktime: TypeError
gmmktime: TypeError
