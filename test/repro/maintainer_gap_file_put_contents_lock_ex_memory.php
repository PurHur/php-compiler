<?php

declare(strict_types=1);

var_export(@file_put_contents('php://memory', 'x', LOCK_EX));
