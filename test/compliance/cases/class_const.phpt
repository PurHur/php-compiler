--TEST--
Class constant fetch (issue #204)
--FILE--
<?php
class Config {
    public const ENV = 'prod';
}
echo Config::ENV;
--EXPECT--
prod
