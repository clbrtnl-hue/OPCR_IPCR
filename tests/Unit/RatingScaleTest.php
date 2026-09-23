<?php

namespace Tests\Unit;

use App\Services\RatingScale;
use PHPUnit\Framework\TestCase;

class RatingScaleTest extends TestCase
{
    public function test_average_of_q_e_t(): void
    {
        $this->assertSame(4.0, RatingScale::average(5, 4, 3));
        $this->assertSame(5.0, RatingScale::average(5, 5, 5));
        $this->assertSame(4.33, RatingScale::average(5, 4, 4));
    }

    public function test_average_ignores_missing_dimensions(): void
    {
        $this->assertSame(4.5, RatingScale::average(5, 4, null));
        $this->assertSame(3.0, RatingScale::average(null, null, 3));
        $this->assertNull(RatingScale::average(null, null, null));
    }

    public function test_adjectival_bands_match_the_printed_legend(): void
    {
        $this->assertSame('Outstanding', RatingScale::adjectival(5.0));
        $this->assertSame('Outstanding', RatingScale::adjectival(4.5));
        $this->assertSame('Very Satisfactory', RatingScale::adjectival(4.49));
        $this->assertSame('Very Satisfactory', RatingScale::adjectival(3.5));
        $this->assertSame('Satisfactory', RatingScale::adjectival(3.49));
        $this->assertSame('Satisfactory', RatingScale::adjectival(2.5));
        $this->assertSame('Unsatisfactory', RatingScale::adjectival(2.49));
        $this->assertSame('Unsatisfactory', RatingScale::adjectival(1.5));
        $this->assertSame('Poor', RatingScale::adjectival(1.49));
        $this->assertSame('Poor', RatingScale::adjectival(1.0));
        $this->assertNull(RatingScale::adjectival(null));
    }

    public function test_mean_skips_unrated_sections(): void
    {
        $this->assertSame(4.0, RatingScale::mean([5.0, 3.0]));
        $this->assertSame(4.0, RatingScale::mean([5.0, null, 3.0]));
        $this->assertNull(RatingScale::mean([null, null]));
    }
}
