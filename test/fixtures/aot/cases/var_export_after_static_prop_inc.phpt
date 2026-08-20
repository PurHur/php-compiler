--TEST--
AOT: var_export after method self::$n++ (#32750)
--FILE--
<?php
class C {
    public static $n = 0;
    public static function inc(): void
    {
        self::$n++;
    }
}
C::inc();
var_export(C::$n);
echo "\n";
var_export(1.0);
echo "\n";
?>
--EXPECT--
1
1.0
