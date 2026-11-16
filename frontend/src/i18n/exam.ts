/**
 * Interface strings for the student exam experience (taking an exam and viewing results),
 * shown in the exam's language: Sinhala (si), Tamil (ta) or English (en).
 */
export type ExamLang = 'si' | 'ta' | 'en'

export const EXAM_LANGUAGES: { value: ExamLang; label: string }[] = [
  { value: 'si', label: 'සිංහල (Sinhala)' },
  { value: 'ta', label: 'தமிழ் (Tamil)' },
  { value: 'en', label: 'English' },
]

const STRINGS = {
  en: {
    answered: '{a} / {t} answered', autosave: 'answers are saved automatically', timeLeft: 'Time left',
    submit: 'Submit', question: 'Question {n} of {t}', marks: '{m} mark(s)', selectAll: 'Select all that apply',
    typeAnswer: 'Type your answer', yourAnswerPh: 'Your answer', previous: 'Previous', next: 'Next',
    finish: 'Finish & submit', questions: 'Questions', starting: 'Starting exam…', back: 'Back to exams',
    confirmSubmit: 'You have answered {a} of {t} questions. Submit anyway?',
    timeUp: 'Time is up. Your answers were submitted.', submitted: 'Exam submitted',
    true: 'True', false: 'False', yourAnswer: 'Your answer', correctAnswer: 'Correct answer', noAnswer: 'No answer',
    scoreOf: '{s} / {t} marks', passMark: 'Pass mark {p}%', passed: 'Passed', failed: 'Failed',
    hidden: 'Your answers were submitted. Results for this exam will be released by your teacher.',
    submittedAt: 'Submitted', explanation: 'Explanation',
  },
  si: {
    answered: '{t} න් {a} කට පිළිතුරු දී ඇත', autosave: 'පිළිතුරු ස්වයංක්‍රීයව සුරැකේ', timeLeft: 'ඉතිරි කාලය',
    submit: 'යොමු කරන්න', question: 'ප්‍රශ්නය {n} / {t}', marks: 'ලකුණු {m}', selectAll: 'අදාළ සියල්ල තෝරන්න',
    typeAnswer: 'ඔබේ පිළිතුර ටයිප් කරන්න', yourAnswerPh: 'ඔබේ පිළිතුර', previous: 'පෙර', next: 'ඊළඟ',
    finish: 'අවසන් කර යොමු කරන්න', questions: 'ප්‍රශ්න', starting: 'විභාගය ආරම්භ වෙමින්…', back: 'විභාග වෙත ආපසු',
    confirmSubmit: 'ඔබ ප්‍රශ්න {t} න් {a} කට පිළිතුරු දී ඇත. කෙසේ වෙතත් යොමු කරන්නද?',
    timeUp: 'කාලය අවසන්. ඔබේ පිළිතුරු යොමු කරන ලදී.', submitted: 'විභාගය යොමු කරන ලදී',
    true: 'සත්‍ය', false: 'අසත්‍ය', yourAnswer: 'ඔබේ පිළිතුර', correctAnswer: 'නිවැරදි පිළිතුර', noAnswer: 'පිළිතුරක් නැත',
    scoreOf: 'ලකුණු {s} / {t}', passMark: 'සමත් ලකුණ {p}%', passed: 'සමත්', failed: 'අසමත්',
    hidden: 'ඔබේ පිළිතුරු යොමු කරන ලදී. මෙම විභාගයේ ප්‍රතිඵල ඔබේ ගුරුවරයා විසින් නිකුත් කරනු ඇත.',
    submittedAt: 'යොමු කළේ', explanation: 'පැහැදිලි කිරීම',
  },
  ta: {
    answered: '{a} / {t} பதிலளிக்கப்பட்டது', autosave: 'பதில்கள் தானாகச் சேமிக்கப்படும்', timeLeft: 'மீதமுள்ள நேரம்',
    submit: 'சமர்ப்பி', question: 'கேள்வி {n} / {t}', marks: '{m} புள்ளிகள்', selectAll: 'பொருந்தும் அனைத்தையும் தேர்ந்தெடுக்கவும்',
    typeAnswer: 'உங்கள் பதிலைத் தட்டச்சு செய்யவும்', yourAnswerPh: 'உங்கள் பதில்', previous: 'முந்தைய', next: 'அடுத்து',
    finish: 'முடித்து சமர்ப்பி', questions: 'கேள்விகள்', starting: 'தேர்வு தொடங்குகிறது…', back: 'தேர்வுகளுக்குத் திரும்பு',
    confirmSubmit: '{t} கேள்விகளில் {a} கேள்விகளுக்கு பதிலளித்துள்ளீர்கள். இருந்தாலும் சமர்ப்பிக்கவா?',
    timeUp: 'நேரம் முடிந்தது. உங்கள் பதில்கள் சமர்ப்பிக்கப்பட்டன.', submitted: 'தேர்வு சமர்ப்பிக்கப்பட்டது',
    true: 'சரி', false: 'தவறு', yourAnswer: 'உங்கள் பதில்', correctAnswer: 'சரியான பதில்', noAnswer: 'பதில் இல்லை',
    scoreOf: '{s} / {t} புள்ளிகள்', passMark: 'தேர்ச்சி புள்ளி {p}%', passed: 'தேர்ச்சி', failed: 'தோல்வி',
    hidden: 'உங்கள் பதில்கள் சமர்ப்பிக்கப்பட்டன. இந்தத் தேர்வின் முடிவுகளை உங்கள் ஆசிரியர் வெளியிடுவார்.',
    submittedAt: 'சமர்ப்பித்தது', explanation: 'விளக்கம்',
  },
} as const

export type ExamStringKey = keyof typeof STRINGS.en

export function normalizeLang(lang?: string | null): ExamLang {
  return lang === 'si' || lang === 'ta' ? lang : 'en'
}

/** Translate an exam UI string, replacing {placeholders} with params. */
export function et(lang: string | null | undefined, key: ExamStringKey, params: Record<string, string | number> = {}): string {
  const s: string = STRINGS[normalizeLang(lang)][key] ?? STRINGS.en[key]
  return s.replace(/\{(\w+)\}/g, (_, k) => String(params[k] ?? ''))
}
