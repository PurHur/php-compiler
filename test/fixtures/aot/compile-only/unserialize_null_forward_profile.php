<?php
// Compile-only (#21223): unserialize(null) soft-null DEP+coerce on 8.4 (not TypeError).
unserialize(null);
