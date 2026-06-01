<?php

declare(strict_types=1);

echo substr_count('abcabc', 'bc', -1), "\n";
echo substr_count('abcabc', 'bc', -3), "\n";
echo substr_count('abcabc', 'bc', 0, -1), "\n";
