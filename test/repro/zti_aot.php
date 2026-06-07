<?php
$id = zend_thread_id();
echo is_int($id) && $id > 0 ? "int\n" : "bad\n";
