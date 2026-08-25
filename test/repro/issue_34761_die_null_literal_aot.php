<?php
// AOT: die(null) literal must not SIGSEGV the compiler (#34761).
die(null);
