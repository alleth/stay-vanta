<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * BookingSources model — the admin-configurable list of OTA sources a
 * property books through (Cocotel, Agoda, ...), replacing what used to be a
 * fixed hardcoded list. 'walk_in' is deliberately not stored here: it's not
 * an OTA, always available, and never carries a promo rate.
 *
 * @method \App\Model\Entity\BookingSource newEmptyEntity()
 * @method \App\Model\Entity\BookingSource get(mixed $primaryKey, array $options = [])
 */
class BookingSourcesTable extends Table
{
    /** The fixed, non-configurable "guest just walked in" source. */
    public const WALK_IN = 'walk_in';

    /** The four sources every property had before this became configurable. */
    private const DEFAULTS = [
        'cocotel' => 'Cocotel',
        'agoda' => 'Agoda',
        'trip_com' => 'Trip.com',
        'tripadvisor' => 'TripAdvisor',
    ];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('booking_sources');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('property_id', 'create')
            ->integer('property_id');

        $validator
            ->scalar('code')
            ->maxLength('code', 50)
            ->requirePresence('code', 'create')
            ->notEmptyString('code');

        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        return $validator;
    }

    /**
     * Turn a display name into a stable, URL/column-safe code, disambiguated
     * against a property's existing codes (e.g. "Trip.com" and "Trip com"
     * would otherwise both slugify to "trip_com").
     */
    public function slugFor(string $name, int $propertyId): string
    {
        $base = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '_', $name)));
        $base = trim($base, '_');
        if ($base === '' || $base === self::WALK_IN) {
            $base = 'source';
        }

        $existing = $this->find()
            ->select(['code'])
            ->where(['BookingSources.property_id' => $propertyId])
            ->all()
            ->extract('code')
            ->toList();

        $code = $base;
        $suffix = 2;
        while (in_array($code, $existing, true)) {
            $code = $base . '_' . $suffix;
            $suffix++;
        }

        return $code;
    }

    /**
     * Lazily seed a new property's booking sources with the four that used
     * to be hardcoded, so existing reservations/promo rates (which store
     * these exact codes) keep resolving and the dropdown isn't empty on day
     * one. A no-op once the property has any row of its own.
     */
    public function seedDefaultsFor(int $propertyId): void
    {
        $hasAny = $this->exists(['BookingSources.property_id' => $propertyId]);
        if ($hasAny) {
            return;
        }

        foreach (self::DEFAULTS as $code => $name) {
            $this->saveOrFail($this->newEntity([
                'property_id' => $propertyId,
                'code' => $code,
                'name' => $name,
            ]));
        }
    }

    /**
     * Human-readable label for a source code — the configured name if found,
     * else 'Walk-in' for the fixed constant, else the raw code as a fallback
     * (e.g. a source that was since deleted but still referenced by an old
     * reservation).
     */
    public function labelFor(int $propertyId, string $code): string
    {
        if ($code === self::WALK_IN) {
            return 'Walk-in';
        }

        $row = $this->find()
            ->select(['name'])
            ->where(['BookingSources.property_id' => $propertyId, 'BookingSources.code' => $code])
            ->first();

        return $row?->name ?? ucfirst(str_replace('_', ' ', $code));
    }
}
