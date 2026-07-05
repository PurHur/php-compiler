<?php

declare(strict_types=1);

$checks = [
    ucfirst(string: 'abc') === 'Abc',
    lcfirst(string: 'Abc') === 'abc',
    strtoupper(string: 'abc') === 'ABC',
    strtolower(string: 'ABC') === 'abc',
    addslashes(string: "a'b") === "a\\'b",
    bin2hex(string: "\x01") === '01',
];

echo in_array(false, $checks, true) ? 'fail' : 'ok';
