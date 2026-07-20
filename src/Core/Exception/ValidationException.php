<?php

declare(strict_types=1);

namespace Keepnew\Core\Exception;

/**
 * Données d'entrée invalides → HTTP 422.
 *
 * Porte un tableau d'erreurs par champ, exploitable par le front (widget /
 * back-office) pour afficher le message au bon endroit.
 */
final class ValidationException extends HttpException
{
    /**
     * @param array<string, string> $errors Erreurs indexées par nom de champ.
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Données invalides.',
    ) {
        parent::__construct(422, $message);
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
