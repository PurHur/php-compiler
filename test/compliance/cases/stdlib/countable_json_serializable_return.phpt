--TEST--
stdlib count()/json_encode() on Countable/JsonSerializable — return from user function (#11452)
--FILE--
<?php
function count_from_closure(): int
{
    return count((static fn() => new class implements Countable {
        public function count(): int
        {
            return 5;
        }
    })());
}

function json_from_closure(): string
{
    return json_encode((static fn() => new class implements JsonSerializable {
        public function jsonSerialize(): mixed
        {
            return ['k' => 1];
        }
    })());
}

echo count_from_closure(), "\n";
echo json_from_closure(), "\n";
--EXPECT--
5
{"k":1}
