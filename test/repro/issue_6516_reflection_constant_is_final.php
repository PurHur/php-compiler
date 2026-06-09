<?php
class Config {
    final public const VERSION = 1;
    public const NAME = 'app';
}
$final = new ReflectionClassConstant(Config::class, 'VERSION');
$plain = new ReflectionClassConstant(Config::class, 'NAME');
var_export($final->isFinal());
var_export($plain->isFinal());
