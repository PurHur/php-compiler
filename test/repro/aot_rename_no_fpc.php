<?php

$base = 'test/compliance/cases/stdlib/rename_fixture';
$from = $base . '/from.txt';
$to = $base . '/to.txt';
if (rename($from, $to)) {
    echo 'ok', "\n";
} else {
    echo 'fail', "\n";
}
if (is_file($from)) {
    echo 'old', "\n";
} else {
    echo 'moved', "\n";
}
if (is_file($to)) {
    echo 'new', "\n";
} else {
    echo 'nonew', "\n";
}
