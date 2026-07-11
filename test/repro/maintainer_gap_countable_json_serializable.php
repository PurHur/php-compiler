<?php

declare(strict_types=1);

$countResult = count((static fn() => new class implements Countable {
    public function count(): int
    {
        return 5;
    }
})());

$jsonResult = json_encode((static fn() => new class implements JsonSerializable {
    public function jsonSerialize(): mixed
    {
        return ['k' => 1];
    }
})());

echo ($countResult === 5 && $jsonResult === '{"k":1}') ? "interface_dispatch_ok\n" : "interface_dispatch_fail\n";
