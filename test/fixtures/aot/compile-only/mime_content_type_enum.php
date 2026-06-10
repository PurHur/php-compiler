<?php
// Compile-only (#6196): mime_content_type() enum-case TypeError guards for AOT.
enum Ep: string { case P = '/tmp/foo.php'; }
mime_content_type(Ep::P);
