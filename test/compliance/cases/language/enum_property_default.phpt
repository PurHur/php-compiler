--TEST--
Language: typed property defaults with enum case singleton (zend_compile.c, #5891)
--FILE--
<?php
enum Status: string {
    case Active = 'active';
    case Inactive = 'inactive';
}

enum Mode: string {
    case On = 'on';
    case Off = 'off';
}

class Config {
    public static Status $state = Status::Active;
}

class Device {
    public Mode $mode = Mode::On;
}

var_export(Config::$state);
echo (Config::$state === Status::Active) ? " static-same\n" : " static-diff\n";
$d = new Device();
var_export($d->mode);
echo ($d->mode === Mode::On) ? " instance-same\n" : " instance-diff\n";
--EXPECT--
\Status::Active static-same
\Mode::On instance-same
