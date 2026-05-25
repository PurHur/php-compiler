--TEST--
Language: parameter attribute ignored at runtime (#1354)
--FILE--
<?php
class C {
    public function m(#[\SensitiveParameter] string $s): string {
        return $s;
    }
}
echo (new C())->m('secret');
--EXPECT--
secret
