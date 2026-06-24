--TEST--
stdlib parse_ini_string() INI_SCANNER_NORMAL — bool keyword keys warn + false (#11025, ext/standard/ini.c)
--FILE--
<?php
foreach (['on=on', 'off=off', 'yes=yes', 'no=no', 'true=true', 'false=false', 'null=null', 'none=none'] as $ini) {
    $result = @parse_ini_string($ini);
    var_export($result);
    echo "\n";
}
var_export(parse_ini_string("debug = on\nenabled=on"));
echo "\n";
--EXPECT--
false
false
false
false
false
false
false
false
array (
  'debug' => '1',
  'enabled' => '1',
)
