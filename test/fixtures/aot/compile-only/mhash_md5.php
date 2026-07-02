<?php

declare(strict_types=1);

echo (int) function_exists('mhash'), "\n";
echo bin2hex(mhash(MHASH_MD5, 'hello')), "\n";
