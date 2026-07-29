<?php

class Config {
    const VERSION = '1.0';
    const NAME = 'app';

    public static function test(): void {
        $c = 'VERSION';
        echo self::{$c}, "\n";
        echo static::{$c}, "\n";
    }
}

$const = 'VERSION';
echo Config::{$const}, "\n";

$expr = 'VER' . 'SION';
echo Config::{$expr}, "\n";

Config::test();
