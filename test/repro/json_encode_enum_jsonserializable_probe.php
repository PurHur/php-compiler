<?php

declare(strict_types=1);

/**
 * json_encode() on enum implementing JsonSerializable — php-src ext/json/php_json.c (#6880).
 */

enum UnitJson implements JsonSerializable
{
    case A;
    public function jsonSerialize(): mixed
    {
        return 'a';
    }
}

enum BackedJson: string implements JsonSerializable
{
    case X = 'x';
    public function jsonSerialize(): mixed
    {
        return 'custom';
    }
}

var_dump(json_encode(UnitJson::A));
var_dump(json_encode(BackedJson::X));
