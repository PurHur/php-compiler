<?php
class C {
    public static array $a;
    public static ?array $n;
}
echo 'isset0=', isset(C::$a[0]) ? '1' : '0', "\n";
echo 'empty0=', empty(C::$a[0]) ? '1' : '0', "\n";
echo 'coalesce=';
var_export(C::$a[0] ?? 'd');
echo "\n";
echo 'issetn=', isset(C::$n['k']) ? '1' : '0', "\n";
echo "after\n";
