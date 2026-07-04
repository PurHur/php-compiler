--TEST--
stdlib PATH_SEPARATOR explode after defined()+OR value checks (issue #15833)
--FILE--
<?php
if (!defined('DIRECTORY_SEPARATOR') || !defined('PATH_SEPARATOR')) {
    echo "fail:undefined\n";
    exit(1);
}
if ('/' !== DIRECTORY_SEPARATOR || ':' !== PATH_SEPARATOR) {
    echo "fail:values\n";
    exit(1);
}
$parts = explode(PATH_SEPARATOR, get_include_path());
echo is_array($parts) && count($parts) >= 1 ? 'ok' : 'fail';
--EXPECT--
ok
