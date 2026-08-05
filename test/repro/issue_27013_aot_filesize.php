<?php
/**
 * #27013 — AOT filesize() must match Zend st_size (not NestedJIT array garbage).
 *
 * Run: php bin/vm.php test/repro/issue_27013_aot_filesize.php
 * AOT: php bin/compile.php -o /tmp/aot_fs test/repro/issue_27013_aot_filesize.php && /tmp/aot_fs
 */
$path = '/tmp/phpc_27013_filesize_probe.txt';
file_put_contents($path, 'hi');
echo filesize($path), "\n";
