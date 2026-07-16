--TEST--
stdlib strcmp family null TypeError on 8.4 forward profile JIT (#19298, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    'strcmp' => static fn () => strcmp(null, 'a'),
    'strcasecmp' => static fn () => strcasecmp(null, 'a'),
    'strncmp' => static fn () => strncmp(null, 'a', 1),
    'strncasecmp' => static fn () => strncasecmp(null, 'a', 1),
    'strcoll' => static fn () => strcoll(null, 'a'),
    'strnatcmp' => static fn () => strnatcmp(null, 'a'),
    'strnatcasecmp' => static fn () => strnatcasecmp(null, 'a'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
strcmp: strcmp(): Argument #1 ($string1) must be of type string, null given
strcasecmp: strcasecmp(): Argument #1 ($string1) must be of type string, null given
strncmp: strncmp(): Argument #1 ($string1) must be of type string, null given
strncasecmp: strncasecmp(): Argument #1 ($string1) must be of type string, null given
strcoll: strcoll(): Argument #1 ($string1) must be of type string, null given
strnatcmp: strnatcmp(): Argument #1 ($string1) must be of type string, null given
strnatcasecmp: strnatcasecmp(): Argument #1 ($string1) must be of type string, null given
