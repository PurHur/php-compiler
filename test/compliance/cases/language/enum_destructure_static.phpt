--TEST--
Language: list destructuring and function static init preserve enum cases (#5599, zend_execute.c)
--FILE--
<?php
enum Color: string { case Red = 'r'; case Blue = 'b'; }
[$a, $b] = [Color::Red, Color::Blue];
echo get_debug_type($a), "\n";
echo get_debug_type($b), "\n";
class S {
    public function go(): void {
        static $e = Color::Red;
        echo get_debug_type($e), "\n";
    }
}
(new S())->go();
enum U { case X; case Y; }
[$x, $y] = [U::X, U::Y];
echo get_debug_type($x), "\n";
echo get_debug_type($y), "\n";
?>
--EXPECT--
Color
Color
Color
U
U
