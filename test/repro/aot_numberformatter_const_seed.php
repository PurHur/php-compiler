<?php
// #35366 — ClassConstFetch must seed NumberFormatter::* for thin AOT (peer #35360).
echo 'DECIMAL=', NumberFormatter::DECIMAL, "\n";
echo 'CURRENCY=', NumberFormatter::CURRENCY, "\n";
echo 'PERCENT=', NumberFormatter::PERCENT, "\n";
$f = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo $f ? 'ok' : 'bad', "\n";
