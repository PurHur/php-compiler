<?php

declare(strict_types=1);

$a = ['k' => 1, '' => 2];
var_dump(array_key_exists('k', $a));
var_dump(key_exists('k', $a));
