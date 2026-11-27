<?php

namespace Tests\Unit;

use App\Support\UiStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UiStatusTest extends TestCase
{
    #[DataProvider('statuses')]
    public function test_status_maps_to_semantic_variant(?string $status, string $variant): void
    {
        $this->assertSame($variant, UiStatus::variant($status));
    }

    public static function statuses(): array
    {
        return [
            'active' => ['active', 'success'],
            'case + whitespace' => ['  ACTIVE ', 'success'],
            'expired' => ['expired', 'danger'],
            'pending' => ['pending', 'warning'],
            'suspended' => ['suspended', 'warning'],
            'terminated' => ['terminated', 'danger'],
            'underscored' => ['in_progress', 'warning'],
            'partial match' => ['Transfer pending', 'warning'],
            'failed partial' => ['sync failed (timeout)', 'danger'],
            'unknown' => ['whatever', 'neutral'],
            'empty' => ['', 'neutral'],
            'null' => [null, 'neutral'],
        ];
    }

    public function test_label_is_humanised(): void
    {
        $this->assertSame('In progress', UiStatus::label('IN_PROGRESS'));
        $this->assertSame('-', UiStatus::label(null));
    }
}
