<?php

declare(strict_types=1);

// AOT fixture (#17708): nested password_hash() as password_needs_rehash() arg.
echo password_needs_rehash(password_hash('x', PASSWORD_BCRYPT), PASSWORD_BCRYPT, ['cost' => 4]) ? "needs\n" : "ok\n";
