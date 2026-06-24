<?php

$result = @dns_get_record('invalid.invalid', DNS_A);
if (is_array($result) && [] === $result) {
    echo "empty_array\n";
} elseif (false === $result) {
    echo "false\n";
} else {
    echo "other\n";
}
