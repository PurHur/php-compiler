--TEST--
Language: (object) scalar cast — stdClass.scalar property (#30098, zend_operators.c)
--FILE--
<?php
foreach ([false, true, 1, 1.5, 'hi', null] as $v) {
    var_export((object) $v);
    echo PHP_EOL;
}
$o = (object) ['a' => 1];
var_export($o);
echo PHP_EOL;
?>
--EXPECT--
(object) array(
   'scalar' => false,
)
(object) array(
   'scalar' => true,
)
(object) array(
   'scalar' => 1,
)
(object) array(
   'scalar' => 1.5,
)
(object) array(
   'scalar' => 'hi',
)
(object) array(
)
(object) array(
   'a' => 1,
)
