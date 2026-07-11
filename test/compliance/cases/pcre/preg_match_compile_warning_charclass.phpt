--TEST--
pcre preg_match() compile failure — character class warning text (ext/pcre/php_pcre.c, #16407)
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
preg_match('/[/', 'x');
restore_error_handler();
echo $warnings[0] ?? 'none';
echo "\n";
?>
--EXPECT--
preg_match(): Compilation failed: missing terminating ] for character class at offset 1
