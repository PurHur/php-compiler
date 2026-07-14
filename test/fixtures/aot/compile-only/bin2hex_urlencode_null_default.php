<?php

declare(strict_types=1);

// AOT compile-only (#18912): bin2hex()/urlencode()/rawurlencode() null coerces on default profile.
foreach (['bin2hex', 'urlencode', 'rawurlencode'] as $fn) {
    var_dump($fn(null));
}
