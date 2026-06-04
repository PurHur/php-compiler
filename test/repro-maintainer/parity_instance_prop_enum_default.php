<?php
enum Mode: string {
    case On = 'on';
    case Off = 'off';
}

class Device {
    public Mode $mode = Mode::On;
}

$d = new Device();
var_export($d->mode);
echo ($d->mode === Mode::On) ? "same\n" : "diff\n";
