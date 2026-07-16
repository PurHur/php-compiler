--TEST--
PCRE preg_quote/preg_match/preg_match_all/preg_split null str/subject TypeError on 8.4 forward profile JIT (#19320)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'preg_quote' => static fn () => preg_quote(null),
    'preg_match' => static fn () => preg_match('/./', null),
    'preg_match_all' => static fn () => preg_match_all('/./', null),
    'preg_split' => static fn () => preg_split('/./', null),
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
preg_quote: preg_quote(): Argument #1 ($str) must be of type string, null given
preg_match: preg_match(): Argument #2 ($subject) must be of type string, null given
preg_match_all: preg_match_all(): Argument #2 ($subject) must be of type string, null given
preg_split: preg_split(): Argument #2 ($subject) must be of type string, null given
