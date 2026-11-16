<?php

namespace Tests\Unit;

use App\Models\ExamQuestion;
use App\Services\ExamGrader;
use Tests\TestCase;

class ExamGraderTest extends TestCase
{
    private function q(string $type, array $correct, ?array $options = null): ExamQuestion
    {
        return new ExamQuestion(['type' => $type, 'correct_answer' => $correct, 'options' => $options]);
    }

    public function test_mcq(): void
    {
        $g = new ExamGrader;
        $this->assertTrue($g->isCorrect($this->q('mcq', [2]), 2));
        $this->assertTrue($g->isCorrect($this->q('mcq', [2]), '2'));
        $this->assertFalse($g->isCorrect($this->q('mcq', [2]), 1));
        $this->assertFalse($g->isCorrect($this->q('mcq', [0]), null));
    }

    public function test_true_false(): void
    {
        $g = new ExamGrader;
        $this->assertTrue($g->isCorrect($this->q('true_false', [false]), false));
        $this->assertTrue($g->isCorrect($this->q('true_false', [true]), 'true'));
        $this->assertFalse($g->isCorrect($this->q('true_false', [true]), false));
    }

    public function test_multi_select_requires_exact_set(): void
    {
        $g = new ExamGrader;
        $q = $this->q('multi_select', [0, 2]);
        $this->assertTrue($g->isCorrect($q, [2, 0]));
        $this->assertFalse($g->isCorrect($q, [0]));
        $this->assertFalse($g->isCorrect($q, [0, 1, 2]));
    }

    public function test_short_answer_is_case_and_whitespace_insensitive(): void
    {
        $g = new ExamGrader;
        $q = $this->q('short_answer', ['Photosynthesis', 'photo synthesis']);
        $this->assertTrue($g->isCorrect($q, '  PHOTOSYNTHESIS '));
        $this->assertTrue($g->isCorrect($q, 'photo   synthesis'));
        $this->assertFalse($g->isCorrect($q, 'respiration'));
    }

    public function test_sinhala_short_answers_ignore_zero_width_joiners(): void
    {
        $g = new ExamGrader;
        // Teacher typed ශ්‍රී ලංකාව with ZWJ (U+200D), as Windows Sinhala keyboards do.
        $q = $this->q('short_answer', ["\u{0DC1}\u{0DCA}\u{200D}\u{0DBB}\u{0DD3} \u{0DBD}\u{0D82}\u{0D9A}\u{0DCF}\u{0DC0}"]);
        // Student typed the same word without the joiner (common on phones).
        $this->assertTrue($g->isCorrect($q, "\u{0DC1}\u{0DCA}\u{0DBB}\u{0DD3} \u{0DBD}\u{0D82}\u{0D9A}\u{0DCF}\u{0DC0}"));
        // Extra spaces are tolerated, a different word is not.
        $this->assertTrue($g->isCorrect($q, "  \u{0DC1}\u{0DCA}\u{200D}\u{0DBB}\u{0DD3}   \u{0DBD}\u{0D82}\u{0D9A}\u{0DCF}\u{0DC0} "));
        $this->assertFalse($g->isCorrect($q, 'ඉන්දියාව'));
    }

    public function test_tamil_short_answer(): void
    {
        $g = new ExamGrader;
        $this->assertTrue($g->isCorrect($this->q('short_answer', ['இதயம்']), ' இதயம் '));
    }

    public function test_numeric_tolerance(): void
    {
        $g = new ExamGrader;
        $q = $this->q('numeric', [3.14, 0.01]);
        $this->assertTrue($g->isCorrect($q, 3.145));
        $this->assertTrue($g->isCorrect($q, '3.13'));
        $this->assertFalse($g->isCorrect($q, 3.2));
        $this->assertFalse($g->isCorrect($q, 'pi'));
    }
}
