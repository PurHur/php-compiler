<?php

declare(strict_types=1);

$transports = stream_get_transports();
sort($transports);

echo 'count=', count($transports), "\n";
echo 'tlsv1.2=', var_export(in_array('tlsv1.2', $transports, true), true), "\n";
echo 'list=', implode(',', $transports), "\n";
