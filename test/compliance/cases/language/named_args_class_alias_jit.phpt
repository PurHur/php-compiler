--TEST--
class_alias named class/alias arguments (JIT, issue #23422)
--FILE--
<?php
class Orig {}
class_alias(class: 'Orig', alias: 'Alias1');
echo class_exists('Alias1') ? 'Y' : 'N', PHP_EOL;
--EXPECT--
Y
