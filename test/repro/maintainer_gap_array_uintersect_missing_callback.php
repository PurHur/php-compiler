<?php

// Repro for #12643 — array_uintersect()/array_udiff() with two arrays must TypeError on callback, not ArgumentCountError.
$fail = 0;
foreach (['array_uintersect', 'array_udiff'] as $fn) {
    try {
        $result = call_user_func($fn, [1], [2]);
        unset($result);
        echo 'fail: ', $fn, '([1],[2]) succeeded unexpectedly', "\n";
        ++$fail;
    } catch (ArgumentCountError $e) {
        echo 'fail: ', $fn, ' unexpected ArgumentCountError: ', $e->getMessage(), "\n";
        ++$fail;
    } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), 'valid callback')) {
            echo 'fail: ', $fn, ' unexpected TypeError: ', $e->getMessage(), "\n";
            ++$fail;
        }
    } catch (Throwable $e) {
        echo 'fail: ', $fn, ' threw ', get_class($e), ': ', $e->getMessage(), "\n";
        ++$fail;
    }
}
if (0 === $fail) {
    echo "ok\n";
}
