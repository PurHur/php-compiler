<?php

declare(strict_types=1);

$result = gethostbyaddr('127.0.0.1');
echo "loopback=$result\n";
if ('localhost' !== $result) {
    echo "FAIL loopback expected localhost got $result\n";
    exit(1);
}

$result = gethostbyaddr('8.8.8.8');
echo "public=$result\n";
if ('8.8.8.8' === $result) {
    echo "FAIL public PTR unchanged\n";
    exit(1);
}

exit(0);
