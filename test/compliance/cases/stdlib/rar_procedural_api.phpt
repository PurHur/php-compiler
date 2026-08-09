--TEST--
stdlib rar_* procedural API when rar advertised (#27878, PECL rar)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['rar_open','rar_list','rar_entry_get','rar_solid_is','rar_comment_get','rar_broken_is','rar_allow_broken_set','rar_close'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}
$missing = rar_open('/tmp/phpc-definitely-missing.rar');
echo 'missing=', ($missing === false) ? 'Y' : 'N', "\n";
--EXPECT--
rar_open=Y
rar_list=Y
rar_entry_get=Y
rar_solid_is=Y
rar_comment_get=Y
rar_broken_is=Y
rar_allow_broken_set=Y
rar_close=Y
missing=Y
