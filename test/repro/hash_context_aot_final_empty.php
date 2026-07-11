<?php
$ctx = hash_init('sha256');
echo hash_final($ctx), "\n";
