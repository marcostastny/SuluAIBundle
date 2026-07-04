<?php

declare(strict_types=1);

$candidates = [
    __DIR__ . '/../vendor/autoload.php',          // standalone checkout with own vendor/
    __DIR__ . '/../../../vendor/autoload.php',    // <project>/.local-bundles/<bundle>/tests
    __DIR__ . '/../../../../vendor/autoload.php', // <project>/vendor/<vendor>/<bundle>/tests
];

$autoload = null;
foreach ($candidates as $candidate) {
    if (\file_exists($candidate)) {
        $autoload = require $candidate;
        break;
    }
}

if (null === $autoload) {
    throw new \RuntimeException('Could not locate a composer autoloader for the test suite.');
}

$autoload->addPsr4('Marcostastny\\SuluAIBundle\\Tests\\', __DIR__);

return $autoload;
