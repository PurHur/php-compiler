<?php
// AOT lint: named args for fnmatch (#23461) — unlink/chdir/umask AOT segfaults pre-exist for positional too
fnmatch(pattern: 'a*', filename: 'abc');
