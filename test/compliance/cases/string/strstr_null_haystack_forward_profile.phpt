--TEST--
stdlib PHP 8.4 profile — str* search haystack null TypeError (#19242)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
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
strpos: TypeError
stripos: TypeError
strrpos: TypeError
strripos: TypeError
strstr: TypeError
stristr: TypeError
strchr: TypeError
strrchr: TypeError
strpbrk: TypeError
strtok: TypeError
