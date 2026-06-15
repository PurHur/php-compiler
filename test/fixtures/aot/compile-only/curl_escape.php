<?php

declare(strict_types=1);

echo curl_escape('a b'), "\n";
echo curl_unescape('a%20b'), "\n";
