<?php
/**
 * AOT json_encode() must invoke JsonSerializable::jsonSerialize()
 * (php-src ext/json/json_encoder.c php_json_encode_serializable_object).
 */
class Payload implements JsonSerializable
{
    public int $id = 99;

    public function jsonSerialize(): mixed
    {
        return ['id' => 1];
    }
}

class SelfSerializable implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return $this;
    }
}

echo json_encode(new Payload()), "\n";
echo json_encode(new SelfSerializable()), "\n";
echo json_encode(new stdClass()), "\n";
