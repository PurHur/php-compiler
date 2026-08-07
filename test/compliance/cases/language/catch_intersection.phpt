--TEST--
Language: catch (A&B $e) / catch ((A&B) $e) ParseError like Zend (#28439, Zend/zend_language_parser.y catch_name_list)
--FILE--
<?php
interface A {}
interface B {}
class E extends Exception implements A, B {}

foreach (['catch (A&B $e) { echo "and\n"; }', 'catch ((A&B) $e) { echo "paren\n"; }', 'catch (A|B $e) { echo "or\n"; }'] as $arm) {
    try {
        eval('try { throw new E("x"); } '.$arm);
    } catch (Throwable $e) {
        echo get_class($e), ':', explode("\n", $e->getMessage())[0], "\n";
    }
}
--EXPECTF--
ParseError:%Asyntax error, unexpected token "&"%A
ParseError:%Asyntax error, unexpected token "("%A
or
