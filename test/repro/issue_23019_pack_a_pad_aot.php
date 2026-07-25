<?php

declare(strict_types=1);

// Repro #23019 — AOT pack() a/A padding must match Zend (NestedJIT pad char).
echo bin2hex(pack('a3', 'hi')), PHP_EOL;
echo bin2hex(pack('A3', 'hi')), PHP_EOL;
echo bin2hex(pack('a5', 'x')), PHP_EOL;
