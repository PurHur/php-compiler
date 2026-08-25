<?php
// AOT: exit(float)/die(bool) must link ObOutput echo ABIs (#34756).
// PHP 8.2 prints the value then exits 0; string exit stays on libc printf.
exit(1.5);
