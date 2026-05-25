--TEST--
Language: class attribute ignored at runtime (#1354)
--FILE--
<?php
#[\AllowDynamicProperties]
class Box {
    public function ping(): string {
        return 'pong';
    }
}
echo (new Box())->ping();
--EXPECT--
pong
