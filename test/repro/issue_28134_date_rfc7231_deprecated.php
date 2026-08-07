<?php

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(function (int $no, string $str): bool {
    echo 'DEP:', $str, "\n";

    return true;
});
echo 'global=', DATE_RFC7231, "\n";
echo 'iface=', DateTimeInterface::RFC7231, "\n";
echo 'dt=', DateTime::RFC7231, "\n";
