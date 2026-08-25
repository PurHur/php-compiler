<?php
// AOT: literal die(null) must not SIGSEGV the compiler (#34764).
die(null);
