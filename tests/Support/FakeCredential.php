<?php

namespace Tests\Support;

/**
 * Throw-away credentials for tests, generated at run time so no password-like literal is committed
 * (keeps secret scanners such as GitGuardian quiet). Values meet the app's password rules.
 */
final class FakeCredential
{
    public static function password(): string
    {
        return 'Tt9!'.bin2hex(random_bytes(10));
    }

    /** A fake secret with a readable label, e.g. FakeCredential::secret('halo'). */
    public static function secret(string $label = 'value'): string
    {
        return 'fake-'.$label.'-'.bin2hex(random_bytes(6));
    }
}
