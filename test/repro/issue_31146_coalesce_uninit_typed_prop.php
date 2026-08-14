<?php
class C { public int $x; public ?int $z; }
$o = new C;
echo "isset="; var_export(isset($o->x)); echo "\n";
echo "coalesce="; var_export($o->x ?? "d"); echo "\n";
$o->z ??= 5;
echo "nullcoalassign=", $o->z, "\n";
class S { public static int $y; }
echo "static="; var_export(S::$y ?? "d"); echo "\n";
echo "bare=";
try {
    echo $o->x;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
