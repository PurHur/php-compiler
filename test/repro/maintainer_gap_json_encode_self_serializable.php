<?php

declare(strict_types=1);

/**
 * json_encode() self-referencing JsonSerializable — Zend returns '{}' not false (#17706).
 *
 * php-src ref: ext/json/php_json_encoder.c
 */
class SelfSerializable implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return $this;
    }
}

$encoded = json_encode(new SelfSerializable());
echo $encoded, "\n";
var_export($encoded);
echo "\n";
echo json_last_error() === 0 ? '0' : (string) json_last_error(), "\n";
