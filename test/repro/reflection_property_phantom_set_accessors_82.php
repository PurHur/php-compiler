<?php
declare(strict_types=1);

foreach (['isPrivateSet', 'isProtectedSet', 'isPublicSet'] as $method) {
    echo $method.'_exists=', method_exists(ReflectionProperty::class, $method) ? 'yes' : 'no', "\n";
}
