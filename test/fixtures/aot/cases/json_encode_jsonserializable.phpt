--TEST--
AOT: json_encode() invokes JsonSerializable::jsonSerialize() (ext/json/json_encoder.c)
--FILE--
<?php
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
echo json_encode((object) []), "\n";
--EXPECT--
{"id":1}
{}
{}
--EXPECT_EXIT--
0
