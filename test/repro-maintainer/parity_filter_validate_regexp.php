<?php
$ok = filter_var('abc123', FILTER_VALIDATE_REGEXP, [
    'options' => ['regexp' => '/^[a-z0-9]+$/'],
]);
var_dump($ok);
$bad = filter_var('!!!', FILTER_VALIDATE_REGEXP, [
    'options' => ['regexp' => '/^[a-z0-9]+$/'],
]);
var_dump($bad);
