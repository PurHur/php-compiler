--TEST--
stdlib parse_ini_string() inside foreach loop (#18442)
--FILE--
<?php
foreach (['on', 'off'] as $v) {
    $r = parse_ini_string("flag = $v");
    echo 'parse_ini_'.$v.':'.var_export($r['flag'] ?? null, true)."\n";
}
echo "done\n";
--EXPECT--
parse_ini_on:'1'
parse_ini_off:''
done
