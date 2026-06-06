--TEST--
Language: new readonly class is parse error (#6903, zend_language_parser.y)
--FILE--
<?php
$o = new readonly class {
    public string $x = 'a';
};
echo $o->x, "\n";
--EXPECT_EXIT--
255
