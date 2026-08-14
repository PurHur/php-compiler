--TEST--
mb_strtolower/mb_strtoupper excess argc JIT → ArgumentCountError (#31036)
--FILE--
<?php
foreach (['mb_strtolower', 'mb_strtoupper'] as $fn) {
    try {
        $fn('ab', 'UTF-8', 'x');
        echo "$fn: NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
mb_strtolower() expects at most 2 arguments, 3 given
mb_strtoupper() expects at most 2 arguments, 3 given
