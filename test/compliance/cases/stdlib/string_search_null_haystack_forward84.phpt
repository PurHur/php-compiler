--TEST--
stdlib haystack-search builtins null soft-null on 8.4 forward profile (#21444, #21189 siblings)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'strtok' => static fn () => strtok(null, '.'),
    'strrpos' => static fn () => strrpos(null, 'x'),
    'strpbrk' => static fn () => strpbrk(null, 'abc'),
    'strstr' => static fn () => strstr(null, 'x'),
    'stristr' => static fn () => stristr(null, 'x'),
    'strrchr' => static fn () => strrchr(null, 'x'),
    'strchr' => static fn () => strchr(null, 'x'),
    'stripos' => static fn () => stripos(null, 'x'),
    'strripos' => static fn () => strripos(null, 'x'),
] as $label => $factory) {
    try {
        $r = $factory();
        echo "$label: ", false === $r ? 'false' : 'other', "\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
strtok: strtok(): Argument #1 ($string) must be of type string, null given
strrpos: false
strpbrk: false
strstr: false
stristr: false
strrchr: false
strchr: false
stripos: false
strripos: false
