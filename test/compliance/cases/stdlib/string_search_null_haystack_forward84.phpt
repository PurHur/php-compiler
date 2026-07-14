--TEST--
stdlib haystack-search builtins null TypeError on 8.4 forward profile (#18982)
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
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
strtok: strtok(): Argument #1 ($string) must be of type string, null given
strrpos: strrpos(): Argument #1 ($haystack) must be of type string, null given
strpbrk: strpbrk(): Argument #1 ($string) must be of type string, null given
strstr: strstr(): Argument #1 ($haystack) must be of type string, null given
stristr: stristr(): Argument #1 ($haystack) must be of type string, null given
strrchr: strrchr(): Argument #1 ($haystack) must be of type string, null given
strchr: strchr(): Argument #1 ($haystack) must be of type string, null given
