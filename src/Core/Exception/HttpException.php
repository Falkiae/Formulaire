<?php

declare(strict_types=1);

namespace Keepnew\Core\Exception;

/**
 * Exception porteuse d'un code de statut HTTP.
 *
 * Le noyau (Kernel) l'attrape et produit une réponse au bon statut, sans
 * divulguer de trace en production. Les sous-classes ciblent les cas usuels.
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
