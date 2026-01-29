<?php

$required = [
    "pthreads",
    "sockets",
    "phar",
    "curl",
    "yaml",
    "zlib",
    "Zend OPcache"
];

echo "PHP Version: " . PHP_VERSION . PHP_EOL;

foreach ($required as $ext) {
    echo $ext . ": " . (extension_loaded($ext) ? "OK" : "MISSING") . PHP_EOL;
}

if (!extension_loaded("pthreads")) {
    exit("ERROR: pthreads is required\n");
}

echo "Environment OK for AquaMarine\n";