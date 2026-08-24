<?php
declare(strict_types=1);

$s = chr(0xE2).chr(0x82).chr(0xAC);
echo mb_detect_encoding($s), "\n";
echo mb_detect_encoding('café', ['UTF-8', 'ASCII'], true), "\n";
echo mb_detect_encoding('hello'), "\n";
