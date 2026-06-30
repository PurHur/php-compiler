<?php

declare(strict_types=1);

$one = json_encode(new ArrayObject([1]));
$two = json_encode(new ArrayObject([1, 2]));

if ('{"0":1}' !== $one) {
    fwrite(STDERR, "one={$one} expected {\"0\":1}\n");
    exit(1);
}
if ('{"0":1,"1":2}' !== $two) {
    fwrite(STDERR, "two={$two} expected {\"0\":1,\"1\":2}\n");
    exit(1);
}
echo "ok\n";
