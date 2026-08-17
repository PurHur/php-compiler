<?php
// AOT compile-only (#28917): password_hash Zend stub names password/algo/options=
// plus string algo. Runtime bcrypt AOT may segfault; lowering must accept names.
password_hash(password: 'x', algo: PASSWORD_DEFAULT);
password_hash(password: 'x', algo: '2y', options: []);
