<?php

declare(strict_types=1);

echo strpos('abc', 'bc', -1) == false ? "false\n" : "?\n";
echo stripos('abc', 'B', -1) == false ? "false\n" : "?\n";
echo strpos('abcdef', 'de', -4), "\n";
