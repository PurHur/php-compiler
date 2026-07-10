--TEST--
Stdlib: preg_match()/preg_replace() duplicate named subpatterns — compile must fail (#17584, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if (E_WARNING === $severity) {
        $warnings[] = $message;
    }

    return true;
});

$m = [];
$match = preg_match('/(?<x>a)(?<x>b)/', 'ab', $m);
$replace = preg_replace('/(?<x>a)(?<x>b)/', 'X', 'ab');

restore_error_handler();

echo var_export($match, true), "\n";
echo var_export($replace, true), "\n";
echo preg_last_error(), "\n";
echo $warnings[0] ?? 'none', "\n";
echo $warnings[1] ?? 'none', "\n";
?>
--EXPECT--
false
NULL
1
preg_match(): Compilation failed: two named subpatterns have the same name (PCRE2_DUPNAMES not set) at offset 12
preg_replace(): Compilation failed: two named subpatterns have the same name (PCRE2_DUPNAMES not set) at offset 12
