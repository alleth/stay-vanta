<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use InvalidArgumentException;

/**
 * BookingSources model — the list of OTA sources a property books through
 * (Cocotel, Agoda, ...). Starts empty for every property; there's no
 * standalone add/rename/delete flow — a row is created (or reused, if the
 * name already matches one) as a side effect of adding a promo rate for it
 * (see `resolveOrCreate()`, called from PromoRatesController). 'walk_in' is
 * deliberately not stored here: it's not an OTA, always available, and never
 * carries a promo rate.
 *
 * @method \App\Model\Entity\BookingSource newEmptyEntity()
 * @method \App\Model\Entity\BookingSource get(mixed $primaryKey, array $options = [])
 */
class BookingSourcesTable extends Table
{
    /** The fixed, non-configurable "guest just walked in" source. */
    public const WALK_IN = 'walk_in';

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
     * Resolve a typed source name to its code, creating the row if no
     * existing source (case-insensitive) matches — this is the only way a
     * `booking_sources` row comes into being: typing a new name when adding
     * a promo rate.
     *
     * @throws \InvalidArgumentException On a blank name or "Walk-in" (that's
     *   the fixed non-OTA constant, never a real row).
     */
    public function resolveOrCreate(int $propertyId, string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Pick or type an OTA booking source.');
        }
        if (mb_strtolower($name) === 'walk-in' || mb_strtolower($name) === 'walk in') {
            throw new InvalidArgumentException('"Walk-in" isn\'t a bookable OTA source.');
        }

        $existing = $this->find()
            ->where(['BookingSources.property_id' => $propertyId])
            ->all();
        foreach ($existing as $row) {
            if (mb_strtolower(trim((string)$row->name)) === mb_strtolower($name)) {
                return $row->code;
            }
        }

        $source = $this->newEntity([
            'property_id' => $propertyId,
            'name' => $name,
            'code' => $this->slugFor($name, $propertyId),
        ]);
        $this->saveOrFail($source);

        return $source->code;
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
