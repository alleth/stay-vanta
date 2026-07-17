<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use RuntimeException;

/**
 * ReceiptSeries — registered physical booklet number series (sales invoices
 * and official receipts). Settling an invoice consumes the next number from
 * the property's active series of the requested type; the formatted number is
 * stamped onto invoices.invoice_number / invoices.or_number.
 */
class ReceiptSeriesTable extends Table
{
    public const TYPES = ['invoice', 'official_receipt'];
    public const TYPE_LABELS = [
        'invoice' => 'Physical Invoice',
        'official_receipt' => 'Official Receipt',
    ];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('receipt_series');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->inList('type', self::TYPES, 'Type must be invoice or official_receipt.');
        $validator->integer('start_number')->greaterThanOrEqual('start_number', 0);
        $validator->integer('end_number');
        $validator->add('end_number', 'range', [
            'rule' => fn($value, array $context) => (int)$value >= (int)($context['data']['start_number'] ?? 0),
            'message' => 'End number must not be below the start number.',
        ]);

        return $validator;
    }

    /**
     * Format a number the way it appears on the physical page
     * (prefix + zero-padded digits, padding taken from the typed start).
     */
    public function format(EntityInterface $series, int $number): string
    {
        $digits = (string)$number;
        $pad = (int)$series->get('pad_length');
        if ($pad > 0) {
            $digits = str_pad($digits, $pad, '0', STR_PAD_LEFT);
        }

        return (string)$series->get('prefix') . $digits;
    }

    /**
     * Consume the next unused number from the property's active series of the
     * given type (oldest series first). Call inside a transaction.
     *
     * @throws \RuntimeException When no active series has numbers left.
     */
    public function assignNext(int $propertyId, string $type): string
    {
        $series = $this->find()
            ->where([
                'ReceiptSeries.property_id' => $propertyId,
                'ReceiptSeries.type' => $type,
                'ReceiptSeries.is_active' => true,
                'ReceiptSeries.next_number <= ReceiptSeries.end_number',
            ])
            ->orderBy(['ReceiptSeries.id' => 'ASC'])
            ->epilog('FOR UPDATE')
            ->first();

        if ($series === null) {
            $label = self::TYPE_LABELS[$type] ?? $type;
            throw new RuntimeException(
                sprintf('No active %s series with numbers left — register a booklet series first.', $label),
            );
        }

        $number = (int)$series->get('next_number');
        $series->set('next_number', $number + 1);
        $this->saveOrFail($series);

        return $this->format($series, $number);
    }
}
