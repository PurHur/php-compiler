<?php
// AOT crc32() must match Zend (#27077 — NestedJIT-safe Crc32JitHelper, not VmCrc32 stub→0).
echo crc32('php-compiler'), PHP_EOL;
