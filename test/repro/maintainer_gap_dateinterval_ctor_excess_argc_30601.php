<?php
try {
    $i = new DateInterval('P1D', 1);
    echo 'NO_THROW ', $i->d, "\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $i = new DateInterval();
    echo 'NO_THROW0 ', $i->d, "\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $i = new DateInterval('P1D');
    echo 'OK ', $i->d, "\n";
} catch (Throwable $e) {
    echo 'OK ', get_class($e), ':', $e->getMessage(), "\n";
}
