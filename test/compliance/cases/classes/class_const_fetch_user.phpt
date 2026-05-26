--TEST--
User class constant fetch from global function (issue #2215)
--FILE--
<?php
function contact_name_max(): int {
    return Router::DEFAULT_CONTACT_NAME_MAX;
}
class Router {
    public const DEFAULT_CONTACT_NAME_MAX = 200;
}
echo contact_name_max();
--EXPECT--
200
