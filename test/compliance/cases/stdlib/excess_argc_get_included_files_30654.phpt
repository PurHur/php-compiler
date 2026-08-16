--TEST--
stdlib get_included_files/get_required_files excess argc ACE exactly 0 (#30654)
--FILE--
<?php
foreach (['get_included_files', 'get_required_files'] as $f) {
    try {
        $f(1);
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
ArgumentCountError: get_included_files() expects exactly 0 arguments, 1 given
ArgumentCountError: get_required_files() expects exactly 0 arguments, 1 given
