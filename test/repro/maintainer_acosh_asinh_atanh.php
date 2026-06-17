<?php
declare(strict_types=1);

foreach (['acosh', 'asinh', 'atanh'] as $f) {
    echo $f, ' exists? ';
    var_dump(function_exists($f));
}
var_dump(acosh(1.5), asinh(1.5), atanh(0.5));
