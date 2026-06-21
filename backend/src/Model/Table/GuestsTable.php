<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Guests model. Expanded by the Guests module; created here because
 * Reservations reference it (fallback table classes are disabled).
 *
 * @method \App\Model\Entity\Guest newEmptyEntity()
 * @method \App\Model\Entity\Guest get(mixed $primaryKey, array $options = [])
 */
class GuestsTable extends Table
{
    public const TYPES = ['local', 'foreign'];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('guests');
        $this->setDisplayField('full_name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
        $this->hasMany('Reservations');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('property_id', 'create')
            ->integer('property_id');

        $validator
            ->scalar('full_name')
            ->maxLength('full_name', 191)
            ->requirePresence('full_name', 'create')
            ->notEmptyString('full_name');

        $validator->inList('guest_type', self::TYPES);

        $validator
            ->email('email')
            ->allowEmptyString('email');

        $validator
            ->maxLength('contact_number', 50)
            ->allowEmptyString('contact_number');

        $validator
            ->maxLength('address', 255)
            ->allowEmptyString('address');

        return $validator;
    }

    /**
     * Find existing guests in a property that look like the same person:
     * the name matches AND (email matches OR contact number matches). Used to
     * warn about — and avoid — duplicate guest records. Returns an empty list
     * when there is no contact point to match on (we won't merge two same-named
     * people on name alone).
     *
     * @return array<\App\Model\Entity\Guest>
     */
    public function findDuplicates(
        int $propertyId,
        string $fullName,
        ?string $email = null,
        ?string $contact = null,
        ?int $excludeId = null,
    ): array {
        $fullName = trim($fullName);
        $email = trim((string)$email);
        $contact = trim((string)$contact);
        if ($fullName === '' || ($email === '' && $contact === '')) {
            return [];
        }

        // full_name compares case-insensitively via the column's CI collation.
        $query = $this->find()->where([
            'Guests.property_id' => $propertyId,
            'Guests.full_name' => $fullName,
        ]);

        $or = [];
        if ($email !== '') {
            $or['Guests.email'] = $email;
        }
        if ($contact !== '') {
            $or['Guests.contact_number'] = $contact;
        }
        $query->where(fn($exp) => $exp->or($or));

        if ($excludeId !== null) {
            $query->where(['Guests.id !=' => $excludeId]);
        }

        return $query->all()->toList();
    }
}
