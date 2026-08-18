<?php
// AOT lint: rename() named from:/to: (#23348). Runtime rename is covered by
// the issue repro under docker-exec (compile.php + native binary).
rename(from: 'a', to: 'b');
