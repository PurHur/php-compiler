<?php
// AOT: var_export after method self::$n++ must compile (#32750 / re-#32747).
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
