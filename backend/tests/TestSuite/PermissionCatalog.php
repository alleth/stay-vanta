<?php
declare(strict_types=1);

namespace App\Test\TestSuite;

use RuntimeException;

/**
 * Reads the approved permission catalog (docs/PERMISSIONS.md, "## Catalog").
 *
 * The catalog is the reviewed source of truth for who holds what, so the tests
 * read it rather than keeping a second copy: the access matrix checks the API
 * against it, and PermissionsTest checks App\Auth\Permissions against it. A
 * change of grants therefore has to be made in the document, where it's
 * reviewed, and in the code, or a test fails.
 */
final class PermissionCatalog
{
    private const HOLDERS = ['PO' => 'owner', 'M' => 'admin', 'FD' => 'receptionist'];

    /**
     * @var array<string, array{roles: list<string>, elevated: bool, module: string}>|null
     */
    private static ?array $rows = null;

    /**
     * @return array<string, array{roles: list<string>, elevated: bool, module: string}>
     */
    public static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        $path = dirname(ROOT) . DS . 'docs' . DS . 'PERMISSIONS.md';
        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("Can't read the permission catalog at $path.");
        }
        if (!preg_match('/^## Catalog\R(.*?)(?=^## )/ms', $text, $section)) {
            throw new RuntimeException('docs/PERMISSIONS.md has no "## Catalog" section.');
        }

        $rows = [];
        foreach (preg_split('/\R/', $section[1]) as $line) {
            if (!preg_match('/^\| `([a-z_.]+)` \|([^|]*)\|[^|]*\|([^|]*)\|([^|]*)\|$/', $line, $m)) {
                continue;
            }
            $roles = [];
            foreach (explode(',', str_replace('†', '', $m[2])) as $holder) {
                $holder = trim($holder);
                if (!isset(self::HOLDERS[$holder])) {
                    throw new RuntimeException("Unknown holder '$holder' for $m[1] in docs/PERMISSIONS.md.");
                }
                $roles[] = self::HOLDERS[$holder];
            }
            if (isset($rows[$m[1]])) {
                throw new RuntimeException("$m[1] is listed twice in docs/PERMISSIONS.md.");
            }
            $rows[$m[1]] = ['roles' => $roles, 'elevated' => trim($m[3]) === 'yes', 'module' => trim($m[4])];
        }
        if ($rows === []) {
            throw new RuntimeException('The catalog table in docs/PERMISSIONS.md has no rows.');
        }

        return self::$rows = $rows;
    }

    /**
     * Whether the catalog grants a permission to a stored role value.
     */
    public static function grants(string $role, string $permission): bool
    {
        $rows = self::rows();
        if (!isset($rows[$permission])) {
            throw new RuntimeException("$permission isn't in docs/PERMISSIONS.md.");
        }

        return in_array($role, $rows[$permission]['roles'], true);
    }
}
