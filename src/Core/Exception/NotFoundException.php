<?php

declare(strict_types=1);

namespace Keepnew\Core\Exception;

/**
 * Ressource ou route introuvable → HTTP 404.
 */
final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Ressource introuvable.', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, $previous);
    }
}
