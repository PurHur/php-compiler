<?php
/**
 * Repro #14798 — ArrayAccess empty() when offsetExists true and value null.
 */
class Box implements ArrayAccess {
    private array $data = ['x' => null];

    public function offsetExists(mixed $offset): bool {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed {
        return $this->data[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void {
        unset($this->data[$offset]);
    }
}

$box = new Box();
$emptyPresentNull = empty($box['x']);
$issetPresentNull = isset($box['x']);

if (!$emptyPresentNull) {
    fwrite(STDERR, "FAIL empty_present_null\n");
    exit(1);
}
if (!$issetPresentNull) {
    fwrite(STDERR, "FAIL isset_present_null\n");
    exit(1);
}

echo "ok\n";
