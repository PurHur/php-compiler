--TEST--
stdlib parse_ini_string() runtime ini inside foreach — no silent exit (#18442, ext/standard/ini.c)
--FILE--
<?php
foreach (['on', 'off'] as $v) {
    $ini = "flag = $v";
    $parsed = parse_ini_string($ini);
    echo "parse_ini_$v:";
    var_export($parsed['flag'] ?? null);
    echo "\n";
}
echo "done\n";
--EXPECT--
parse_ini_on:'1'
parse_ini_off:''
done
