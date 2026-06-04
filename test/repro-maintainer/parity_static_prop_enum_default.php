<?php
enum Status: string {
    case Active = 'active';
    case Inactive = 'inactive';
}

class Config {
    public static Status $state = Status::Active;
}

var_export(Config::$state);
echo (Config::$state === Status::Active) ? "same\n" : "diff\n";
