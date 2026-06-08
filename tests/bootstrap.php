<?php

declare(strict_types=1);

// tests/ -> bundle root -> .local-bundles -> project root
$autoload = require __DIR__ . '/../../../vendor/autoload.php';
$autoload->addPsr4('Marcostastny\\SuluAIBundle\\Tests\\', __DIR__);

return $autoload;
