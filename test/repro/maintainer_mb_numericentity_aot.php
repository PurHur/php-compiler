<?php

declare(strict_types=1);

echo mb_encode_numericentity("\xE2\x82\xAC", [0x80, 0x10FFFF, 0, 0xFFFFFFFF], 'UTF-8'), "\n";
echo mb_decode_numericentity('&#8364;', [0x80, 0x10FFFF, 0, 0xFFFFFFFF], 'UTF-8'), "\n";
