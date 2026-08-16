--TEST--
SplDoublyLinkedList OOB offset* throws OutOfRangeException (#31553, ext/spl/spl_dllist.c)
--FILE--
<?php
$l = new SplDoublyLinkedList();
$l->push(1);
foreach (['offsetGet' => 5, 'offsetSet' => [5, 9], 'offsetUnset' => 5] as $method => $arg) {
    try {
        if ($method === 'offsetSet') {
            $l->offsetSet($arg[0], $arg[1]);
        } elseif ($method === 'offsetUnset') {
            $l->offsetUnset($arg);
        } else {
            $l->offsetGet($arg);
        }
        echo "$method ok\n";
    } catch (Throwable $e) {
        echo $method, ' ', get_class($e), ': ', $e->getMessage(), "\n";
        echo $method, ' instanceof OutOfRangeException=', $e instanceof OutOfRangeException ? '1' : '0', "\n";
    }
}
try {
    $l[5] = 9;
    echo "dim-set ok\n";
} catch (Throwable $e) {
    echo 'dim-set ', get_class($e), ' instanceof=', $e instanceof OutOfRangeException ? '1' : '0', "\n";
}
?>
--EXPECT--
offsetGet OutOfRangeException: SplDoublyLinkedList::offsetGet(): Argument #1 ($index) is out of range
offsetGet instanceof OutOfRangeException=1
offsetSet OutOfRangeException: SplDoublyLinkedList::offsetSet(): Argument #1 ($index) is out of range
offsetSet instanceof OutOfRangeException=1
offsetUnset OutOfRangeException: SplDoublyLinkedList::offsetUnset(): Argument #1 ($index) is out of range
offsetUnset instanceof OutOfRangeException=1
dim-set OutOfRangeException instanceof=1
