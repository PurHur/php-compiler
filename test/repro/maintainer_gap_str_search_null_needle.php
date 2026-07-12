<?php

foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    var_export($fn('abc', null));
    echo "\n";
}
