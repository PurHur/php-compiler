--TEST--
stdlib strtr/span/strip_tags TypeError; nl2br soft-null on 8.4 (#19284/#21180)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'strtr' => static fn () => strtr(null, 'a', 'b'),
    'strcspn' => static fn () => strcspn(null, 'a'),
    'strspn' => static fn () => strspn(null, 'a'),
    'strip_tags' => static fn () => strip_tags(null),
    'nl2br' => static fn () => nl2br(null),
] as $label => $factory) {
    try {
        $r = $factory();
        echo "$label: uncaught ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
strtr: strtr(): Argument #1 ($string) must be of type string, null given
strcspn: strcspn(): Argument #1 ($string) must be of type string, null given
strspn: strspn(): Argument #1 ($string) must be of type string, null given
strip_tags: strip_tags(): Argument #1 ($string) must be of type string, null given
nl2br: uncaught ''
