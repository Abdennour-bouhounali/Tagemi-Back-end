<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Cross-tabulations (matrices) of an event's patients and appointments:
 * ksar x sex, ksar x age group, sex x status ... each with a chi-square
 * test of independence and Cramér's V.
 *
 * Demographic matrices count patients (one per patient_id); the status and
 * specialty matrices count appointments, like the other statistics screens.
 */
class DeepStatistics
{
    public const AGE_GROUPS = ['0-17', '18-30', '31-45', '46-60', '60+'];

    // Registration stores the ksar either as a code (Tawat events) or as free
    // Arabic text; both spellings are folded into one label per ksar.
    private const KSAR_CODES = [
        'tajnint' => 'تاجنينت',
        'bonora' => 'آت بونور',
        'taghardait' => 'تغردايت',
        'mlichat' => 'آت مليشت',
        'yazgan' => 'آت يزجن',
        'berian' => 'آت برقان',
        'karara' => 'القرارة',
        'warglen' => 'وارجلان',
    ];

    private const KSAR_ALIASES = [
        'بونورة' => 'آت بونور',
        'بوتورة' => 'آت بونور',
        'غرداية' => 'تغردايت',
        'بريان' => 'آت برقان',
        'ورقلة' => 'وارجلان',
    ];

    public const UNKNOWN = 'غير محدد';

    public static function ksarOf(?string $city): string
    {
        $city = trim(preg_replace('/\s+/u', ' ', (string) $city));
        if ($city === '') {
            return self::UNKNOWN;
        }

        return self::KSAR_CODES[strtolower($city)] ?? self::KSAR_ALIASES[$city] ?? $city;
    }

    public static function sexOf($sex): string
    {
        return in_array($sex, ['0', 0, 'ذكر'], true) ? 'ذكر' : 'أنثى';
    }

    public static function ageOf($birthday): ?int
    {
        return $birthday ? Carbon::parse($birthday)->age : null;
    }

    public static function ageGroupOf(?int $age): string
    {
        return match (true) {
            $age === null => self::UNKNOWN,
            $age < 18 => '0-17',
            $age <= 30 => '18-30',
            $age <= 45 => '31-45',
            $age <= 60 => '46-60',
            default => '60+',
        };
    }

    /**
     * @param Collection $appointments items: patient_id, city, sex, birthday, status, specialty_name
     */
    public static function build(Collection $appointments): array
    {
        $rows = $appointments->map(fn ($a) => [
            'patient' => $a->patient_id ?: 'a'.$a->id,
            'ksar' => self::ksarOf($a->city),
            'sex' => self::sexOf($a->sex),
            'age' => self::ageOf($a->birthday),
            'age_group' => self::ageGroupOf(self::ageOf($a->birthday)),
            'outcome' => match (VisitStatistics::outcomeOf($a->status)) {
                'Completed' => 'أنهى الفحص',
                'Waiting' => 'حضر ولم يُفحص',
                default => 'لم يحضر',
            },
            'specialty' => $a->specialty_name ?: self::UNKNOWN,
            'status' => VisitStatistics::groupOf($a->status),
            'attended' => $a->status !== 'Pending' && VisitStatistics::outcomeOf($a->status) !== 'Pending',
            'completed' => $a->status === 'Completed',
            'day' => $a->created_at ? Carbon::parse($a->created_at)->format('Y-m-d') : null,
        ]);

        $patients = $rows->unique('patient')->values();

        $ksarOrder = $patients->countBy('ksar')->sortDesc()->keys()->all();

        return [
            'totals' => [
                'appointments' => $rows->count(),
                'patients' => $patients->count(),
                'ksars' => count($ksarOrder),
            ],
            'sex_summary' => self::sexSummary($patients, $rows),
            'ksar_summary' => self::ksarSummary($patients, $rows, $ksarOrder),
            'pyramid' => self::pyramid($patients),
            'timeline' => self::timeline($rows),
            'insights' => self::insights($patients, $rows, $ksarOrder),
            'matrices' => self::matrices($rows),
            // Same matrices restricted to one appointment status group; a patient
            // counts in a group when one of their appointments is in it.
            'matrices_by_status' => collect(array_keys(VisitStatistics::GROUPS))
                ->mapWithKeys(fn ($group) => [$group => self::matrices($rows->where('status', $group)->values())])
                ->all(),
        ];
    }

    private static function matrices(Collection $rows): array
    {
        $patients = $rows->unique('patient')->values();

        $ksarOrder = $patients->countBy('ksar')->sortDesc()->keys()->all();
        $specialtyOrder = $rows->countBy('specialty')->sortDesc()->keys()->all();
        $sexOrder = ['ذكر', 'أنثى'];
        $outcomeOrder = ['أنهى الفحص', 'حضر ولم يُفحص', 'لم يحضر'];
        $ageOrder = self::AGE_GROUPS;
        if ($patients->contains('age_group', self::UNKNOWN)) {
            $ageOrder[] = self::UNKNOWN;
        }

        return [
            'ksar_sex' => self::matrix('القصر × الجنس', 'patients', $patients, 'ksar', 'sex', $ksarOrder, $sexOrder),
            'ksar_age' => self::matrix('القصر × الفئة العمرية', 'patients', $patients, 'ksar', 'age_group', $ksarOrder, $ageOrder),
            'sex_age' => self::matrix('الجنس × الفئة العمرية', 'patients', $patients, 'sex', 'age_group', $sexOrder, $ageOrder),
            'ksar_outcome' => self::matrix('القصر × الحالة', 'appointments', $rows, 'ksar', 'outcome', $ksarOrder, $outcomeOrder),
            'sex_outcome' => self::matrix('الجنس × الحالة', 'appointments', $rows, 'sex', 'outcome', $sexOrder, $outcomeOrder),
            'ksar_specialty' => self::matrix('القصر × التخصص', 'appointments', $rows, 'ksar', 'specialty', $ksarOrder, $specialtyOrder),
            'sex_specialty' => self::matrix('الجنس × التخصص', 'appointments', $rows, 'sex', 'specialty', $sexOrder, $specialtyOrder),
        ];
    }

    private static function matrix(string $title, string $unit, Collection $items, string $rowKey, string $colKey, array $rowLabels, array $colLabels): array
    {
        $cells = [];
        foreach ($rowLabels as $r => $rowLabel) {
            foreach ($colLabels as $c => $colLabel) {
                $cells[$r][$c] = $items->where($rowKey, $rowLabel)->where($colKey, $colLabel)->count();
            }
        }

        return [
            'title' => $title,
            'unit' => $unit,
            'rows' => $rowLabels,
            'cols' => $colLabels,
            'cells' => $cells,
            'test' => self::chiSquare($cells),
            'residuals' => self::residuals($cells),
        ];
    }

    private static function sexSummary(Collection $patients, Collection $rows): array
    {
        return collect(['ذكر', 'أنثى'])->map(function ($sex) use ($patients, $rows) {
            $ages = $patients->where('sex', $sex)->pluck('age')->filter(fn ($a) => $a !== null)->sort()->values();

            return [
                'sex' => $sex,
                'count' => $patients->where('sex', $sex)->count(),
                'minors' => $patients->where('sex', $sex)->where('age_group', '0-17')->count(),
                'mean_age' => $ages->isEmpty() ? null : round($ages->avg(), 1),
                'median_age' => self::median($ages),
            ] + self::rates($rows->where('sex', $sex));
        })->all();
    }

    /** Attendance / completion rates and specialties per patient for a set of appointments. */
    private static function rates(Collection $rows): array
    {
        $n = $rows->count();
        $patients = $rows->unique('patient')->count();

        return [
            'appointments' => $n,
            'attendance_rate' => $n ? round($rows->where('attended', true)->count() / $n * 100, 1) : null,
            'completion_rate' => $n ? round($rows->where('completed', true)->count() / $n * 100, 1) : null,
            'per_patient' => $patients ? round($n / $patients, 2) : null,
        ];
    }

    private static function ksarSummary(Collection $patients, Collection $rows, array $ksarOrder): array
    {
        return collect($ksarOrder)->map(function ($ksar) use ($patients, $rows) {
            $group = $patients->where('ksar', $ksar);
            $males = $group->where('sex', 'ذكر')->count();
            $females = $group->where('sex', 'أنثى')->count();
            $ages = $group->pluck('age')->filter(fn ($a) => $a !== null)->sort()->values();

            return [
                'ksar' => $ksar,
                'count' => $group->count(),
                'males' => $males,
                'females' => $females,
                'males_per_100_females' => $females > 0 ? round($males / $females * 100) : null,
                'minors' => $group->where('age_group', '0-17')->count(),
                'mean_age' => $ages->isEmpty() ? null : round($ages->avg(), 1),
                'median_age' => self::median($ages),
            ] + self::rates($rows->where('ksar', $ksar));
        })->all();
    }

    /** Patients per 10-year band and sex, for the age pyramid. */
    private static function pyramid(Collection $patients): array
    {
        $bands = [];
        foreach (range(0, 7) as $i) {
            $label = $i === 7 ? '70+' : ($i * 10).'-'.($i * 10 + 9);
            $inBand = $patients->filter(fn ($p) => $p['age'] !== null && min(intdiv($p['age'], 10), 7) === $i);
            $bands[] = [
                'band' => $label,
                'males' => $inBand->where('sex', 'ذكر')->count(),
                'females' => $inBand->where('sex', 'أنثى')->count(),
            ];
        }

        return $bands;
    }

    /** Registrations (appointments) per creation day, split by sex of unique patients. */
    private static function timeline(Collection $rows): array
    {
        return $rows->whereNotNull('day')->groupBy('day')->sortKeys()->map(function ($group, $day) {
            $patients = $group->unique('patient');

            return [
                'day' => $day,
                'patients' => $patients->count(),
                'males' => $patients->where('sex', 'ذكر')->count(),
                'females' => $patients->where('sex', 'أنثى')->count(),
            ];
        })->values()->all();
    }

    /** Adjusted standardized residuals; |z| > 1.96 marks a cell that departs from independence (5%). */
    private static function residuals(array $cells): ?array
    {
        $rowTotals = array_map('array_sum', $cells);
        $colTotals = [];
        foreach (array_keys($cells[0] ?? []) as $c) {
            $colTotals[$c] = array_sum(array_column($cells, $c));
        }
        $n = array_sum($rowTotals);
        if ($n === 0) {
            return null;
        }

        $out = [];
        foreach ($cells as $r => $row) {
            foreach ($row as $c => $observed) {
                $expected = $rowTotals[$r] * $colTotals[$c] / $n;
                $variance = $expected * (1 - $rowTotals[$r] / $n) * (1 - $colTotals[$c] / $n);
                $out[$r][$c] = $variance > 0 ? round(($observed - $expected) / sqrt($variance), 1) : null;
            }
        }

        return $out;
    }

    /** Plain-Arabic findings, so the numbers can be read at a glance. */
    private static function insights(Collection $patients, Collection $rows, array $ksarOrder): array
    {
        $out = [];
        $n = $patients->count();
        if ($n === 0) {
            return $out;
        }
        $pc = fn ($part, $total) => $total ? round($part / $total * 100, 1) : 0;

        $top = $ksarOrder[0];
        $topCount = $patients->where('ksar', $top)->count();
        $out[] = "أكبر قصر تمثيلاً: {$top} بـ {$topCount} مريض ({$pc($topCount, $n)}%).";

        $m = $patients->where('sex', 'ذكر')->count();
        $f = $n - $m;
        if ($f > 0) {
            $out[] = 'نسبة الجنس: '.round($m / $f * 100)." ذكراً لكل 100 أنثى ({$pc($m, $n)}% ذكور).";
        }

        $minors = $patients->where('age_group', '0-17')->count();
        $out[] = "القصّر (أقل من 18 سنة): {$minors} ({$pc($minors, $n)}%).";

        $ageGroups = $patients->where('age_group', '!=', self::UNKNOWN)->countBy('age_group')->sortDesc();
        if ($ageGroups->isNotEmpty()) {
            $out[] = 'الفئة العمرية الأكثر: '.$ageGroups->keys()->first().' ('.$pc($ageGroups->first(), $n).'%).';
        }

        $big = collect($ksarOrder)->filter(fn ($k) => $patients->where('ksar', $k)->count() >= 15);
        $share = fn ($k, $sex) => $pc($patients->where('ksar', $k)->where('sex', $sex)->count(), $patients->where('ksar', $k)->count());
        if ($big->count() >= 2) {
            $maleTop = $big->sortByDesc(fn ($k) => $share($k, 'ذكر'))->first();
            $femTop = $big->sortByDesc(fn ($k) => $share($k, 'أنثى'))->first();
            $out[] = "أعلى نسبة ذكور: {$maleTop} ({$share($maleTop, 'ذكر')}%) ، وأعلى نسبة إناث: {$femTop} ({$share($femTop, 'أنثى')}%).";
        }

        $byAbsence = collect($ksarOrder)->filter(fn ($k) => $rows->where('ksar', $k)->count() >= 15);
        if ($byAbsence->count() >= 2 && $rows->where('attended', true)->count() > 0) {
            $rate = fn ($k) => $pc($rows->where('ksar', $k)->where('attended', true)->count(), $rows->where('ksar', $k)->count());
            $best = $byAbsence->sortByDesc($rate)->first();
            $worst = $byAbsence->sortBy($rate)->first();
            $out[] = "أعلى نسبة حضور: {$best} ({$rate($best)}%) ، وأدنى نسبة حضور: {$worst} ({$rate($worst)}%).";
        }

        $matrix = self::matrixCells($patients, 'ksar', 'sex', $ksarOrder, ['ذكر', 'أنثى']);
        $z = self::residuals($matrix);
        foreach ($ksarOrder as $r => $ksar) {
            foreach (['ذكر', 'أنثى'] as $c => $sex) {
                if (($z[$r][$c] ?? 0) >= 1.96 && array_sum($matrix[$r]) >= 10) {
                    $out[] = "في {$ksar} عدد ".($sex === 'ذكر' ? 'الذكور' : 'الإناث').' أعلى من المتوقع بدلالة إحصائية (z = '.$z[$r][$c].').';
                }
            }
        }

        return $out;
    }

    private static function matrixCells(Collection $items, string $rowKey, string $colKey, array $rowLabels, array $colLabels): array
    {
        $cells = [];
        foreach ($rowLabels as $r => $rl) {
            foreach ($colLabels as $c => $cl) {
                $cells[$r][$c] = $items->where($rowKey, $rl)->where($colKey, $cl)->count();
            }
        }

        return $cells;
    }

    private static function median(Collection $sorted): ?float
    {
        $n = $sorted->count();
        if ($n === 0) {
            return null;
        }
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }

    /**
     * Pearson chi-square test of independence on a contingency table.
     * Empty rows/columns are ignored. Returns null when it is not defined.
     */
    public static function chiSquare(array $cells): ?array
    {
        $rowTotals = array_map('array_sum', $cells);
        $colCount = count($cells[0] ?? []);
        $colTotals = [];
        for ($c = 0; $c < $colCount; $c++) {
            $colTotals[$c] = array_sum(array_column($cells, $c));
        }
        $n = array_sum($rowTotals);
        $liveRows = array_keys(array_filter($rowTotals));
        $liveCols = array_keys(array_filter($colTotals));
        if ($n === 0 || count($liveRows) < 2 || count($liveCols) < 2) {
            return null;
        }

        $chi = 0.0;
        $small = 0;
        foreach ($liveRows as $r) {
            foreach ($liveCols as $c) {
                $expected = $rowTotals[$r] * $colTotals[$c] / $n;
                $chi += ($cells[$r][$c] - $expected) ** 2 / $expected;
                if ($expected < 5) {
                    $small++;
                }
            }
        }

        $df = (count($liveRows) - 1) * (count($liveCols) - 1);
        $v = sqrt($chi / ($n * (min(count($liveRows), count($liveCols)) - 1)));

        return [
            'chi_square' => round($chi, 2),
            'df' => $df,
            'p_value' => self::chiSquarePValue($chi, $df),
            'cramers_v' => round($v, 3),
            'small_expected_share' => round($small / (count($liveRows) * count($liveCols)) * 100),
        ];
    }

    /** P(X >= x) for a chi-square variable: the upper regularized gamma Q(df/2, x/2). */
    private static function chiSquarePValue(float $x, int $df): float
    {
        $a = $df / 2;
        $x = $x / 2;
        if ($x <= 0) {
            return 1.0;
        }
        $lnGamma = self::lnGamma($a);

        if ($x < $a + 1) { // series for the lower function
            $sum = $term = 1 / $a;
            for ($i = 1; $i < 500; $i++) {
                $term *= $x / ($a + $i);
                $sum += $term;
                if (abs($term) < abs($sum) * 1e-12) {
                    break;
                }
            }

            return max(0.0, min(1.0, 1 - $sum * exp(-$x + $a * log($x) - $lnGamma)));
        }

        // continued fraction for the upper function (modified Lentz)
        $tiny = 1e-300;
        $b = $x + 1 - $a;
        $c = 1 / $tiny;
        $d = 1 / $b;
        $h = $d;
        for ($i = 1; $i < 500; $i++) {
            $an = -$i * ($i - $a);
            $b += 2;
            $d = $an * $d + $b;
            $d = abs($d) < $tiny ? $tiny : $d;
            $c = $b + $an / $c;
            $c = abs($c) < $tiny ? $tiny : $c;
            $d = 1 / $d;
            $delta = $d * $c;
            $h *= $delta;
            if (abs($delta - 1) < 1e-12) {
                break;
            }
        }

        return max(0.0, min(1.0, exp(-$x + $a * log($x) - $lnGamma) * $h));
    }

    private static function lnGamma(float $z): float
    {
        static $g = [76.18009172947146, -86.50532032941677, 24.01409824083091,
            -1.231739572450155, 0.1208650973866179e-2, -0.5395239384953e-5];
        $y = $z;
        $tmp = $z + 5.5;
        $tmp -= ($z + 0.5) * log($tmp);
        $ser = 1.000000000190015;
        foreach ($g as $coef) {
            $ser += $coef / ++$y;
        }

        return -$tmp + log(2.5066282746310005 * $ser / $z);
    }
}
