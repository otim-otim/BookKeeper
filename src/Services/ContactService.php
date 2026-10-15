<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Model;
use OtimOtim\BookKeeper\Data\ContactData;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Contact;
use OtimOtim\BookKeeper\Support\Morph;

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
        return $this->createFrom(ContactData::fromArray(['name' => $name] + $options));
    }

    public function createFrom(ContactData $data): Contact
    {
        return Contact::create([
            'name' => $data->name,
            'is_customer' => $data->isCustomer,
            'is_vendor' => $data->isVendor,
            'email' => $data->email,
            'phone' => $data->phone,
            'tax_number' => $data->taxNumber,
            'address' => $data->address,
            'currency' => $data->currency,
            'payment_terms_days' => $data->paymentTermsDays,
            'metadata' => $data->metadata,
        ] + Morph::attributes($data->contactable, 'contactable'));
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
        $contact = Contact::query()->where(Morph::attributes($model, 'contactable'))->first();

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
