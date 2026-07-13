<?php
class C { public $prop = 42; }

$o = null;

echo "var_export:\n";
var_export($o?->prop);
echo "\n";

echo "json_encode:\n";
echo json_encode($o?->prop);
echo "\n";

echo "assigned:\n";
$x = $o?->prop;
var_export($x);
echo "\n";

echo "coalesce:\n";
var_export($o?->prop ?? 'fallback');
echo "\n";

echo "array_literal:\n";
var_export([$o?->prop]);
echo "\n";
