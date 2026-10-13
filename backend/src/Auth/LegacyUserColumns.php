<?php
declare(strict_types=1);

namespace App\Auth;

use Cake\Database\Connection;
use Cake\Log\Log;

/**
 * The retiring users.role / users.property_id (Permissions Phase 3d). Since
 * 3d-1 nothing reads them at request time; since 3d-2 nothing writes them;
 * 3d-3 copies them into legacy_user_columns and drops them. This class is the
 * one place (besides the one-time imports) allowed to read them, and only to
 * watch the retirement:
 *
 * - report(): how far they still agree with the memberships, how many
 *   accounts have them empty, and their fingerprint (logged at start-up);
 * - fingerprint(): the count and one SHA-256 over every account's values,
 *   from `users` or from the archive, so 3d-3 can prove the copy complete
 *   (3D2-D5) before anything is dropped.
 */
final class LegacyUserColumns
{
    public const ARCHIVE = 'legacy_user_columns';

    /**
     * @param \Cake\Database\Connection $connection The database.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Whether users.role and users.property_id still exist (false after 3d-3).
     */
    public function exist(): bool
    {
        $users = $this->connection->getSchemaCollection()->describe('users');

        return $users->hasColumn('role') && $users->hasColumn('property_id');
    }

    /**
     * One account's values as the archive records them: a SHA-256 over a
     * fixed format, `\N` for an empty value.
     */
    public static function rowChecksum(int $userId, ?string $role, int|string|null $propertyId): string
    {
        return hash('sha256', implode('|', [
            $userId,
            $role ?? '\N',
            $propertyId === null ? '\N' : (string)(int)$propertyId,
        ]));
    }

    /**
     * The values' fingerprint: how many accounts, how many have a role / a
     * property, and one SHA-256 over every row's checksum in account order.
     * Computed in PHP (GROUP_CONCAT would truncate at 1,024 characters).
     *
     * @param string $source `users` (the columns) or the archive table.
     * @return array{accounts: int, with_role: int, with_property: int, checksum: string}
     */
    public function fingerprint(string $source = 'users'): array
    {
        $sql = $source === self::ARCHIVE
            ? 'SELECT user_id AS id, role, property_id FROM legacy_user_columns ORDER BY user_id'
            : 'SELECT id, role, property_id FROM users ORDER BY id';
        $hash = hash_init('sha256');
        $accounts = 0;
        $withRole = 0;
        $withProperty = 0;
        foreach ($this->connection->execute($sql)->fetchAll('assoc') as $row) {
            $accounts++;
            $withRole += $row['role'] !== null ? 1 : 0;
            $withProperty += $row['property_id'] !== null ? 1 : 0;
            hash_update($hash, self::rowChecksum((int)$row['id'], $row['role'], $row['property_id']) . "\n");
        }

        return [
            'accounts' => $accounts,
            'with_role' => $withRole,
            'with_property' => $withProperty,
            'checksum' => hash_final($hash),
        ];
    }

    /**
     * The start-up report (bin/cake roles --observe): disagreements among the
     * values that exist (a warning; never fixed here), accounts with no
     * values (made since 3d-2), property staff without an active membership,
     * and the fingerprint. Null once the columns are gone.
     *
     * @return array<string, mixed>|null
     */
    public function report(): ?array
    {
        if (!$this->exist()) {
            return null;
        }
        $count = fn(string $sql): int => (int)$this->connection->execute($sql)->fetchColumn(0);
        $active = 'JOIN property_memberships m ON m.user_id = u.id AND m.ended_at IS NULL';
        $report = [
            'role' => $count("SELECT COUNT(*) FROM users u $active JOIN roles r ON r.id = m.role_id"
                . ' WHERE u.role IS NOT NULL AND u.role <> r.code'),
            'property' => $count("SELECT COUNT(*) FROM users u $active"
                . ' WHERE u.property_id IS NOT NULL AND u.property_id <> m.property_id'),
            'platform' => $count('SELECT COUNT(*) FROM users u WHERE u.is_platform = 1'
                . " AND u.role IS NOT NULL AND u.role <> 'owner'"),
            'empty' => $count('SELECT COUNT(*) FROM users u WHERE u.role IS NULL AND u.property_id IS NULL'),
            'without_membership' => $count('SELECT COUNT(*) FROM users u WHERE u.is_platform = 0 AND NOT EXISTS'
                . ' (SELECT 1 FROM property_memberships m WHERE m.user_id = u.id AND m.ended_at IS NULL)'),
            'fingerprint' => $this->fingerprint(),
        ];
        $f = $report['fingerprint'];
        $line = sprintf(
            'legacy user columns: %d role and %d property disagreement(s) with memberships, %d platform;'
                . ' %d staff without an active membership; %d with empty columns;'
                . ' fingerprint %d accounts, %d with a role, %d with a property, checksum %s',
            $report['role'],
            $report['property'],
            $report['platform'],
            $report['without_membership'],
            $report['empty'],
            $f['accounts'],
            $f['with_role'],
            $f['with_property'],
            $f['checksum'],
        );
        $report['role'] + $report['property'] + $report['platform'] > 0 ? Log::warning($line) : Log::info($line);

        return $report;
    }
}
