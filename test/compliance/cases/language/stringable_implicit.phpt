--TEST--
Implicit Stringable for classes with public __toString (issue #7198)
--FILE--
<?php
class C {
    public function __toString(): string {
        return 'ok';
    }
}

class NotStringable {
    protected function __toString(): string {
        return 'hidden';
    }
}

function needsStringable(Stringable $s): void {
    echo $s, "\n";
}

var_export(new C() instanceof Stringable);
echo "\n";
needsStringable(new C());
var_export(new NotStringable() instanceof Stringable);
echo "\n";
--EXPECT--
true
ok
false
