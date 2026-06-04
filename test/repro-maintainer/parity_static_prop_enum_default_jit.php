<?php
enum Status: string {
    case Active = 'active';
}

class Config {
    public static Status $state = Status::Active;
}

echo (Config::$state === Status::Active) ? "same\n" : "diff\n";
