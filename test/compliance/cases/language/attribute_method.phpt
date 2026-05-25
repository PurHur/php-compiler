--TEST--
Language: method attribute ignored at runtime (#1354)
--FILE--
<?php
#[\AllowDynamicProperties]
class Box {
    #[\Deprecated]
    public function ping(): string {
        return 'pong';
    }
}
echo (new Box())->ping();
--EXPECT--
pong
