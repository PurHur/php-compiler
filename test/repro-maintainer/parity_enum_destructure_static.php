<?php
enum Color: string { case Red = 'r'; case Blue = 'b'; }
[$a, $b] = [Color::Red, Color::Blue];
var_dump($a, $b);
class S {
    public function go(): void {
        static $e = Color::Red;
        var_dump($e);
    }
}
(new S())->go();
