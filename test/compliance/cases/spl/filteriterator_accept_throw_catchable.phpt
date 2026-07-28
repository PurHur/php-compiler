--TEST--
FilterIterator/RecursiveFilterIterator accept() throw is user-catchable (#24286, #24297)
--FILE--
<?php
class F extends FilterIterator {
    public function accept(): bool {
        throw new Exception('no');
    }
}
try {
    foreach (new F(new ArrayIterator([1, 2])) as $v) {
        echo "v=$v\n";
    }
} catch (Exception $e) {
    echo 'Exception: ', $e->getMessage(), "\n";
}

class RF extends RecursiveFilterIterator {
    public function accept(): bool {
        throw new Exception('rej');
    }
}
try {
    foreach (new RF(new RecursiveArrayIterator([1, [2]])) as $v) {
        echo "rv=";
        var_export($v);
        echo "\n";
    }
} catch (Exception $e) {
    echo 'Exception: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
Exception: no
Exception: rej
