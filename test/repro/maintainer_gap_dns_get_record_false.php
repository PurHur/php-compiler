<?php

declare(strict_types=1);

$result = @dns_get_record('invalid..domain', DNS_A);
echo false === $result ? "false\n" : "not_false\n";
