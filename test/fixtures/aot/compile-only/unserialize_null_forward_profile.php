<?php
// Compile-only (#18840): unserialize() must lower null TypeError on 8.4 forward profile.
unserialize(null);
