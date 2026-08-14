--TEST--
mb_strtolower/mb_strtoupper excess argc → ArgumentCountError at most (#31036)
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
    try {
        $fn();
        echo "$fn: NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo mb_strtolower('AbC'), "\n";
echo mb_strtoupper('AbC'), "\n";
?>
--EXPECT--
mb_strtolower() expects at most 2 arguments, 3 given
mb_strtolower() expects at least 1 argument, 0 given
mb_strtoupper() expects at most 2 arguments, 3 given
mb_strtoupper() expects at least 1 argument, 0 given
abc
ABC
