<?php
/**
 * User: lonely walker
 * Date: 2024/8/19 10:14
 * Desc:
 */

namespace LonelyWalker\CustomLaravelStorage;

use League\Flysystem\FilesystemAdapter;

abstract class BaseAdapter implements FilesystemAdapter
{
    abstract public function getUrl(string $path): string;

    abstract public function getTemporaryUrl(string $path, int|string|\DateTimeInterface $expiration, array $options = [], string $method = 'GET'): bool|string;
}