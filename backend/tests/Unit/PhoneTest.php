<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    public function test_normalizes_sri_lankan_numbers(): void
    {
        $this->assertSame('94771234567', Phone::normalize('0771234567'));
        $this->assertSame('94771234567', Phone::normalize('+94 77 123 4567'));
        $this->assertSame('94771234567', Phone::normalize('94771234567'));
        $this->assertNull(Phone::normalize('12345'));
        $this->assertSame(1, preg_match(Phone::SL_MOBILE_REGEX, '0712345678'));
        $this->assertSame(0, preg_match(Phone::SL_MOBILE_REGEX, '0112345678'));
    }
}
