<?php
declare(strict_types=1);

$algos = hash_algos();
echo 'count=', count($algos), "\n";
echo 'sha512=', var_export(in_array('sha512', $algos, true), true), "\n";
echo 'crc32c=', var_export(in_array('crc32c', $algos, true), true), "\n";
echo 'md5=', var_export(in_array('md5', $algos, true), true), "\n";
