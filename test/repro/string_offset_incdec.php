<?php
$s = 'ab';
$errors = [];
try {
    $s[0]++;
    $errors[] = 'post-inc-no-error';
} catch (Throwable $e) {
    $errors[] = get_class($e) . ': ' . $e->getMessage();
}
try {
    ++$s[1];
    $errors[] = 'pre-inc-no-error';
} catch (Throwable $e) {
    $errors[] = get_class($e) . ': ' . $e->getMessage();
}
try {
    $s[0]--;
    $errors[] = 'post-dec-no-error';
} catch (Throwable $e) {
    $errors[] = get_class($e) . ': ' . $e->getMessage();
}
foreach ($errors as $e) {
    echo $e, "\n";
}
