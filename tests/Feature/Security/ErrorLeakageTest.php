<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class ErrorLeakageTest extends TestCase
{
    public function test_web_controllers_do_not_echo_raw_exception_messages_for_dns_and_cpanel(): void
    {
        foreach ([
            'app/Http/Controllers/DnsController.php',
            'app/Http/Controllers/Admin/ServicesController.php',
        ] as $file) {
            $src = file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression(
                "/(with\\(|flash\\()[^;]*\\.\\s*\\\$e->getMessage\\(\\)/s",
                $src,
                "$file echoes a raw exception message to the user"
            );
        }
    }

    public function test_api_sync_endpoints_do_not_forward_registrar_error_text(): void
    {
        foreach (['Domain', 'Hosting', 'Ssl'] as $c) {
            $src = file_get_contents(base_path("app/Http/Controllers/Api/{$c}Controller.php"));
            $this->assertStringNotContainsString("'Synergy error: '", $src);
        }
    }
}
