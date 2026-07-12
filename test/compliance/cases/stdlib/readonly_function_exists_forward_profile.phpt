--TEST--
stdlib readonly() function_exists on PHP 8.4 forward profile (#17693, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

putenv('PHP_COMPILER_PROFILE=8.4');
echo function_exists('readonly') ? "yes\n" : "no\n";
$o = (object) ['x' => 1];
readonly($o);
try {
    $o->x = 2;
    echo "mutated\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
yes
Cannot modify readonly object of class stdClass
