<?php
$ctx = hash_init('sha256');
hash_update($ctx, 'hello ');
hash_update($ctx, 'world');
$ctx2 = hash_copy($ctx);
echo hash_final($ctx), "\n";
echo hash_final($ctx2), "\n";
