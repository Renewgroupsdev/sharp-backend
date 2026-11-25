<?php

namespace Tests\Unit;

use App\Services\SpecialistService;
use PHPUnit\Framework\TestCase;

class SpecialistServiceTest extends TestCase
{
    public function test_each_name_is_kept_once_ignoring_case_and_spacing(): void
    {
        $this->assertSame('Startup, Lead ership, New', SpecialistService::normalize(' Startup,startup , Lead  ership,, New '));
    }

    public function test_nothing_left_becomes_null(): void
    {
        $this->assertNull(SpecialistService::normalize(' , , '));
        $this->assertNull(SpecialistService::normalize(null));
    }
}
