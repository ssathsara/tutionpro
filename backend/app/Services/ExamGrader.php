<?php

namespace App\Services;

use App\Models\ExamQuestion;

/**
 * Automatic marking for objective question types.
 *
 * Answer formats (as submitted by the client):
 *  - mcq:          option index (int)
 *  - multi_select: array of option indexes
 *  - true_false:   boolean
 *  - short_answer: string (compared case/space-insensitively against any accepted answer)
 *  - numeric:      number (correct_answer = [value, tolerance])
 */
class ExamGrader
{
    public function isCorrect(ExamQuestion $q, mixed $answer): bool
    {
        if ($answer === null || $answer === '' || $answer === []) {
            return false;
        }
        $correct = $q->correct_answer ?? [];

        return match ($q->type) {
            'mcq' => is_numeric($this->scalar($answer)) && (int) $this->scalar($answer) === (int) ($correct[0] ?? -1),
            'true_false' => $this->bool($this->scalar($answer)) === $this->bool($correct[0] ?? null),
            'multi_select' => $this->sameSet((array) $answer, $correct),
            'short_answer' => in_array($this->normalizeText((string) $this->scalar($answer)),
                array_map(fn ($c) => $this->normalizeText((string) $c), $correct), true),
            'numeric' => is_numeric($this->scalar($answer)) && isset($correct[0])
                && abs((float) $this->scalar($answer) - (float) $correct[0]) <= (float) ($correct[1] ?? 0) + 1e-9,
            default => false,
        };
    }

    private function scalar(mixed $v): mixed
    {
        return is_array($v) ? ($v[0] ?? null) : $v;
    }

    private function bool(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            $v = strtolower($v);
            if (in_array($v, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($v, ['false', '0', 'no'], true)) {
                return false;
            }
        }
        if (is_int($v)) {
            return $v === 1;
        }

        return null;
    }

    private function sameSet(array $a, array $b): bool
    {
        $a = array_map('intval', $a);
        $b = array_map('intval', $b);
        sort($a);
        sort($b);

        return array_values(array_unique($a)) === array_values(array_unique($b)) && count($a) > 0;
    }

    /**
     * Normalise free-text answers so equivalent spellings compare equal:
     *  - Unicode NFC (when ext-intl is available), so composed/decomposed vowel signs match
     *  - remove zero-width joiner/non-joiner/space: Sinhala conjuncts such as ශ්‍රී, ප්‍ර, ක්‍ය
     *    are typed with or without U+200D depending on the keyboard (Wijesekara, phonetic, Helakuru)
     *  - case-insensitive, whitespace collapsed
     */
    private function normalizeText(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s;
        }
        $s = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $s);

        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($s)));
    }
}
