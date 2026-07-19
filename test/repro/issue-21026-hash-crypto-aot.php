<?php
echo "H:", hash("sha256", "abc"), "\n";
echo "S:", hash_hmac("sha256", "abc", "k"), "\n";
echo "M:", hash("md5", "x"), "\n";
