--TEST--
Stdlib: preg_match()/preg_replace() duplicate named subpatterns — compile failure (#17584, ext/pcre/php_pcre.c)
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

$matches = [];
$result = @preg_match('/(?<x>a)(?<x>b)/', 'ab', $matches);
var_export($result);
echo "\n";
var_export(preg_last_error());
echo "\n";
var_export($matches);
echo "\n";
var_export(preg_replace('/(?<x>a)(?<x>b)/', 'X', 'ab'));
echo "\n";
echo $warnings[0] ?? 'none';
echo "\n";
restore_error_handler();
--EXPECT--
false
1
array (
)
NULL
preg_match(): Compilation failed: two named subpatterns have the same name (PCRE2_DUPNAMES not set) at offset 12
