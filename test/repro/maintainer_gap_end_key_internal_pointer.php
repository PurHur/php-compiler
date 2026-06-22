<?php
/**
 * Parity: end()/key()/current() must move/read array internal pointer.
 * After end(), key() must return the last key; current() the last value.
 */
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
