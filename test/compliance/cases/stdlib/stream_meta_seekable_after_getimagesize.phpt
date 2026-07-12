--TEST--
stream_get_meta_data() seekable — nested dim-fetch in var_export() after getimagesizefromstring() (#18005)
--FILE--
<?php
declare(strict_types=1);

@getimagesizefromstring('not-image');
$meta = stream_get_meta_data(tmpfile());

$assigned = $meta['seekable'];
$nested = var_export($meta['seekable'], true);

echo ($assigned === true) ? '1' : '0', "\n";
echo ($nested === 'true') ? '1' : '0', "\n";
--EXPECT--
1
1
