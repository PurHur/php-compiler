<?php
declare(strict_types=1);
// Compile-only (#6879): json_encode() on objects without JsonSerializable must compile for AOT.
class C implements Stringable {
    public function __toString(): string {
        return 'hi';
    }
}
echo json_encode(new C()), "\n";
