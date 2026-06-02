<?php

$host = parse_url('http://', 1);
echo $host === false ? 'false' : var_export($host, true), "\n";

$path = parse_url('http://', 5);
echo $path, "\n";

$port = parse_url('http://example.com', 2);
echo $port === false ? 'false' : (string) $port, "\n";
