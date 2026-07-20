<?php

declare(strict_types=1);

namespace Keepnew\Core;

/**
 * Représentation d'une réponse HTTP sortante.
 *
 * Constructeurs nommés pour les cas usuels : html(), json(), redirect(),
 * noContent(). Les en-têtes de sécurité globaux sont ajoutés par le Kernel,
 * pas ici, afin de rester configurables (ex. widget whitelisté pour l'iframe).
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['content-type' => 'text/html; charset=utf-8']);
    }

    /**
     * @param mixed $data Sérialisé en JSON (UTF-8, sans échappement des slashes).
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return new self($json, $status, ['content-type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['location' => $location]);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = $value;

        return $clone;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Émet la réponse (statut, en-têtes, corps). Point de sortie unique.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $this->body;
    }
}
