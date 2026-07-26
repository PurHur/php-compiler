<?php
// AOT lint: fnmatch named args (#23461). unlink/chdir/umask AOT are pre-existing
// positional failures (umask structGep / FS segfault); VM covers all four.
fnmatch(pattern: 'a*', filename: 'abc');
