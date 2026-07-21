<?php

declare(strict_types=1);

namespace Keepnew\Tests\Form;

use Keepnew\Form\FormValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la logique conditionnelle et de la validation serveur du formulaire.
 */
final class FormValidatorTest extends TestCase
{
    private FormValidator $v;

    protected function setUp(): void
    {
        $this->v = new FormValidator();
    }

    private function fields(): array
    {
        return [
            ['id' => 1, 'field_key' => 'pets', 'field_type' => 'radio', 'is_required' => 0],
            ['id' => 2, 'field_key' => 'dirt_level', 'field_type' => 'cards', 'is_required' => 0],
            ['id' => 3, 'field_key' => 'water', 'field_type' => 'radio', 'is_required' => 1],
        ];
    }

    public function testRequiredFieldMissingProducesError(): void
    {
        $errors = $this->v->validate([], $this->fields(), []);
        self::assertArrayHasKey('water', $errors);        // requis par défaut
        self::assertArrayNotHasKey('pets', $errors);      // facultatif
    }

    public function testConditionMakesFieldRequired(): void
    {
        // SI pets = yes ALORS exiger dirt_level.
        $conditions = [
            ['source_field_id' => 1, 'operator' => 'eq', 'compare_value' => 'yes', 'action' => 'require', 'target_field_id' => 2],
        ];

        $errorsWhenPets = $this->v->validate(['pets' => 'yes', 'water' => 'yes'], $this->fields(), $conditions);
        self::assertArrayHasKey('dirt_level', $errorsWhenPets); // devenu requis

        $errorsNoPets = $this->v->validate(['pets' => 'no', 'water' => 'yes'], $this->fields(), $conditions);
        self::assertArrayNotHasKey('dirt_level', $errorsNoPets); // reste facultatif
    }

    public function testHiddenFieldIsNeverRequired(): void
    {
        // water requis par défaut, mais masqué si pets = no.
        $conditions = [
            ['source_field_id' => 1, 'operator' => 'eq', 'compare_value' => 'no', 'action' => 'hide', 'target_field_id' => 3],
        ];
        $errors = $this->v->validate(['pets' => 'no'], $this->fields(), $conditions);
        self::assertArrayNotHasKey('water', $errors); // masqué → non exigé
    }

    public function testOperatorsFilledAndIn(): void
    {
        $fields = [
            ['id' => 1, 'field_key' => 'a', 'field_type' => 'text', 'is_required' => 0],
            ['id' => 2, 'field_key' => 'b', 'field_type' => 'text', 'is_required' => 0],
        ];
        // SI a rempli ALORS exiger b.
        $c1 = [['source_field_id' => 1, 'operator' => 'filled', 'compare_value' => null, 'action' => 'require', 'target_field_id' => 2]];
        self::assertArrayHasKey('b', $this->v->validate(['a' => 'x'], $fields, $c1));
        self::assertArrayNotHasKey('b', $this->v->validate(['a' => ''], $fields, $c1));

        // SI a in "x,y" ALORS exiger b.
        $c2 = [['source_field_id' => 1, 'operator' => 'in', 'compare_value' => 'x,y', 'action' => 'require', 'target_field_id' => 2]];
        self::assertArrayHasKey('b', $this->v->validate(['a' => 'y'], $fields, $c2));
        self::assertArrayNotHasKey('b', $this->v->validate(['a' => 'z'], $fields, $c2));
    }
}
