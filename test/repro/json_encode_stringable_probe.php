<?php
declare(strict_types=1);

class C implements Stringable {
    public function __toString(): string {
        return 'hi';
    }
}

var_dump(json_encode(new C()));
echo json_last_error(), "\n";

class D {
    public int $x = 1;
}
echo json_encode(new D()), "\n";
