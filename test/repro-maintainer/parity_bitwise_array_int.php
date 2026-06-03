<?php

declare(strict_types=1);

foreach (['&', '|', '^'] as $op) {
    try {
        eval('return [] '.$op.' 1;');
    } catch (TypeError $e) {
        echo $op, ':', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $op, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
