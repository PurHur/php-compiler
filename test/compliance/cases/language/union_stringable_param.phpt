--TEST--
Union parameter string|Stringable accepts implicit __toString classes (issue #7207)
--FILE--
<?php
class S {
    public function __toString(): string {
        return 'x';
    }
}

class NotStringable {
    protected function __toString(): string {
        return 'hidden';
    }
}

function f(string|Stringable $s): void {
    echo $s, "\n";
}

f('literal');
f(new S());
try {
    f(new NotStringable());
    echo "bad_ok\n";
} catch (TypeError $e) {
    echo "reject_ok\n";
}
--EXPECT--
literal
x
reject_ok
