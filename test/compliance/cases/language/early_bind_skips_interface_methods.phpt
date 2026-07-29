--TEST--
Language: early-bind skips ClassMethod / interface methods (php-cfg ClassMethod extends Function_, #24836)
--FILE--
<?php
interface EarlyBindLive24836
{
    public function rewind(): void;
}

function early_bind_after_iface_24836(): string
{
    return 'ok';
}

echo early_bind_after_iface_24836(), "\n";
echo interface_exists(EarlyBindLive24836::class) ? 'iface_yes' : 'iface_no', "\n";
?>
--EXPECT--
ok
iface_yes
