--TEST--
AOT: static method call receives array argument (#2185)
--FILE--
<?php
class Router
{
    public static function appName($config)
    {
        if (isset($config['app_name'])) {
            return $config['app_name'];
        }

        return 'MiniWebApp';
    }
}
echo Router::appName(['app_name' => 'TestApp']);
--EXPECT--
TestApp
