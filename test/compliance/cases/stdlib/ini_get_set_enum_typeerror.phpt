--TEST--
stdlib ini_get()/ini_set() — enum case option operands TypeError (#5915, ext/standard/basic_functions.c)
--FILE--
<?php
enum Es: string { case B = 'display_errors'; }
enum Eu { case B; }

foreach (['backed' => Es::B, 'unit' => Eu::B] as $label => $option) {
    try {
        ini_get($option);
        echo "ini_get {$label} uncaught\n";
    } catch (TypeError $e) {
        echo "ini_get {$label} TypeError\n";
    } catch (Error $e) {
        echo "ini_get {$label} Error\n";
    }
    try {
        ini_set($option, '1');
        echo "ini_set {$label} uncaught\n";
    } catch (TypeError $e) {
        echo "ini_set {$label} TypeError\n";
    } catch (Error $e) {
        echo "ini_set {$label} Error\n";
    }
}

$val = ini_get('display_errors');
echo is_string($val) ? "string-ok\n" : "string-bad\n";
--EXPECT--
ini_get backed TypeError
ini_set backed TypeError
ini_get unit TypeError
ini_set unit TypeError
string-ok
