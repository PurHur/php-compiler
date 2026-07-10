<?php

declare(strict_types=1);

enum E: string
{
    case A = 'a';
}

echo json_encode(E::A), "\n";
echo json_encode([E::A]), "\n";
echo json_encode(E::A, JSON_THROW_ON_ERROR), "\n";
