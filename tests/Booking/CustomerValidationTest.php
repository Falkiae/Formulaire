<?php

declare(strict_types=1);

namespace Keepnew\Tests\Booking;

use Keepnew\Booking\BookingService;
use Keepnew\Core\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Exigences serveur sur les coordonnées et l'adresse.
 *
 * L'API ne validait que le honeypot, le consentement et le formulaire
 * d'intake : une réservation à domicile sans rue passait, et le technicien
 * recevait un rendez-vous sans adresse. Ces règles ne doivent pas dépendre du
 * widget, qui est du code client — ce test est volontairement pur (pas de
 * base) pour tourner partout.
 */
final class CustomerValidationTest extends TestCase
{
    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $lines
     * @return array<string, string>
     */
    private function errorsFor(array $customer, array $address = [], array $lines = []): array
    {
        $method = new \ReflectionMethod(BookingService::class, 'assertCustomerAndAddress');
        $method->setAccessible(true);

        try {
            $method->invoke(null, $customer, $address, $lines);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function validCustomer(): array
    {
        return ['first_name' => 'Nina', 'last_name' => 'Lambert', 'email' => 'nina@exemple.be', 'phone' => '+32 470 00 00 00'];
    }

    /**
     * @return array<string, mixed>
     */
    private function validAddress(): array
    {
        return ['street' => 'Rue Saint-Gilles', 'number' => '12', 'postal_code' => '4000', 'city' => 'Liège'];
    }

    /** @return list<array<string, mixed>> */
    private function onsiteLines(): array
    {
        return [['mode' => 'onsite', 'service_id' => 4]];
    }

    /** @return list<array<string, mixed>> */
    private function workshopLines(): array
    {
        return [['mode' => 'workshop', 'service_id' => 5]];
    }

    public function testACompleteOnsiteBookingPasses(): void
    {
        self::assertSame([], $this->errorsFor($this->validCustomer(), $this->validAddress(), $this->onsiteLines()));
    }

    public function testIdentityFieldsAreAllRequired(): void
    {
        $errors = $this->errorsFor([], [], $this->workshopLines());

        self::assertArrayHasKey('customer.first_name', $errors);
        self::assertArrayHasKey('customer.last_name', $errors);
        self::assertArrayHasKey('customer.email', $errors);
        self::assertArrayHasKey('customer.phone', $errors);
    }

    public function testWhitespaceOnlyDoesNotSatisfyARequiredField(): void
    {
        $customer = $this->validCustomer();
        $customer['first_name'] = '   ';

        self::assertArrayHasKey('customer.first_name', $this->errorsFor($customer, [], $this->workshopLines()));
    }

    /**
     * L'e-mail porte le lien de gestion : une faute de frappe coupe au client
     * l'accès à sa propre réservation.
     */
    public function testMalformedEmailIsRejected(): void
    {
        $customer = $this->validCustomer();
        $customer['email'] = 'nina.exemple.be';

        $errors = $this->errorsFor($customer, [], $this->workshopLines());
        self::assertArrayHasKey('customer.email', $errors);
        self::assertStringContainsString('incorrecte', $errors['customer.email']);
    }

    public function testTooShortPhoneIsRejected(): void
    {
        $customer = $this->validCustomer();
        $customer['phone'] = '047 00';

        self::assertArrayHasKey('customer.phone', $this->errorsFor($customer, [], $this->workshopLines()));
    }

    public function testCommonPhoneSpellingsAreAccepted(): void
    {
        foreach (['+32 470 12 34 56', '0470/12.34.56', '0032470123456', '04 70 12 34 56'] as $spelling) {
            $customer = $this->validCustomer();
            $customer['phone'] = $spelling;

            self::assertArrayNotHasKey(
                'customer.phone',
                $this->errorsFor($customer, [], $this->workshopLines()),
                "refusé à tort : {$spelling}",
            );
        }
    }

    /**
     * Le cas qui laissait partir un technicien sans savoir où aller.
     */
    public function testAnOnsiteBookingWithoutAddressIsRejected(): void
    {
        $errors = $this->errorsFor($this->validCustomer(), [], $this->onsiteLines());

        self::assertArrayHasKey('address.street', $errors);
        self::assertArrayHasKey('address.number', $errors);
        self::assertArrayHasKey('address.postal_code', $errors);
        self::assertArrayHasKey('address.city', $errors);
    }

    /**
     * À l'atelier, c'est le client qui se déplace : exiger son adresse serait
     * une friction gratuite.
     */
    public function testAWorkshopBookingNeedsNoAddress(): void
    {
        self::assertSame([], $this->errorsFor($this->validCustomer(), [], $this->workshopLines()));
    }

    public function testErrorKeysAreUsableAsFieldPathsByTheWidget(): void
    {
        $errors = $this->errorsFor([], [], $this->onsiteLines());

        foreach (array_keys($errors) as $key) {
            self::assertMatchesRegularExpression('/^(customer|address)\.[a-z_]+$/', $key);
        }
    }
}
