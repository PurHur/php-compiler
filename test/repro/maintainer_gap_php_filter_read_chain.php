<?php

declare(strict_types=1);

$cases = [
    ['php://filter/read=string.toupper/resource=data://text/plain,hello', 'HELLO'],
    ['php://filter/read=string.rot13/resource=data://text/plain,uryyb', 'hello'],
    ['php://filter/read=convert.quoted-printable-decode/resource=data://text/plain,=48=65=6C=6C=6F', 'Hello'],
    ['php://filter/read=string.tolower|string.toupper/resource=data://text/plain,hello', 'HELLO'],
];

foreach ($cases as [$uri, $expected]) {
    $got = file_get_contents($uri);
    if ($got !== $expected) {
        echo 'fail:', $uri, ':', var_export($got, true), "\n";
        exit(1);
    }
    echo 'ok:', $expected, "\n";
}
