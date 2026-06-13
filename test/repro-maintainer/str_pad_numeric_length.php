<?php

echo str_pad('x', '5', '-'), "\n";
echo str_pad('x', 5.0, '-'), "\n";
try {
    str_pad('x', [], '-');
    echo "no throw\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
