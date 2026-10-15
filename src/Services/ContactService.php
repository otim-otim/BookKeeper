<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Model;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Contact;

/**
 * Customers and vendors.
 */
class ContactService
{
    /**
     * Options: customer (bool, default true unless vendor), vendor (bool),
     * email, phone, tax_number, address, currency, payment_terms_days,
     * contactable (an application model), metadata.
     */
    public function create(string $name, array $options = []): Contact
    {
        $contactable = $options['contactable'] ?? null;

        return Contact::create([
            'name' => $name,
            'is_customer' => $options['customer'] ?? ! ($options['vendor'] ?? false),
            'is_vendor' => $options['vendor'] ?? false,
            'email' => $options['email'] ?? null,
            'phone' => $options['phone'] ?? null,
            'tax_number' => $options['tax_number'] ?? null,
            'address' => $options['address'] ?? null,
            'currency' => isset($options['currency']) ? strtoupper($options['currency']) : null,
            'payment_terms_days' => $options['payment_terms_days'] ?? null,
            'contactable_type' => $contactable?->getMorphClass(),
            'contactable_id' => $contactable?->getKey(),
            'metadata' => $options['metadata'] ?? null,
        ]);
    }

    public function customer(string $name, array $options = []): Contact
    {
        return $this->create($name, ['customer' => true] + $options);
    }

    public function vendor(string $name, array $options = []): Contact
    {
        return $this->create($name, ['vendor' => true, 'customer' => false] + $options);
    }

    /**
     * The contact linked to an application model, created on first use.
     */
    public function for(Model $model, array $options = []): Contact
    {
        $contact = Contact::query()
            ->where('contactable_type', $model->getMorphClass())
            ->where('contactable_id', $model->getKey())
            ->first();

        return $contact ?? $this->create(
            $options['name'] ?? (string) ($model->getAttribute('name') ?? class_basename($model).' #'.$model->getKey()),
            ['contactable' => $model] + $options,
        );
    }

    public function resolve(Contact|Model|int $contact): Contact
    {
        return match (true) {
            $contact instanceof Contact => $contact,
            $contact instanceof Model => $this->for($contact),
            default => Contact::query()->find($contact) ?? throw new InvalidOperationException("Contact [{$contact}] not found."),
        };
    }
}
