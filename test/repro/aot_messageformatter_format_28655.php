<?php
echo (new MessageFormatter('en_US', 'Hello {name}'))->format(['name' => 'World']), "\n";
