--TEST--
stdlib nl_langinfo() invalid item warns then false (#29459)
--FILE--
<?php
error_reporting(E_ALL);
foreach ([0, -1] as $item) {
    echo "item=$item\n";
    var_export(nl_langinfo($item));
    echo "\n";
}
echo "codeset=", nl_langinfo(CODESET) !== false ? 'ok' : 'fail', "\n";
--EXPECTF--
PHP Warning:  nl_langinfo(): Item '0' is not valid in %s on line %d
PHP Warning:  nl_langinfo(): Item '-1' is not valid in %s on line %d
item=0
false
item=-1
false
codeset=ok
