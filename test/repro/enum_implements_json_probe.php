<?php

declare(strict_types=1);

/**
 * Backed enum JsonSerializable must not fall back to backing scalar (#6880).
 */

enum Status: int implements JsonSerializable
{
    case Ok = 1;
    public function jsonSerialize(): mixed
    {
        return ['status' => 'ok'];
    }
}

echo json_encode(Status::Ok), "\n";
