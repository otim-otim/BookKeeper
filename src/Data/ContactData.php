<?php

namespace OtimOtim\BookKeeper\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer and/or vendor, optionally linked to an application model.
 */
final readonly class ContactData extends Data
{
    public function __construct(
        public string $name,
        public bool $isCustomer = true,
        public bool $isVendor = false,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $taxNumber = null,
        public ?string $address = null,
        public ?string $currency = null,
        public ?int $paymentTermsDays = null,
        public ?Model $contactable = null,
        public ?array $metadata = null,
    ) {}

    /**
     * Keys: name, customer, vendor, email, phone, tax_number, address,
     * currency, payment_terms_days, contactable, metadata. A contact is a
     * customer unless it is only flagged as a vendor.
     */
    public static function fromArray(array $data): static
    {
        $isVendor = (bool) ($data['vendor'] ?? $data['is_vendor'] ?? false);

        return new self(
            name: $data['name'],
            isCustomer: (bool) ($data['customer'] ?? $data['is_customer'] ?? ! $isVendor),
            isVendor: $isVendor,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            taxNumber: $data['tax_number'] ?? null,
            address: $data['address'] ?? null,
            currency: isset($data['currency']) ? strtoupper($data['currency']) : null,
            paymentTermsDays: isset($data['payment_terms_days']) ? (int) $data['payment_terms_days'] : null,
            contactable: $data['contactable'] ?? null,
            metadata: $data['metadata'] ?? null,
        );
    }
}
