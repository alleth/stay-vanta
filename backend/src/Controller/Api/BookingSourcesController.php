<?php
declare(strict_types=1);

namespace App\Controller\Api;

/**
 * BookingSources — read-only endpoint. The list of OTA sources a property
 * books through is created implicitly by typing a new source name into
 * Promo Rates' "Add promo rate" (see PromoRatesController::resolveOrCreateSource
 * / BookingSourcesTable::resolveOrCreate) — there's no separate add/rename/
 * delete flow. This just serves the list so the New Reservation form's
 * Source dropdown (and the promo rate form's autocomplete) can read it.
 * 'walk_in' is not included here; it's a fixed option the frontend always
 * shows alongside these.
 */
class BookingSourcesController extends AppController
{
    /**
     * GET /api/booking-sources
     */
    public function index(): void
    {
        $sources = $this->fetchTable('BookingSources');
        $query = $this->scopeToProperty($sources->find()->orderBy(['BookingSources.name' => 'ASC']));

        $this->set('bookingSources', $query->all());
        $this->viewBuilder()->setOption('serialize', ['bookingSources']);
    }
}
