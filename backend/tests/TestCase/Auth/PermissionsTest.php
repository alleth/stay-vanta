<?php
declare(strict_types=1);

namespace App\Test\TestCase\Auth;

use App\Auth\Permissions;
use App\Auth\PermissionSet;
use App\Model\Table\UsersTable;
use App\Test\TestSuite\PermissionCatalog;
use Cake\TestSuite\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * The permission definitions and the Phase 1 role map.
 *
 * The approved catalog (docs/PERMISSIONS.md) and App\Auth\Permissions must say
 * the same thing, so a grant can't change in one without the other: that's
 * what keeps a behavior change from slipping in as a refactor.
 */
class PermissionsTest extends TestCase
{
    public function testTheCodeDefinesExactlyTheCatalogsPermissions(): void
    {
        $this->assertSame(array_keys(PermissionCatalog::rows()), Permissions::ALL);
    }

    public function testEachRoleHoldsExactlyWhatTheCatalogGrants(): void
    {
        foreach (UsersTable::ROLES as $role) {
            $expected = [];
            foreach (PermissionCatalog::rows() as $permission => $row) {
                if (in_array($role, $row['roles'], true)) {
                    $expected[] = $permission;
                }
            }
            sort($expected);
            $this->assertSame($expected, Permissions::forRole($role)->toArray(), "grants for $role");
        }
    }

    public function testElevatedPermissionsMatchTheCatalog(): void
    {
        $expected = array_keys(array_filter(PermissionCatalog::rows(), fn($row) => $row['elevated']));
        $this->assertEqualsCanonicalizing($expected, Permissions::ELEVATED);
    }

    public function testNamesAreModuleResourceActionAndUnique(): void
    {
        foreach (Permissions::ALL as $permission) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+\.[a-z_]+$/', $permission);
        }
        $this->assertSame(Permissions::ALL, array_values(array_unique(Permissions::ALL)));
    }

    public function testRolesGrantOnlyDefinedPermissionsOnce(): void
    {
        foreach (Permissions::ROLE_GRANTS as $role => $grants) {
            $this->assertSame([], array_diff($grants, Permissions::ALL), "$role grants something undefined");
            $this->assertSame($grants, array_values(array_unique($grants)), "$role grants something twice");
        }
        $this->assertEqualsCanonicalizing(UsersTable::ROLES, array_keys(Permissions::ROLE_GRANTS));
    }

    public function testAnUnknownOrMissingRoleHoldsNothing(): void
    {
        $this->assertSame([], Permissions::forRole('housekeeping')->toArray());
        $this->assertSame([], Permissions::forRole('')->toArray());
        $this->assertSame([], Permissions::forRole(null)->toArray());
    }

    /**
     * Access goes through authorize()/can(), and who may manage whom through
     * StaffHierarchy (Phase 3a): no decision reads a role code. userHasRole()
     * was removed; this keeps it from coming back.
     */
    public function testNoDecisionReadsARoleCode(): void
    {
        $allowed = [];
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace(DS, '/', substr($file->getPathname(), strlen(APP)));
            $source = (string)file_get_contents($file->getPathname());
            if (!in_array($relative, $allowed, true) && str_contains($source, 'userHasRole(')) {
                $found[] = $relative;
            }
        }
        $this->assertSame([], $found, 'Use $this->authorize()/can() with a Permissions constant instead.');
    }

    /**
     * users.role and users.property_id are compatibility-only (Phase 3c-2,
     * Phase 3d-1): a person's role and property are their membership's, the
     * Platform Owner is users.is_platform. Only the one-time imports (step
     * 10a/10b, already run everywhere) read the columns; 3d-3 drops them.
     */
    public function testNothingReadsTheLegacyUserColumns(): void
    {
        $allowed = ['Event/MembershipImport.php', 'Event/AccessBackfill.php'];
        // A user entity is $user / $target / currentUser; `$subject` is a
        // user only for its role (other ledgers' subjects own a property_id).
        $pattern = '/\$(?:user|target|this->currentUser)\??->(?:role|property_id)\b(?!\s*=[^=>])'
            . '|\$subject->role\b|[\'"]Users\.(?:role|property_id)[\'"]'
            . '|\$user->get\([\'"](?:role|property_id)[\'"]\)|\$subject->get\([\'"]role[\'"]\)/';
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace(DS, '/', substr($file->getPathname(), strlen(APP)));
            if (!in_array($relative, $allowed, true) && preg_match($pattern, (string)file_get_contents($file->getPathname()))) {
                $found[] = $relative;
            }
        }
        $this->assertSame([], $found, "Read the person's role from their membership (StaffHierarchy::rolesOf, access()).");
    }

    /**
     * frontend/src/auth/permissions.js mirrors this class: the same names. A
     * typo there would silently hide a screen. The role map it once mirrored
     * (ROLE_FALLBACK) was removed in G8: screens read only user.permissions.
     */
    public function testTheFrontendMirrorsTheseDefinitions(): void
    {
        $js = (string)file_get_contents(dirname(ROOT) . '/frontend/src/auth/permissions.js');
        $this->assertNotSame('', $js, 'frontend/src/auth/permissions.js is missing');

        // \R and \r? so a Windows checkout (CRLF) reads the same as CI.
        preg_match('/export const P = \{(.*?)\R\}/s', $js, $block);
        preg_match_all("/^\s*([A-Z_]+): '([a-z_.]+)',\r?$/m", $block[1] ?? '', $pairs, PREG_SET_ORDER);
        $frontend = [];
        foreach ($pairs as [, $name, $value]) {
            $frontend[$name] = $value;
        }
        $backend = array_filter(
            (new ReflectionClass(Permissions::class))->getConstants(),
            fn($value) => is_string($value),
        );
        $this->assertSame($backend, $frontend, 'P in permissions.js must match the Permissions constants');
        $this->assertStringNotContainsString('ROLE_FALLBACK', $js, 'screens must not guess permissions from a role');
    }

    public function testTheFrontendOnlyNamesDefinedPermissions(): void
    {
        $src = dirname(ROOT) . '/frontend/src';
        $unknown = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
        foreach ($files as $file) {
            if (!in_array($file->getExtension(), ['js', 'jsx'], true)) {
                continue;
            }
            preg_match_all('/\bP\.([A-Z_]+)\b/', (string)file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $name) {
                if (!defined(Permissions::class . '::' . $name)) {
                    $unknown[] = $file->getFilename() . ': P.' . $name;
                }
            }
        }
        $this->assertSame([], $unknown);
    }

    public function testAPermissionSetAnswersOnlyForWhatItHolds(): void
    {
        $set = new PermissionSet([Permissions::GUESTS_GUEST_VIEW]);
        $this->assertTrue($set->has(Permissions::GUESTS_GUEST_VIEW));
        $this->assertFalse($set->has(Permissions::GUESTS_GUEST_MANAGE));
        $this->assertFalse($set->has('guests.guest.*'));
    }
}
