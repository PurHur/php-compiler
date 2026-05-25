--TEST--
AOT: MiniWebApp Router static factory, ::class, instanceof (#2185)
--FILE--
<?php
class Router
{
    public static function fromConfig()
    {
        return new self();
    }
}
$router = Router::fromConfig();
echo ($router instanceof Router) ? '1' : '0';
echo "\n";
echo Router::class;
echo "\n";
--EXPECT--
1
Router
