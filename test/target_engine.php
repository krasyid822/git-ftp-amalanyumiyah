<?php
// FILE: test/target_engine.php
// Mesin perhitungan aturan target (target_settings.json).
// Dipakai bersama oleh:
//   - test/bi.php      (halaman analisis & panel target)
//   - test/index.php   (kartu target di halaman penginputan mentee)
// Fungsi di sini sengaja datar (tanpa kelas) agar bisa di-require kapan saja.
// bi_normalize_quran_key di-guard karena test/index.php punya definisi sendiri
// (isinya identik), sehingga tidak pernah bentrok "Cannot redeclare".

function bi_read_json(string $file): array
{
	if (!is_file($file)) {
		return [];
	}

	$content = file_get_contents($file);
	if ($content === false || trim($content) === '') {
		return [];
	}

	$data = json_decode($content, true);
	return is_array($data) ? $data : [];
}

function bi_rawatib_detail_keys(): array
{
	return [
		'rawatib_subuh_q',
		'rawatib_dzuhur_q',
		'rawatib_dzuhur_b',
		'rawatib_maghrib_b',
		'rawatib_isya_b',
	];
}

function bi_rawatib_detail_count(array $data = []): int
{
	// Jumlah opsi rawatib itu tetap, tidak bergantung pada isi data.
	$count = count(bi_rawatib_detail_keys());
	return $count > 0 ? $count : 5;
}

function bi_is_filled_value(mixed $value): bool
{
	if (is_string($value)) {
		return trim($value) !== '';
	}

	return !empty($value);
}

if (!function_exists('bi_normalize_quran_key')) {
function bi_normalize_quran_key(string $value): string
{
	$value = strtolower(trim($value));
	$value = preg_replace('/^(surah|surat|qs)\s+/iu', '', $value) ?? $value;
	$value = str_replace(['’', '`', '´'], "'", $value);
	$value = preg_replace('/[^a-z0-9]+/', '', $value);

	return is_string($value) ? $value : '';
}
}

function bi_load_quran_reference_data(string $sourceDataset = ''): array
{
	static $cache = [];

	$csvFile = __DIR__ . DIRECTORY_SEPARATOR . 'dataset_halaman_quran.csv';
	if ($sourceDataset !== '') {
		$sourceDataset = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourceDataset);
		$workspaceCandidate = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($sourceDataset, DIRECTORY_SEPARATOR);
		$localCandidate = __DIR__ . DIRECTORY_SEPARATOR . basename($sourceDataset);
		if (is_file($workspaceCandidate)) {
			$csvFile = $workspaceCandidate;
		} elseif (is_file($localCandidate)) {
			$csvFile = $localCandidate;
		}
	}

	$cacheKey = realpath($csvFile) ?: $csvFile;
	if (isset($cache[$cacheKey])) {
		return $cache[$cacheKey];
	}

	$reference = [
		'names' => [],
		'surah_order' => [],
		'pages' => [],
	];

	if (!is_file($csvFile)) {
		$cache[$cacheKey] = $reference;
		return $reference;
	}

	$handle = fopen($csvFile, 'r');
	if ($handle === false) {
		$cache[$cacheKey] = $reference;
		return $reference;
	}

	fgetcsv($handle);
	while (($row = fgetcsv($handle)) !== false) {
		$page = isset($row[0]) && is_numeric($row[0]) ? (int) $row[0] : 0;
		$startName = trim((string) ($row[1] ?? ''));
		$startAyah = isset($row[2]) && is_numeric($row[2]) ? (int) $row[2] : 0;
		$endName = trim((string) ($row[3] ?? ''));
		$endAyah = isset($row[4]) && is_numeric($row[4]) ? (int) $row[4] : 0;

		if ($page <= 0 || $startName === '' || $endName === '' || $startAyah <= 0 || $endAyah <= 0) {
			continue;
		}

		foreach ([$startName, $endName] as $surahName) {
			$key = bi_normalize_quran_key($surahName);
			if ($key === '') {
				continue;
			}

			$reference['names'][$key] = $surahName;
			if (!isset($reference['surah_order'][$key])) {
				$reference['surah_order'][$key] = count($reference['surah_order']) + 1;
			}
		}

		$startKey = bi_normalize_quran_key($startName);
		$endKey = bi_normalize_quran_key($endName);
		if ($startKey === '' || $endKey === '' || !isset($reference['surah_order'][$startKey], $reference['surah_order'][$endKey])) {
			continue;
		}

		$reference['pages'][] = [
			'page' => $page,
			'start_order' => $reference['surah_order'][$startKey],
			'start_ayah' => $startAyah,
			'end_order' => $reference['surah_order'][$endKey],
			'end_ayah' => $endAyah,
		];
	}

	fclose($handle);

	$cache[$cacheKey] = $reference;
	return $reference;
}

function bi_match_quran_surah_name(string $name, string $sourceDataset = ''): string
{
	$name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
	if ($name === '') {
		return '';
	}

	$reference = bi_load_quran_reference_data($sourceDataset);
	$key = bi_normalize_quran_key($name);
	if ($key === '' || empty($reference['names'])) {
		return $name;
	}

	if (isset($reference['names'][$key])) {
		return $reference['names'][$key];
	}

	$bestName = null;
	$bestDistance = null;
	foreach ($reference['names'] as $candidateKey => $candidateName) {
		$distance = levenshtein($key, $candidateKey);
		if ($bestDistance === null || $distance < $bestDistance) {
			$bestDistance = $distance;
			$bestName = $candidateName;
		}
	}

	$limit = max(1, (int) floor(strlen($key) * 0.25));
	return $bestName !== null && $bestDistance !== null && $bestDistance <= $limit ? $bestName : $name;
}

function bi_quran_point_compare(int $leftOrder, int $leftAyah, int $rightOrder, int $rightAyah): int
{
	if ($leftOrder === $rightOrder) {
		return $leftAyah <=> $rightAyah;
	}

	return $leftOrder <=> $rightOrder;
}

function bi_count_quran_pages_for_range(string $surahName, int $startAyah, int $endAyah, string $sourceDataset = ''): ?array
{
	if ($startAyah <= 0 || $endAyah <= 0) {
		return null;
	}

	if ($endAyah < $startAyah) {
		[$startAyah, $endAyah] = [$endAyah, $startAyah];
	}

	$reference = bi_load_quran_reference_data($sourceDataset);
	if (empty($reference['pages']) || empty($reference['surah_order'])) {
		return null;
	}

	$matchedName = bi_match_quran_surah_name($surahName, $sourceDataset);
	$surahKey = bi_normalize_quran_key($matchedName);
	if ($surahKey === '' || !isset($reference['surah_order'][$surahKey])) {
		return null;
	}

	$order = $reference['surah_order'][$surahKey];
	$pages = [];
	foreach ($reference['pages'] as $pageRange) {
		$startsBeforeEnd = bi_quran_point_compare($pageRange['start_order'], $pageRange['start_ayah'], $order, $endAyah) <= 0;
		$endsAfterStart = bi_quran_point_compare($order, $startAyah, $pageRange['end_order'], $pageRange['end_ayah']) <= 0;
		if ($startsBeforeEnd && $endsAfterStart) {
			$pages[$pageRange['page']] = true;
		}
	}

	return $pages;
}

function bi_count_tilawah_pages(mixed $value, array $rule): ?int
{
	if (!is_string($value) || trim($value) === '') {
		return 0;
	}

	$sourceDataset = is_string($rule['source_dataset'] ?? null) ? $rule['source_dataset'] : '';
	$segments = preg_split('/\s*,\s*/u', trim($value));
	if ($segments === false || $segments === []) {
		$segments = [trim($value)];
	}

	$pages = [];
	$hasAyahRange = false;
	foreach ($segments as $segment) {
		$segment = trim(preg_replace('/\s+/u', ' ', $segment) ?? $segment);
		$segment = preg_replace('/\s*[:：]\s*/u', ' ', $segment) ?? $segment;
		$segment = preg_replace('/\b(ayat|ayah)\b/iu', ' ', $segment) ?? $segment;
		$segment = trim(preg_replace('/\s+/u', ' ', $segment) ?? $segment);
		if ($segment === '') {
			continue;
		}

		if (!preg_match('/^(.+?)\s+(\d+)(?:\s*[-–—]\s*(\d+))?$/u', $segment, $match)) {
			continue;
		}

		$hasAyahRange = true;
		$rangePages = bi_count_quran_pages_for_range($match[1], (int) $match[2], isset($match[3]) && $match[3] !== '' ? (int) $match[3] : (int) $match[2], $sourceDataset);
		if ($rangePages === null) {
			continue;
		}

		foreach ($rangePages as $page => $_) {
			$pages[$page] = true;
		}
	}

	if ($hasAyahRange) {
		return count($pages);
	}

	$pagesPerSheet = isset($rule['pages_per_sheet']) && is_numeric($rule['pages_per_sheet']) ? max(1, (int) $rule['pages_per_sheet']) : 2;
	return $pagesPerSheet;
}

function bi_target_rule_keys(array $rule): array
{
	$rawKeys = $rule['data_keys'] ?? ($rule['keys'] ?? []);
	if (!is_array($rawKeys)) {
		return [];
	}

	$keys = [];
	foreach ($rawKeys as $key) {
		if (is_string($key) && trim($key) !== '') {
			$keys[] = trim($key);
		}
	}

	return $keys;
}

function bi_target_count(array $rule, float $default = 1): float
{
	$value = $rule['target_count'] ?? $default;
	return is_numeric($value) ? max(0.0, (float) $value) : $default;
}

function bi_target_required_units(array $rule): float
{
	$mode = strtolower((string) ($rule['mode'] ?? 'day_presence'));
	if ($mode === 'numeric') {
		return 1.0;
	}

	return max(1.0, bi_target_count($rule, 1.0));
}

function bi_target_required_units_for_period(array $rule, array $days, float $baseRequiredUnits, string $period): float
{
	$period = strtolower(trim($period));
	$mode = strtolower((string) ($rule['mode'] ?? 'day_presence'));

	if ($period === 'week' && ($mode === 'day_presence' || $mode === 'quran_sheet_count' || isset($rule['pages_per_sheet']))) {
		return max(1.0, min($baseRequiredUnits, (float) count($days)));
	}

	return $baseRequiredUnits;
}

function bi_target_periods(int $daysInMonth, string $period, string $selectedMonth = ''): array
{
	$period = strtolower(trim($period));
	if ($period === 'month') {
		return ['month' => range(1, $daysInMonth)];
	}

	$periods = [];
	for ($day = 1; $day <= $daysInMonth; $day++) {
		$key = (string) $day;
		if ($period === 'week' && preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
			$timestamp = strtotime($selectedMonth . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT));
			$key = $timestamp === false ? (string) ceil($day / 7) : date('o-W', $timestamp);
		}

		if (!isset($periods[$key])) {
			$periods[$key] = [];
		}
		$periods[$key][] = $day;
	}

	return $periods;
}

function bi_target_presence_units(array $monthData, array $rule, int $day): float
{
	$keys = bi_target_rule_keys($rule);
	if (empty($keys)) {
		return 0.0;
	}

	$logic = strtolower((string) ($rule['logic'] ?? 'all'));
	$matches = [];
	foreach ($keys as $key) {
		$matches[] = bi_is_filled_value($monthData[$key][$day] ?? null);
	}

	if ($logic === 'any') {
		return in_array(true, $matches, true) ? 1.0 : 0.0;
	}

	return !in_array(false, $matches, true) ? 1.0 : 0.0;
}

function bi_target_value_count_units(array $monthData, array $rule, int $day): float
{
	$keys = bi_target_rule_keys($rule);

	// match_value boleh berisi beberapa nilai dipisah koma (mis. "M,R-J"
	// untuk sholat berjamaah). Nilai cocok jika sama dengan salah satunya.
	$matchRaw = $rule['match_value'] ?? null;
	$matchValues = [];
	if (is_array($matchRaw)) {
		foreach ($matchRaw as $v) {
			if (is_scalar($v) && trim((string) $v) !== '') {
				$matchValues[] = trim((string) $v);
			}
		}
	} elseif (is_scalar($matchRaw)) {
		foreach (explode(',', (string) $matchRaw) as $v) {
			$v = trim($v);
			if ($v !== '') {
				$matchValues[] = $v;
			}
		}
	}
	if ($matchValues === []) {
		return 0.0;
	}

	$count = 0;
	foreach ($keys as $key) {
		$value = $monthData[$key][$day] ?? null;
		if (is_scalar($value) && in_array(trim((string) $value), $matchValues, true)) {
			$count++;
		}
	}

	return (float) $count;
}

function bi_target_filled_key_count_units(array $monthData, array $rule, int $day): float
{
	$count = 0;
	foreach (bi_target_rule_keys($rule) as $key) {
		if (bi_is_filled_value($monthData[$key][$day] ?? null)) {
			$count++;
		}
	}

	return (float) $count;
}

function bi_target_rawatib_units(array $monthData, array $rule, int $day): float
{
	$rakaatPerCheck = isset($rule['rakaat_per_check']) && is_numeric($rule['rakaat_per_check']) ? max(1.0, (float) $rule['rakaat_per_check']) : 2.0;
	$rawValue = $monthData['rawatib'][$day] ?? '';
	if (is_string($rawValue) && preg_match('/^(\d+)\s*\/\s*(\d+)$/', trim($rawValue), $match)) {
		return (float) ((int) $match[1]) * $rakaatPerCheck;
	}

	$done = 0;
	foreach ($monthData as $key => $values) {
		if (strpos((string) $key, 'rawatib_') !== 0 || !is_array($values)) {
			continue;
		}

		if (bi_is_filled_value($values[$day] ?? null)) {
			$done++;
		}
	}

	return (float) $done * $rakaatPerCheck;
}

function bi_target_numeric_units(array $monthData, array $rule, int $day): float
{
	$keys = bi_target_rule_keys($rule);
	$key = $keys[0] ?? '';
	if ($key === '') {
		return 0.0;
	}

	$value = $monthData[$key][$day] ?? null;
	$threshold = isset($rule['threshold']) && is_numeric($rule['threshold']) ? (float) $rule['threshold'] : bi_target_count($rule, 1.0);

	return is_numeric($value) && (float) $value >= $threshold ? 1.0 : 0.0;
}

function bi_target_quran_sheet_units(array $monthData, array $rule, int $day): float
{
	$keys = bi_target_rule_keys($rule);
	$key = $keys[0] ?? 'tilawah';
	$value = $monthData[$key][$day] ?? '';
	$pages = bi_count_tilawah_pages($value, $rule);
	if ($pages === null || $pages <= 0) {
		return 0.0;
	}

	$pagesPerSheet = isset($rule['pages_per_sheet']) && is_numeric($rule['pages_per_sheet']) ? max(1.0, (float) $rule['pages_per_sheet']) : 2.0;
	return $pages / $pagesPerSheet;
}

function bi_target_rule_units_for_day(array $monthData, array $rule, int $day): float
{
	$mode = strtolower((string) ($rule['mode'] ?? 'day_presence'));

	if ($mode === 'value_count') {
		return bi_target_value_count_units($monthData, $rule, $day);
	}

	if ($mode === 'filled_key_count') {
		return bi_target_filled_key_count_units($monthData, $rule, $day);
	}

	if ($mode === 'rawatib_count') {
		return bi_target_rawatib_units($monthData, $rule, $day);
	}

	if ($mode === 'numeric') {
		return bi_target_numeric_units($monthData, $rule, $day);
	}

	if ($mode === 'quran_sheet_count' || isset($rule['pages_per_sheet'])) {
		return bi_target_quran_sheet_units($monthData, $rule, $day);
	}

	return bi_target_presence_units($monthData, $rule, $day);
}

function bi_target_rule_matches_day(array $monthData, array $rule, int $day): bool
{
	return bi_target_rule_units_for_day($monthData, $rule, $day) >= bi_target_required_units($rule);
}

function bi_compute_target_rule_progress(array $monthData, int $daysInMonth, array $rule, string $selectedMonth = ''): array
{
	$requiredUnits = bi_target_required_units($rule);
	$period = is_string($rule['period'] ?? null) ? $rule['period'] : 'day';
	$periods = bi_target_periods($daysInMonth, $period, $selectedMonth);
	$done = 0.0;
	$doneRaw = 0.0;
	$possible = 0.0;
	$periodsDone = 0;

	foreach ($periods as $days) {
		$periodRequiredUnits = bi_target_required_units_for_period($rule, $days, $requiredUnits, $period);
		$periodUnits = 0.0;
		foreach ($days as $day) {
			$periodUnits += bi_target_rule_units_for_day($monthData, $rule, $day);
		}

		$doneRaw += $periodUnits;
		$done += min($periodUnits, $periodRequiredUnits);
		$possible += $periodRequiredUnits;
		if ($periodUnits >= $periodRequiredUnits) {
			$periodsDone++;
		}
	}

	return [
		'done' => $done,
		'done_raw' => $doneRaw,
		'possible' => $possible,
		'percent' => $possible > 0 ? round(($done / $possible) * 100, 1) : 0,
		'periods_done' => $periodsDone,
		'periods_possible' => count($periods),
	];
}

/**
 * Rincian capaian PER PERIODE (per hari / per minggu / per bulan).
 *
 * Dipakai kartu target di halaman penginputan mentee untuk menggambar grafik
 * batang: tiap batang punya "target" (digambar sebagai bayangan di belakang)
 * dan "done" (capaian nyata di depannya), sehingga besar target terlihat jelas.
 *
 * @return array<int, array{key:string,label:string,target:float,done:float,reached:bool,percent:float}>
 */
function bi_target_rule_period_breakdown(array $monthData, int $daysInMonth, array $rule, string $selectedMonth = ''): array
{
	$requiredUnits = bi_target_required_units($rule);
	$period = is_string($rule['period'] ?? null) ? $rule['period'] : 'day';
	$periods = bi_target_periods($daysInMonth, $period, $selectedMonth);
	$breakdown = [];

	foreach ($periods as $key => $days) {
		$target = bi_target_required_units_for_period($rule, $days, $requiredUnits, $period);
		$done = 0.0;
		foreach ($days as $day) {
			$done += bi_target_rule_units_for_day($monthData, $rule, $day);
		}

		$firstDay = $days[0] ?? 0;
		$lastDay = $days === [] ? $firstDay : $days[count($days) - 1];

		$breakdown[] = [
			'key' => is_string($key) ? $key : (string) $key,
			'label' => $firstDay === $lastDay ? (string) $firstDay : $firstDay . '-' . $lastDay,
			'target' => $target,
			'done' => $done,
			'reached' => $done >= $target,
			'percent' => $target > 0 ? round(min($done, $target) / $target * 100, 1) : 0,
		];
	}

	return $breakdown;
}

function bi_compute_target_summary(array $monthData, int $daysInMonth, array $targetRules, string $selectedMonth = ''): array
{
	$summary = [
		'rules_count' => 0,
		'done' => 0.0,
		'possible' => 0.0,
		'percent' => 0,
		'overall_percent' => 0,
		'best_rule' => null,
		'rules' => [],
	];

	foreach ($targetRules as $rule) {
		if (!is_array($rule)) {
			continue;
		}

		$summary['rules_count']++;
		$progress = bi_compute_target_rule_progress($monthData, $daysInMonth, $rule, $selectedMonth);
		$ruleDone = $progress['done'];
		$rulePossible = $progress['possible'];
		$rulePercent = $progress['percent'];
		$ruleLabel = is_string($rule['label'] ?? null) && trim($rule['label']) !== '' ? trim($rule['label']) : (string) ($rule['category'] ?? 'Target');

		$summary['rules'][] = [
			'category' => $rule['category'] ?? '',
			'label' => $ruleLabel,
			'percent' => $rulePercent,
			'done' => $ruleDone,
			'done_raw' => $progress['done_raw'] ?? $ruleDone,
			'possible' => $rulePossible,
			'periods_done' => $progress['periods_done'],
			'periods_possible' => $progress['periods_possible'],
		];
		$summary['done'] += $ruleDone;
		$summary['possible'] += $rulePossible;

		if ($summary['best_rule'] === null || $rulePercent > $summary['best_rule']['percent']) {
			$summary['best_rule'] = [
				'label' => $ruleLabel,
				'percent' => $rulePercent,
			];
		}
	}

	$summary['overall_percent'] = $summary['possible'] > 0 ? round(($summary['done'] / $summary['possible']) * 100, 1) : 0;
	$summary['percent'] = $summary['overall_percent'];

	return $summary;
}

function bi_compute_target_category_summary(array $rawParticipants, string $selectedMonth, int $daysInMonth, array $targetRules): array
{
	$summary = [];

	foreach ($targetRules as $rule) {
		if (!is_array($rule)) {
			continue;
		}

		$category = is_string($rule['category'] ?? null) && trim($rule['category']) !== '' ? trim($rule['category']) : 'Target';
		if (!isset($summary[$category])) {
			$summary[$category] = [
				'done' => 0,
				'possible' => 0,
				'percent' => 0,
			];
		}

		foreach ($rawParticipants as $participant) {
			$monthData = $participant['data'][$selectedMonth] ?? [];
			if (!is_array($monthData)) {
				$monthData = [];
			}

			$progress = bi_compute_target_rule_progress($monthData, $daysInMonth, $rule, $selectedMonth);
			$summary[$category]['done'] += $progress['done'];
			$summary[$category]['possible'] += $progress['possible'];
		}
	}

	foreach ($summary as $category => $stats) {
		$summary[$category]['percent'] = $stats['possible'] > 0 ? round(($stats['done'] / $stats['possible']) * 100, 1) : 0;
	}

	return $summary;
}

function bi_compute_target_rule_summaries(array $rawParticipants, string $selectedMonth, int $daysInMonth, array $targetRules): array
{
	$summaries = [];

	foreach ($targetRules as $index => $rule) {
		if (!is_array($rule)) {
			continue;
		}

		$ruleLabel = is_string($rule['label'] ?? null) && trim($rule['label']) !== '' ? trim($rule['label']) : (string) ($rule['category'] ?? 'Target');
		$summary = [
			'category' => $rule['category'] ?? '',
			'label' => $ruleLabel,
			'target' => $rule['target'] ?? '',
			'done' => 0.0,
			'possible' => 0.0,
			'percent' => 0,
			'periods_done' => 0,
			'periods_possible' => 0,
			'order' => $index,
		];

		foreach ($rawParticipants as $participant) {
			$monthData = $participant['data'][$selectedMonth] ?? [];
			if (!is_array($monthData)) {
				$monthData = [];
			}

			$progress = bi_compute_target_rule_progress($monthData, $daysInMonth, $rule, $selectedMonth);
			$summary['done'] += $progress['done'];
			$summary['possible'] += $progress['possible'];
			$summary['periods_done'] += $progress['periods_done'];
			$summary['periods_possible'] += $progress['periods_possible'];
		}

		$summary['percent'] = $summary['possible'] > 0 ? round(($summary['done'] / $summary['possible']) * 100, 1) : 0;
		$summaries[] = $summary;
	}

	return $summaries;
}

function bi_format_target_number(mixed $value): string
{
	if (!is_numeric($value)) {
		return '0';
	}

	$number = (float) $value;
	if (abs($number - round($number)) < 0.001) {
		return number_format((float) round($number), 0, ',', '.');
	}

	return number_format($number, 1, ',', '.');
}

function bi_load_target_settings_file(string $targetFile): array
{
	if (!is_file($targetFile)) {
		return [];
	}

	$targetSettings = bi_read_json($targetFile);
	return is_array($targetSettings) ? $targetSettings : [];
}

function bi_load_target_settings(string $analysisRoot): array
{
	return bi_load_target_settings_file($analysisRoot . DIRECTORY_SEPARATOR . 'target_settings.json');
}
