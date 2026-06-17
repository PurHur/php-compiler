<?php

declare(strict_types=1);

compiler_language_warning('"continue" targeting switch is equivalent to "break"', 8);
echo compiler_language_warning('probe') ? '1' : '0';
echo "\nok\n";
