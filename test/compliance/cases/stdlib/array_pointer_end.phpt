--TEST--
stdlib: end()/key()/current() internal pointer after end() (#10567, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = ['a' => 1, 'b' => 2, 'c' => 3];
end($a);
echo 'after_end:' . key($a) . '=' . current($a) . "\n";

$b = [10, 20, 30];
end($b);
echo 'numeric:' . key($b) . '=' . current($b) . "\n";

end($b);
prev($b);
echo 'after_prev:' . key($b) . "\n";
--EXPECT--
after_end:c=3
numeric:2=30
after_prev:1
--CREDITS--
PurHur/php-compiler issue #10567
