--TEST--
stdlib sprintf() %d array/object coercion (#18532, ext/standard/sprintf.c)
--FILE--
<?php
echo 'empty: ', sprintf('%d', []), "\n";
echo 'nonempty: ', sprintf('%d', [1]), "\n";
echo 'object: ', @sprintf('%d', new stdClass()), "\n";
@sprintf('%d', new stdClass());
$err = error_get_last();
echo 'warning: ', $err['message'] ?? '', "\n";
--EXPECT--
empty: 0
nonempty: 1
object: 1
warning: Object of class stdClass could not be converted to int
