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
     * Access goes through authorize()/can(). userHasRole() survives only for
     * the staff hierarchy (who may manage whom), which stays a role rule.
     */
    public function testRoleChecksAreOnlyUsedForTheStaffHierarchy(): void
    {
        $allowed = ['Controller/Api/AppController.php', 'Controller/Api/UsersController.php'];
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

    public function testAPermissionSetAnswersOnlyForWhatItHolds(): void
    {
        $set = new PermissionSet([Permissions::GUESTS_GUEST_VIEW]);
        $this->assertTrue($set->has(Permissions::GUESTS_GUEST_VIEW));
        $this->assertFalse($set->has(Permissions::GUESTS_GUEST_MANAGE));
        $this->assertFalse($set->has('guests.guest.*'));
    }
}
