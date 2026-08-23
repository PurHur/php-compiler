<?php

declare(strict_types=1);

// Runtime (non-literal) subject — forces NestedJIT path under AOT (#34294).
$s = 'ｱｲｳ';
$mode = 'KV';
echo mb_convert_kana($s, $mode);
echo "\n";
echo mb_convert_kana($s);
echo "\n";
$zen = 'カタカナ';
echo mb_convert_kana($zen, 'c');
echo "\n";
