--TEST--
Language: new / $c::method / $c::CONST on non-string/non-object — Zend Error (#30059)
--FILE--
<?php
class C {
    const X = 1;
    static function foo() {
        return 1;
    }
}

$msg = 'Class name must be a valid object or a string';
foreach ([null, true, false, 1, 1.5, []] as $c) {
    try {
        new $c;
        echo "new ok\n";
    } catch (Throwable $e) {
        echo $e->getMessage() === $msg ? "new:ok\n" : ("new:".$e->getMessage()."\n");
    }
    try {
        $c::foo();
        echo "call ok\n";
    } catch (Throwable $e) {
        echo $e->getMessage() === $msg ? "call:ok\n" : ("call:".$e->getMessage()."\n");
    }
    try {
        echo $c::X;
        echo "const ok\n";
    } catch (Throwable $e) {
        echo $e->getMessage() === $msg ? "const:ok\n" : ("const:".$e->getMessage()."\n");
    }
}

$s = 'C';
echo (new $s) instanceof C ? "newstr\n" : "newstr-fail\n";
echo $s::foo() === 1 ? "callstr\n" : "callstr-fail\n";
echo $s::X === 1 ? "conststr\n" : "conststr-fail\n";

$o = new C;
echo (new $o) instanceof C ? "newobj\n" : "newobj-fail\n";
echo $o::foo() === 1 ? "callobj\n" : "callobj-fail\n";
echo $o::X === 1 ? "constobj\n" : "constobj-fail\n";

try {
    $c = 'Nope';
    new $c;
    echo "nope ok\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Class "Nope" not found') ? "nope:ok\n" : ("nope:".$e->getMessage()."\n");
}
?>
--EXPECT--
new:ok
call:ok
const:ok
new:ok
call:ok
const:ok
new:ok
call:ok
const:ok
new:ok
call:ok
const:ok
new:ok
call:ok
const:ok
new:ok
call:ok
const:ok
newstr
callstr
conststr
newobj
callobj
constobj
nope:ok
