<?php
function test(): void {
    static $s = new ArrayObject([1, 2]);
    echo "count=", $s->count(), " first=", $s[0], "\n";
}
test();
