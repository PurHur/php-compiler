<?php

declare(strict_types=1);

realpath(__DIR__);
clearstatcache(clear_realpath_cache: true);
realpath(__DIR__);
echo count(realpath_cache_get()) >= 1 ? "named ok\n" : "named fail\n";
