--TEST--
stdlib preg_quote(null $delimiter) silent like Zend (#29347)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$warns = 0;
set_error_handler(static function (int $severity, string $message) use (&$warns): bool {
    if (E_DEPRECATED === $severity || 8192 === $severity) {
        ++$warns;
        echo "DEP:$message\n";
    }
    return true;
});
echo preg_quote('a.*', null), "\n";
echo 'warns=', $warns, "\n";
echo preg_quote('a.*'), "\n";
echo preg_quote('a/b', '/'), "\n";
?>
--EXPECT--
a\.\*
warns=0
a\.\*
a\/b
