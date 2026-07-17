<?php
/**
 * Repro for #4675 — pack()/unpack() machine-endian integers + @ alignment.
 * Compare: php test/repro/issue_4675_pack_integer_endian.php
 *          php bin/vm.php test/repro/issue_4675_pack_integer_endian.php
 */
$bin = pack('Nn', 0x11223344, 0x5566);
var_export(unpack('Nn', $bin));
echo "\n";
var_export(strlen(pack('I@4I', 1, 2)));
echo "\n";
var_export(unpack('P', pack('P', 0x1234)));
echo "\n";
