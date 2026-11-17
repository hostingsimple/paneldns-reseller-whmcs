<?php

/**
 * Stand-ins for WHMCS 8's signed-in user (https://developers.whmcs.com/advanced/authentication/).
 *
 * \WHMCS\Authentication\CurrentUser answers user() and client() from static state a
 * test sets; FakeWhmcsClient::isOwnedBy() mirrors \WHMCS\User\Client::isOwnedBy(User).
 * Default: nobody is signed in.
 */

namespace WHMCS\Authentication {
    if (!class_exists('WHMCS\\Authentication\\CurrentUser', false)) {
        class CurrentUser
        {
            /** @var null|object */ public static $user = null;
            /** @var null|object */ public static $client = null;

            public function user() { return self::$user; }
            public function client() { return self::$client; }
            public function isAuthenticatedUser(): bool { return self::$user !== null; }
            public function isAuthenticatedAdmin(): bool { return false; }
            public function isMasqueradingAdmin(): bool { return false; }

            public static function reset(): void
            {
                self::$user = null;
                self::$client = null;
            }

            /** Sign in user $userId on client account $clientId, owned by user $ownerId. */
            public static function signIn(int $userId, int $clientId, int $ownerId): void
            {
                self::$user = new \FakeWhmcsUser($userId);
                self::$client = new \FakeWhmcsClient($clientId, $ownerId);
            }
        }
    }
}

namespace {
    final class FakeWhmcsUser
    {
        public function __construct(public int $id) {}
    }

    final class FakeWhmcsClient
    {
        public function __construct(public int $id, public int $ownerUserId) {}

        public function isOwnedBy($user): bool
        {
            return is_object($user) && (int) $user->id === $this->ownerUserId;
        }
    }
}
