<?php

declare(strict_types=1);

namespace Keepnew\Form;

/**
 * Évaluation de la logique conditionnelle du formulaire et validation des
 * réponses — CÔTÉ SERVEUR (jamais de confiance au front).
 *
 * Pur (aucun accès base) : reçoit les champs + conditions + réponses, calcule la
 * visibilité et l'obligation effectives de chaque champ, et renvoie les erreurs.
 *
 * Règle : un champ masqué par une condition n'est jamais requis ; un champ rendu
 * requis par une condition l'est même s'il ne l'était pas par défaut.
 */
final class FormValidator
{
    /**
     * @param array<string, mixed> $answers  Réponses indexées par field_key
     * @param list<array<string, mixed>> $fields
     * @param list<array<string, mixed>> $conditions
     * @return array<string, string>  Erreurs par field_key (vide si tout est valide)
     */
    public function validate(array $answers, array $fields, array $conditions): array
    {
        $visibility = $this->resolve($fields, $conditions, $answers);
        $errors = [];

        foreach ($fields as $field) {
            $key = (string) $field['field_key'];
            $meta = $visibility[$key];
            if (!$meta['visible'] || !$meta['required']) {
                continue;
            }
            if ($this->isEmpty($answers[$key] ?? null)) {
                $errors[$key] = 'Ce champ est requis.';
            }
        }

        return $errors;
    }

    /**
     * Calcule visibilité + obligation effectives de chaque champ après
     * application des conditions.
     *
     * @param list<array<string, mixed>> $fields
     * @param list<array<string, mixed>> $conditions
     * @param array<string, mixed> $answers
     * @return array<string, array{visible:bool, required:bool}>
     */
    public function resolve(array $fields, array $conditions, array $answers): array
    {
        // État par défaut.
        $byId = [];
        $state = [];
        foreach ($fields as $field) {
            $byId[(int) $field['id']] = $field;
            $state[(string) $field['field_key']] = [
                'visible' => true,
                'required' => (int) $field['is_required'] === 1,
            ];
        }

        foreach ($conditions as $cond) {
            $source = $byId[(int) $cond['source_field_id']] ?? null;
            $target = $byId[(int) $cond['target_field_id']] ?? null;
            if ($source === null || $target === null) {
                continue;
            }
            $sourceValue = $answers[(string) $source['field_key']] ?? null;
            if (!$this->matches($sourceValue, (string) $cond['operator'], $cond['compare_value'])) {
                continue;
            }
            $targetKey = (string) $target['field_key'];
            switch ($cond['action']) {
                case 'show':
                    $state[$targetKey]['visible'] = true;
                    break;
                case 'hide':
                    $state[$targetKey]['visible'] = false;
                    break;
                case 'require':
                    $state[$targetKey]['required'] = true;
                    break;
                case 'optional':
                    $state[$targetKey]['required'] = false;
                    break;
            }
        }

        return $state;
    }

    private function matches(mixed $value, string $operator, mixed $compare): bool
    {
        return match ($operator) {
            'eq' => (string) $value === (string) $compare,
            'neq' => (string) $value !== (string) $compare,
            'in' => in_array((string) $value, array_map('trim', explode(',', (string) $compare)), true),
            'gt' => is_numeric($value) && is_numeric($compare) && (float) $value > (float) $compare,
            'lt' => is_numeric($value) && is_numeric($compare) && (float) $value < (float) $compare,
            'filled' => !$this->isEmpty($value),
            'empty' => $this->isEmpty($value),
            default => false,
        };
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
