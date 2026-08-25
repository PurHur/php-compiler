<?php

declare(strict_types=1);

// AOT hash() non-crypto digests (#34829) — Zend/VM reference values for "abc".
echo hash('crc32', 'abc'), PHP_EOL;
echo hash('crc32b', 'abc'), PHP_EOL;
echo hash('crc32c', 'abc'), PHP_EOL;
echo hash('adler32', 'abc'), PHP_EOL;
echo hash('fnv132', 'abc'), PHP_EOL;
echo hash('fnv1a32', 'abc'), PHP_EOL;
echo hash('md5', 'abc'), PHP_EOL;
