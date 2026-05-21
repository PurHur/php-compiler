--TEST--
switch on string literals (issue #96)
--FILE--
<?php
$action = 'edit';
switch ($action) {
    case 'list':
        echo "list\n";
        break;
    case 'edit':
        echo "edit\n";
        break;
    default:
        echo "home\n";
}
switch ('home') {
    case 'home':
        echo "ok\n";
        break;
}
--EXPECT--
edit
ok
