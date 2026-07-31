--TEST--
language: catch-bound Exception survives use() and arrow capture (#25897)
--FILE--
<?php
$a = 1;
$b = 2;
$c = 3;
$d = 4;
try {
    throw new Exception('e2');
} catch (Exception $e) {
    echo 'direct=', $e->getMessage(), "\n";
    $fn = function () use ($e) {
        return $e === null ? 'NULL' : $e->getMessage();
    };
    echo 'closure=', $fn(), "\n";
    echo 'arrow=', (fn () => $e === null ? 'NULL' : $e->getMessage())(), "\n";
}
--EXPECT--
direct=e2
closure=e2
arrow=e2
