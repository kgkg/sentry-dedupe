<?php
declare(strict_types=1);

namespace Kgkg\SentryDedupe\Exception;

class StorageException extends \RuntimeException
{
    public function __construct(string $category = 'storage_error', ?\Throwable $previous = null)
    {
        parent::__construct($category, 1, $previous);
    }
}
