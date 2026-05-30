--TEST--
Language: #[\Deprecated] attribute emits E_USER_DEPRECATED (VM, #3569)
--FILE--
<?php
ini_set('error_reporting', '32767');

#[\Deprecated(message: "old")]
function f() {}

#[\Deprecated(message: "use g() instead", since: "8.4")]
function g(): void {}

class Box {
    #[\Deprecated(message: "use ping2")]
    public function ping(): void {}

    #[\Deprecated(since: "1.0")]
    public const FLAG = 1;
}

f();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "fn\n" : "no\n";

g();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";

(new Box())->ping();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";

echo Box::FLAG, "\n";
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
--EXPECT--
Function f() is deprecated, old
fn
Function g() is deprecated since 8.4, use g() instead
Method Box::ping() is deprecated, use ping2
1
Constant Box::FLAG is deprecated since 1.0
