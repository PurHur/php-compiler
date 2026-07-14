<?php

declare(strict_types=1);

echo iconv(null, 'UTF-8', 'hi'), "\n";
echo iconv('UTF-8', null, 'hi'), "\n";
