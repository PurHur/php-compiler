<?php

echo hash('crc32', 'abc'), "\n";
echo hash('crc32b', 'abc'), "\n";
echo hash('crc32c', 'abc'), "\n";
echo hash('adler32', 'abc'), "\n";
echo hash('fnv132', 'abc'), "\n";
echo hash('fnv1a32', 'abc'), "\n";
echo hash('crc32b', 'abc', true) === "\x35\x24\x41\xc2" ? "raw-ok\n" : "raw-fail\n";
