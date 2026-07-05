<?php

declare(strict_types=1);

$ok = ucfirst(string: 'abc') === 'Abc'
    && lcfirst(string: 'Abc') === 'abc'
    && strtoupper(string: 'abc') === 'ABC'
    && strtolower(string: 'ABC') === 'abc'
    && addslashes(string: "a'b") === "a\\'b"
    && bin2hex(string: "\x01") === '01';

echo $ok ? "ok\n" : "fail\n";
