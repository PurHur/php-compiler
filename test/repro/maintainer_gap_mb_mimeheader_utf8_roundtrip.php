<?php

declare(strict_types=1);

$enc = mb_encode_mimeheader('über', 'UTF-8');
$round = mb_decode_mimeheader($enc);

echo $enc, "\n";
echo $round === 'über' ? 'roundtrip ok' : 'roundtrip '.$round, "\n";

$mixed = mb_encode_mimeheader('Hello 世界', 'UTF-8');
echo $mixed, "\n";
echo mb_decode_mimeheader($mixed) === 'Hello 世界' ? 'mixed ok' : 'mixed fail', "\n";

$prefix = mb_encode_mimeheader('test über', 'UTF-8');
echo $prefix, "\n";
echo mb_decode_mimeheader($prefix) === 'test über' ? 'prefix ok' : 'prefix fail', "\n";
