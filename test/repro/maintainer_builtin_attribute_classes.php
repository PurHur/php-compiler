<?php
declare(strict_types=1);
foreach (['Attribute', 'ReturnTypeWillChange', 'AllowDynamicProperties', 'SensitiveParameter', 'Override'] as $c) {
    echo $c, '=', class_exists($c, false) ? 'yes' : 'no', "\n";
}
