<?php

foreach (['div' => fn () => 1 / 0, 'mod' => fn () => 1 % 0] as $label => $op) {
    try {
        $op();
    } catch (DivisionByZeroError $e) {
        echo "$label: DivisionByZeroError\n";
    } catch (Throwable $e) {
        echo "$label: wrong:", get_class($e), "\n";
    }
}
