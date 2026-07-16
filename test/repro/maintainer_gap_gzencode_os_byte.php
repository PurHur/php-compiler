<?php
declare(strict_types=1);
// Issue #19516 — gzencode() gzip OS header byte must be Unix 0x03 (zlib OS_CODE), not 0xff.
$z = gzencode('hello', 1);
echo 'os_byte=', bin2hex($z[9]), "\n";
echo 'hdr=', bin2hex(substr($z, 0, 10)), "\n";
echo gzdecode($z) === 'hello' ? "ok\n" : "roundtrip-fail\n";
