<?php
declare(strict_types=1);

$anon = new class {
};

if (in_array(get_class($anon), get_declared_classes(), true)) {
    echo "yes\n";
} else {
    echo "no\n";
}

$d = get_declared_classes();
var_dump(is_array($d));

if (in_array('stdClass', get_declared_classes(), true)) {
    echo "stdClass yes\n";
}
