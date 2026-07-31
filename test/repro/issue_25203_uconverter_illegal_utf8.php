<?php

declare(strict_types=1);

// UConverter::transcode/convert illegal UTF-8 → U+FFFD (#25203, php-src ext/intl/converter)
$cases = [
    "caf\x80",
    "a\xC0\x80b",
    "\xED\xA0\x80",
    "\xF5\x80\x80\x80",
];
foreach ($cases as $t) {
    echo bin2hex($t), ' tc=', bin2hex(UConverter::transcode($t, 'UTF-8', 'UTF-8')), "\n";
}
$c = new UConverter('UTF-8', 'UTF-8');
echo 'convert=', bin2hex($c->convert("a\xC0\x80b")), "\n";
