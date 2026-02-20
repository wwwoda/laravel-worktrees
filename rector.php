<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths(['src'])
    ->withPhpSets(php82: true)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0);
