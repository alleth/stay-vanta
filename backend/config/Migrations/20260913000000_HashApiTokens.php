<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * `users.api_token` now stores a SHA-256 digest of the token rather than the
 * token itself (see UsersTable::hashToken), so a leaked database no longer
 * yields usable sessions.
 *
 * The column already fits — it is string(64), and hex SHA-256 is exactly 64
 * characters — so there is no schema change here. What this migration does is
 * clear the tokens that are still sitting in the table in plaintext: they can
 * never match a digest lookup again, and leaving them would leave live
 * credentials in every backup taken from here on.
 *
 * The visible effect is that everyone signed in at deploy time signs in again.
 */
class HashApiTokens extends BaseMigration
{
    /**
     * Clear every stored plaintext token.
     */
    public function up(): void
    {
        $this->execute('UPDATE users SET api_token = NULL, token_expires = NULL WHERE api_token IS NOT NULL');
    }

    /**
     * Irreversible by design: the plaintext tokens are gone, and that is the
     * point. Rolling back simply leaves everyone signed out, which is a safe
     * state — they sign in again and receive a token in whichever format the
     * code at that moment issues.
     */
    public function down(): void
    {
    }
}
