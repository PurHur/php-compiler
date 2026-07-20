--TEST--
stdlib PHP 8.4 profile — str* search haystack null (#19242/#21189 soft for strpos/strstr)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (): bool { return true; });
foreach (['strpos', 'stripos', 'strrpos', 'strripos', 'strstr', 'stristr', 'strchr', 'strrchr', 'strpbrk', 'strtok'] as $f) {
    try {
        $r = $f === 'strtok' ? strtok(null, '.') : $f(null, 'x');
        echo "$f: OK ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo "$f: TypeError\n";
    }
}
?>
--EXPECT--
strpos: OK false
stripos: OK false
strrpos: OK false
strripos: OK false
strstr: OK false
stristr: OK false
strchr: OK false
strrchr: OK false
strpbrk: OK false
strtok: TypeError
