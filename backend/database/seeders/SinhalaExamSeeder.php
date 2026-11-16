<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Exam;
use App\Support\Tenant;
use Illuminate\Database\Seeder;

/**
 * A Sinhala-medium O/L Science quiz for each institute's Sinhala "Science" batch.
 * Safe to run on an existing database: php artisan db:seed --class=SinhalaExamSeeder
 */
class SinhalaExamSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = app(Tenant::class);
        $batches = Batch::withoutGlobalScopes()->where('medium', 'Sinhala')->where('name', 'like', '%Science%')->with('teacher')->get();

        foreach ($batches as $batch) {
            $tenant->set($batch->institute_id);
            if (Exam::where('batch_id', $batch->id)->where('language', 'si')->exists()) {
                continue;
            }

            $exam = Exam::create([
                'batch_id' => $batch->id,
                'created_by' => $batch->teacher?->user_id,
                'title' => 'විද්‍යාව - සතිපතා ප්‍රශ්නාවලිය 01',
                'language' => 'si',
                'instructions' => "සියලුම ප්‍රශ්නවලට පිළිතුරු සපයන්න.\nඔබට විනාඩි 20ක් ඇත. කාලය අවසන් වූ විට පිළිතුරු ස්වයංක්‍රීයව යොමු වේ.",
                'duration_minutes' => 20,
                'pass_percentage' => 40,
                'max_attempts' => 2,
                'shuffle_questions' => false,
                'show_results' => true,
                'is_published' => true,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(10),
            ]);

            $questions = [
                ['mcq', 'ජලයේ රසායනික සූත්‍රය කුමක්ද?', ['H₂O', 'CO₂', 'O₂', 'NaCl'], [0], 1,
                    'ජල අණුවක හයිඩ්‍රජන් පරමාණු දෙකක් සහ ඔක්සිජන් පරමාණුවක් ඇත.'],
                ['mcq', 'හරිත ශාක සූර්යාලෝකය භාවිතයෙන් ආහාර නිපදවන ක්‍රියාවලිය කුමක්ද?', ['ශ්වසනය', 'ප්‍රභාසංශ්ලේෂණය', 'උත්ස්වේදනය', 'ජීරණය'], [1], 1, null],
                ['true_false', 'සූර්යයා තාරකාවකි.', null, [true], 1, null],
                ['multi_select', 'පහත ඒවායින් ලෝහ මොනවාද? (නිවැරදි සියල්ල තෝරන්න)', ['යකඩ', 'තඹ', 'ඔක්සිජන්', 'කාබන්'], [0, 1], 2, null],
                ['numeric', 'මිනිත්තු 2ක තත්පර කීයක් තිබේද?', null, [120, 0], 1, null],
                ['short_answer', 'මිනිස් සිරුරේ රුධිරය පොම්ප කරන ඉන්ද්‍රිය කුමක්ද?', null, ['හදවත', 'හෘදය'], 1,
                    'හදවත (හෘදය) රුධිරය සිරුර පුරා පොම්ප කරයි.'],
            ];
            foreach ($questions as $pos => [$type, $text, $options, $correct, $marks, $explanation]) {
                $exam->questions()->create([
                    'type' => $type, 'question' => $text, 'options' => $type === 'true_false' ? ['True', 'False'] : $options,
                    'correct_answer' => $correct, 'marks' => $marks, 'explanation' => $explanation, 'position' => $pos,
                ]);
            }
        }

        $tenant->set(null);
    }
}
