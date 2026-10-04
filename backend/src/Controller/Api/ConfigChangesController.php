<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use Cake\Http\Exception\BadRequestException;
use Cake\ORM\Query\SelectQuery;

/**
 * The configuration change log (build step 9): the authoritative record of
 * who changed which setting, when, why, and what it was before and after.
 *
 * - GET /api/config-changes (settings.change_log.view, a Manager): the
 *   property's changes, property records excepted; filter by `entity_type`,
 *   `entity_id` (one row's history), `impact`, `event`; `latest=1` (with
 *   `entity_type`) keeps only each row's most recent matching change, e.g.
 *   `impact=price&latest=1`: where each current price came from.
 * - GET /api/platform/property-changes (platform.property.manage, the
 *   Platform Owner): changes to property records (fee, subscription, name),
 *   optionally one property's (`property_id`).
 */
class ConfigChangesController extends AppController
{
    private const PAGE_SIZE = 50;
    private const MAX_PAGE = 100;

    /**
     * GET /api/config-changes
     */
    public function index(): void
    {
        $this->authorize(Permissions::SETTINGS_CHANGE_LOG_VIEW, 'Only a Manager can view the change log.');
        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }
        $query = $this->fetchTable('ConfigChanges')->find()
            ->where(['property_id' => $propertyId, 'entity_type !=' => 'property']);
        $this->respondWith($this->filtered($query));
    }

    /**
     * GET /api/platform/property-changes
     */
    public function propertyChanges(): void
    {
        $this->authorize(Permissions::PLATFORM_PROPERTY_MANAGE, 'Only the Platform Owner can view property changes.');
        $query = $this->fetchTable('ConfigChanges')->find()->where(['entity_type' => 'property']);
        $propertyId = $this->request->getQuery('property_id');
        if ($propertyId !== null && $propertyId !== '') {
            $query->where(['property_id' => (int)$propertyId]);
        }
        $this->respondWith($this->filtered($query));
    }

    /**
     * Apply the list's filters.
     */
    private function filtered(SelectQuery $query): SelectQuery
    {
        foreach (['entity_type' => 'entity_type', 'impact' => 'impact', 'event' => 'event_type'] as $param => $column) {
            $value = $this->request->getQuery($param);
            if ($value !== null && $value !== '') {
                $query->where([$column => (string)$value]);
            }
        }
        $entityId = $this->request->getQuery('entity_id');
        if ($entityId !== null && $entityId !== '') {
            $query->where(['entity_id' => (int)$entityId]);
        }
        if ($this->request->getQuery('latest')) {
            $entityType = (string)$this->request->getQuery('entity_type');
            if ($entityType === '') {
                throw new BadRequestException('latest needs entity_type.');
            }
            // No later change of the same row matching the same filters.
            $later = $query->getConnection()->selectQuery('1', ['later' => 'config_changes'])
                ->where([
                    'later.entity_type = ConfigChanges.entity_type',
                    'later.entity_id = ConfigChanges.entity_id',
                    'later.id > ConfigChanges.id',
                ]);
            foreach (['impact' => 'impact', 'event' => 'event_type'] as $param => $column) {
                $value = $this->request->getQuery($param);
                if ($value !== null && $value !== '') {
                    $later->where(["later.$column" => (string)$value]);
                }
            }
            $query->where(fn($exp) => $exp->notExists($later));
        }

        return $query->orderBy(['occurred_at' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * One page of changes, newest first → {changes, page, has_more}. Who is
     * named now; an imported baseline has no actor and says so (`recorded:
     * false`).
     */
    private function respondWith(SelectQuery $query): void
    {
        $page = min(self::MAX_PAGE, max(1, (int)($this->request->getQuery('page') ?? 1)));
        $rows = $query->limit(self::PAGE_SIZE + 1)->offset(($page - 1) * self::PAGE_SIZE)->all()->toList();
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);

        $actorIds = array_values(array_unique(array_filter(array_map(fn($r) => $r->actor_id, $rows))));
        $names = $actorIds === [] ? [] : $this->fetchTable('Users')->find()
            ->select(['id', 'name'])->where(['id IN' => $actorIds])->all()->combine('id', 'name')->toArray();

        $changes = array_map(fn($r) => [
            'id' => (int)$r->id,
            'at' => $r->occurred_at,
            'actor' => $r->actor_id !== null ? ($names[$r->actor_id] ?? null) : null,
            'recorded' => $r->source !== 'import',
            'source' => $r->source,
            'property_id' => (int)$r->property_id,
            'entity_type' => $r->entity_type,
            'entity_id' => (int)$r->entity_id,
            'label' => $r->snapshot['label'] ?? null,
            'event' => $r->event_type,
            'impact' => $r->impact,
            'reason' => $r->reason,
            'changes' => $r->changes,
            'correlation_id' => $r->correlation_id,
        ], $rows);

        $this->set(['changes' => $changes, 'page' => $page, 'has_more' => $hasMore]);
        $this->viewBuilder()->setOption('serialize', ['changes', 'page', 'has_more']);
    }
}
