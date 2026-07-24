<?php

declare(strict_types=1);

namespace Keepnew\Form;

use Keepnew\Core\Database;

/**
 * Accès au formulaire dynamique : versions, champs, options, conditions.
 *
 * Une version publiée est le formulaire servi au widget. Éditer se fait sur une
 * version non publiée (brouillon) pour ne pas altérer le formulaire en ligne.
 */
final class FormRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(): array
    {
        return $this->db->select('SELECT * FROM form_versions ORDER BY version DESC');
    }

    public function findVersion(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM form_versions WHERE id = :id', ['id' => $id]);
    }

    /**
     * Dernière version publiée (le formulaire en ligne).
     */
    public function publishedVersion(): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM form_versions WHERE is_published = 1 ORDER BY version DESC LIMIT 1',
        );
    }

    public function createVersion(?string $label, ?int $userId): int
    {
        $next = (int) $this->db->scalar('SELECT COALESCE(MAX(version), 0) + 1 FROM form_versions');

        return $this->db->insert('form_versions', [
            'version' => $next,
            'label' => $label ?: ('Version ' . $next),
            'is_published' => 0,
            'created_by' => $userId,
        ]);
    }

    public function publish(int $versionId): void
    {
        $this->db->run(
            'UPDATE form_versions SET is_published = 1, published_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $versionId],
        );
    }

    // --- Champs ---------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function fields(int $versionId): array
    {
        $fields = $this->db->select(
            'SELECT * FROM form_fields WHERE version_id = :v ORDER BY step, sort_order',
            ['v' => $versionId],
        );
        $serviceIdsByField = $this->fieldServiceIdsByVersion($versionId);
        foreach ($fields as &$field) {
            $field['options'] = $this->db->select(
                'SELECT * FROM form_field_options WHERE field_id = :f ORDER BY sort_order',
                ['f' => (int) $field['id']],
            );
            $field['service_ids'] = $serviceIdsByField[(int) $field['id']] ?? [];
        }

        return $fields;
    }

    public function findField(int $fieldId): ?array
    {
        return $this->db->selectOne('SELECT * FROM form_fields WHERE id = :id', ['id' => $fieldId]);
    }

    /**
     * Prestations assignées à chaque champ d'une version, en une seule requête
     * groupée (évite le N+1 sur fields()). Un champ absent de la map (ou avec
     * une liste vide) s'applique à toutes les prestations.
     *
     * @return array<int, list<int>> field_id => [service_id, ...]
     */
    public function fieldServiceIdsByVersion(int $versionId): array
    {
        $rows = $this->db->select(
            'SELECT ffs.field_id, ffs.service_id
               FROM form_field_services ffs
               JOIN form_fields ff ON ff.id = ffs.field_id
              WHERE ff.version_id = :v',
            ['v' => $versionId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['field_id']][] = (int) $row['service_id'];
        }

        return $out;
    }

    /**
     * Remplace les prestations assignées à un champ (liste vide = s'applique
     * à toutes les prestations).
     *
     * @param list<int> $serviceIds
     */
    public function syncFieldServices(int $fieldId, array $serviceIds): void
    {
        $this->db->transaction(function (Database $db) use ($fieldId, $serviceIds): void {
            $db->run('DELETE FROM form_field_services WHERE field_id = :f', ['f' => $fieldId]);
            foreach (array_unique($serviceIds) as $serviceId) {
                $db->insert('form_field_services', ['field_id' => $fieldId, 'service_id' => $serviceId]);
            }
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addField(int $versionId, array $data): int
    {
        $order = (int) $this->db->scalar(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM form_fields WHERE version_id = :v AND step = :s',
            ['v' => $versionId, 's' => $data['step']],
        );

        return $this->db->insert('form_fields', [
            'version_id' => $versionId,
            'field_key' => $data['field_key'],
            'label' => $data['label'],
            'help_text' => $data['help_text'] ?? null,
            'field_type' => $data['field_type'],
            'step' => $data['step'],
            'sort_order' => $order,
            'is_required' => $data['is_required'],
            'duration_modifier_type' => $data['duration_modifier_type'] ?? 'none',
            'duration_modifier_value' => $data['duration_modifier_value'] ?? 0,
            'config_json' => isset($data['config_json']) ? json_encode($data['config_json'], JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateField(int $fieldId, array $data): void
    {
        $this->db->run(
            'UPDATE form_fields SET label = :label, help_text = :help, field_type = :type,
                    step = :step, is_required = :req
             WHERE id = :id',
            [
                'label' => $data['label'],
                'help' => $data['help_text'] ?? null,
                'type' => $data['field_type'],
                'step' => $data['step'],
                'req' => $data['is_required'],
                'id' => $fieldId,
            ],
        );
    }

    public function deleteField(int $fieldId): void
    {
        $this->db->run('DELETE FROM form_fields WHERE id = :id', ['id' => $fieldId]);
    }

    /**
     * @param list<int> $orderedIds
     */
    public function reorderFields(array $orderedIds): void
    {
        $this->db->transaction(function (Database $db) use ($orderedIds): void {
            $pos = 0;
            foreach ($orderedIds as $id) {
                $db->run('UPDATE form_fields SET sort_order = :p WHERE id = :id', ['p' => $pos, 'id' => (int) $id]);
                $pos++;
            }
        });
    }

    // --- Options --------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function addOption(int $fieldId, array $data): int
    {
        $order = (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM form_field_options WHERE field_id = :f', ['f' => $fieldId]);

        return $this->db->insert('form_field_options', [
            'field_id' => $fieldId,
            'value' => $data['value'],
            'label' => $data['label'],
            'duration_modifier_type' => $data['duration_modifier_type'] ?? 'none',
            'duration_modifier_value' => $data['duration_modifier_value'] ?? 0,
            'sort_order' => $order,
        ]);
    }

    public function deleteOption(int $optionId): void
    {
        $this->db->run('DELETE FROM form_field_options WHERE id = :id', ['id' => $optionId]);
    }

    // --- Conditions -----------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function conditions(int $versionId): array
    {
        return $this->db->select('SELECT * FROM form_conditions WHERE version_id = :v', ['v' => $versionId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addCondition(int $versionId, array $data): int
    {
        return $this->db->insert('form_conditions', [
            'version_id' => $versionId,
            'source_field_id' => $data['source_field_id'],
            'operator' => $data['operator'],
            'compare_value' => $data['compare_value'] ?? null,
            'action' => $data['action'],
            'target_field_id' => $data['target_field_id'],
        ]);
    }

    public function deleteCondition(int $conditionId): void
    {
        $this->db->run('DELETE FROM form_conditions WHERE id = :id', ['id' => $conditionId]);
    }

    /**
     * Formulaire publié, prêt pour le widget (fields + options + conditions).
     *
     * @return array<string, mixed>|null
     */
    public function publishedForm(): ?array
    {
        $version = $this->publishedVersion();
        if ($version === null) {
            return null;
        }
        $versionId = (int) $version['id'];

        return [
            'version' => (int) $version['version'],
            'fields' => array_map(
                static fn (array $f): array => [
                    'id' => (int) $f['id'],
                    'field_key' => $f['field_key'],
                    'label' => $f['label'],
                    'help_text' => $f['help_text'],
                    'field_type' => $f['field_type'],
                    'step' => (int) $f['step'],
                    'is_required' => (int) $f['is_required'] === 1,
                    'config' => $f['config_json'] !== null ? json_decode((string) $f['config_json'], true) : null,
                    'options' => array_map(
                        static fn (array $o): array => ['value' => $o['value'], 'label' => $o['label']],
                        $f['options'],
                    ),
                    'service_ids' => $f['service_ids'],
                ],
                $this->fields($versionId),
            ),
            'conditions' => array_map(
                static fn (array $c): array => [
                    'source_field_id' => (int) $c['source_field_id'],
                    'operator' => $c['operator'],
                    'compare_value' => $c['compare_value'],
                    'action' => $c['action'],
                    'target_field_id' => (int) $c['target_field_id'],
                ],
                $this->conditions($versionId),
            ),
        ];
    }

    /**
     * Formulaire publié, filtré aux champs pertinents pour un ensemble de
     * prestations (contenu du panier) : un champ sans prestation assignée
     * s'applique à toutes ; un champ avec assignation ne s'affiche que si au
     * moins une de ses prestations est présente dans $serviceIds (union).
     *
     * @param list<int> $serviceIds
     * @return array<string, mixed>|null
     */
    public function publishedFormForServices(array $serviceIds): ?array
    {
        $form = $this->publishedForm();
        if ($form === null) {
            return null;
        }

        $fields = array_values(array_filter(
            $form['fields'],
            static fn (array $f): bool => $f['service_ids'] === [] || array_intersect($f['service_ids'], $serviceIds) !== [],
        ));
        $fieldIds = array_map(static fn (array $f): int => $f['id'], $fields);

        $conditions = array_values(array_filter(
            $form['conditions'],
            static fn (array $c): bool => in_array($c['source_field_id'], $fieldIds, true) && in_array($c['target_field_id'], $fieldIds, true),
        ));

        return ['version' => $form['version'], 'fields' => $fields, 'conditions' => $conditions];
    }
}
