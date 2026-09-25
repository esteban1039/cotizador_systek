<?php

namespace App\Application\Drive;

interface DriveInventoryClient
{
    public function inventory(string $rootFolderId, int $maxFiles, int $maxDepth): array;
}
