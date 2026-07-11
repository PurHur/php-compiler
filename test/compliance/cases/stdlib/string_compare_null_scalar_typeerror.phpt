--TEST--
stdlib string compare builtins reject null/bool operands (#10908, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'strncmp' => fn() => strncmp(null, 'a', 1),
    'version_compare' => fn() => version_compare(null, '1.0'),
    'strcmp' => fn() => strcmp(true, '1'),
] as $name => $fn) {
    try {
        $fn();
        echo "$name: no error\n";
    } catch (TypeError $e) {
        echo "$name: TypeError\n";
    }
}
?>
--EXPECT--
strncmp: TypeError
version_compare: TypeError
strcmp: TypeError
