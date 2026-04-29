<?php
date_default_timezone_set('Asia/Jakarta');

if (!isset($analysisScope) || !is_string($analysisScope) || trim($analysisScope) === '') {
	$analysisScope = $_GET['scope'] ?? 'group';
}
$analysisScope = in_array($analysisScope, ['group', 'all'], true) ? $analysisScope : 'group';

if (!isset($analysisRoot) || !is_string($analysisRoot) || trim($analysisRoot) === '') {
	$callerFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
	$analysisRoot = $callerFile !== '' ? dirname($callerFile) : dirname(__DIR__);
}

$workspaceRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$analysisRoot = realpath($analysisRoot) ?: $analysisRoot;
$scanRoot = $analysisScope === 'all' ? $workspaceRoot : $analysisRoot;
$folder_name = $folder_name ?? basename($analysisRoot);
$namaPengguna = $namaPengguna ?? strtoupper(str_replace(['_', '-'], ' ', $folder_name));
$manifestPath = $manifestPath ?? '';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';
$requestPath = '/' . ltrim($requestPath, '/');
$requestPath = rtrim($requestPath, '/');
if ($requestPath === '') {
	$requestPath = '/';
}

$scriptBasePath = dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$scriptBasePath = str_replace('\\', '/', $scriptBasePath);
if ($scriptBasePath === '.' || $scriptBasePath === '') {
	$scriptBasePath = '/';
}
if ($scriptBasePath !== '/' && $scriptBasePath[0] !== '/') {
	$scriptBasePath = '/' . $scriptBasePath;
}
$scriptBasePath = rtrim($scriptBasePath, '/');
if ($scriptBasePath === '') {
	$scriptBasePath = '/';
}

$allowedPaths = $scriptBasePath === '/' ? ['/', '/index.php'] : [$scriptBasePath, $scriptBasePath . '/index.php'];
if (!in_array($requestPath, $allowedPaths, true)) {
	http_response_code(404);
	include __DIR__ . '/404.html';
	exit;
}

function bi_h(mixed $value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bi_month_label(mixed $month): string
{
	$bulan = [
		'01' => 'Januari',
		'02' => 'Februari',
		'03' => 'Maret',
		'04' => 'April',
		'05' => 'Mei',
		'06' => 'Juni',
		'07' => 'Juli',
		'08' => 'Agustus',
		'09' => 'September',
		'10' => 'Oktober',
		'11' => 'November',
		'12' => 'Desember',
	];

	if (!preg_match('/^(\d{4})-(\d{2})$/', (string) $month, $match)) {
		return (string) $month;
	}

	return ($bulan[$match[2]] ?? $match[2]) . ' ' . $match[1];
}

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

function bi_available_months(array $data): array
{
	$months = [];

	foreach ($data as $month => $monthData) {
		if (is_array($monthData) && preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
			$months[] = $month;
		}
	}

	$months = array_values(array_unique($months));
	rsort($months);

	return $months;
}

function bi_rawatib_detail_count(array $data): int
{
	$keys = [];

	foreach ($data as $monthData) {
		if (!is_array($monthData)) {
			continue;
		}

		foreach ($monthData as $key => $values) {
			if (strpos((string) $key, 'rawatib_') === 0) {
				$keys[$key] = true;
			}
		}
	}

	$count = count($keys);
	return $count > 0 ? $count : 5;
}

function bi_is_filled_value(mixed $value): bool
{
	if (is_string($value)) {
		return trim($value) !== '';
	}

	return !empty($value);
}

function bi_normalize_quran_key(string $value): string
{
	$value = strtolower(trim($value));
	$value = preg_replace('/^(surah|surat|qs)\s+/iu', '', $value) ?? $value;
	$value = str_replace(['’', '`', '´'], "'", $value);
	$value = preg_replace('/[^a-z0-9]+/', '', $value);

	return is_string($value) ? $value : '';
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

function bi_format_person_name(string $name): string
{
	return ucwords(str_replace(['_', '-'], ' ', $name));
}

function bi_format_group_name(string $name): string
{
	$normalised = str_replace(['_', '-'], ' ', $name);
	if (preg_match('/^[a-z0-9]+$/i', $normalised) && strlen($normalised) <= 4) {
		return strtoupper($normalised);
	}

	return ucwords($normalised);
}

function bi_participant_focus_key(array $participant): string
{
	return (string) ($participant['group'] ?? '') . '/' . (string) ($participant['folder'] ?? '');
}

function bi_resolve_selected_mentee_key(mixed $requestedMentee, array $participants, string $analysisScope): string
{
	if (!is_string($requestedMentee)) {
		return '';
	}

	$requestedMentee = trim($requestedMentee);
	if ($requestedMentee === '' || $requestedMentee === '__all') {
		return '';
	}

	foreach ($participants as $participant) {
		$focusKey = bi_participant_focus_key($participant);
		if ($requestedMentee === $focusKey) {
			return $focusKey;
		}

		if ($analysisScope === 'group' && $requestedMentee === (string) ($participant['folder'] ?? '')) {
			return $focusKey;
		}
	}

	return '';
}

function bi_find_focused_participant(array $participants, string $selectedMenteeKey): ?array
{
	if ($selectedMenteeKey === '') {
		return null;
	}

	foreach ($participants as $participant) {
		if (bi_participant_focus_key($participant) === $selectedMenteeKey) {
			return $participant;
		}
	}

	return null;
}

function bi_extract_redirect_target(string $indexFile): ?string
{
	if (!is_file($indexFile)) {
		return null;
	}

	$contents = file_get_contents($indexFile);
	if ($contents === false) {
		return null;
	}

	if (preg_match('/header\s*\(\s*["\']Location:\s*([^"\']+)["\']\s*\)/i', $contents, $matches)) {
		$target = trim($matches[1]);
		if ($target !== '') {
			return $target;
		}
	}

	if (preg_match('/header\s*\(\s*["\']Location:\s*["\']\s*\.\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*\)/i', $contents, $headerMatches)) {
		$variableName = $headerMatches[1];
		if (preg_match('/\$' . preg_quote($variableName, '/') . '\s*=\s*["\']([^"\']+)["\']\s*;/i', $contents, $variableMatches)) {
			$target = trim($variableMatches[1]);
			if ($target !== '') {
				return $target;
			}
		}
	}

	return null;
}

function bi_resolve_redirect_path(string $basePath, string $relativeTarget): ?string
{
	$relativeTarget = trim($relativeTarget);
	if ($relativeTarget === '' || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $relativeTarget)) {
		return null;
	}

	$relativeTarget = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeTarget), DIRECTORY_SEPARATOR);
	$resolvedPath = realpath($basePath . DIRECTORY_SEPARATOR . $relativeTarget);

	return $resolvedPath !== false && is_dir($resolvedPath) ? $resolvedPath : null;
}

function bi_resolve_participant_data_file(string $folderPath): array
{
	$directDataFile = $folderPath . DIRECTORY_SEPARATOR . 'data_amalan.json';
	if (is_file($directDataFile)) {
		return [$directDataFile, $folderPath];
	}

	$currentPath = $folderPath;
	for ($depth = 0; $depth < 5; $depth++) {
		$indexFile = $currentPath . DIRECTORY_SEPARATOR . 'index.php';
		if (!is_file($indexFile)) {
			break;
		}

		$redirectTarget = bi_extract_redirect_target($indexFile);
		if ($redirectTarget === null) {
			break;
		}

		$currentPath = bi_resolve_redirect_path($currentPath, $redirectTarget);
		if ($currentPath === null) {
			break;
		}

		$directDataFile = $currentPath . DIRECTORY_SEPARATOR . 'data_amalan.json';
		if (is_file($directDataFile)) {
			return [$directDataFile, $currentPath];
		}
	}

	return [null, null];
}

function bi_is_mentor_folder(string $folderPath, ?string $resolvedPath = null): bool
{
	$paths = [$folderPath];
	if (is_string($resolvedPath) && $resolvedPath !== '' && $resolvedPath !== $folderPath) {
		$paths[] = $resolvedPath;
	}

	foreach ($paths as $path) {
		if (is_file($path . DIRECTORY_SEPARATOR . '.THIS_IS_MENTOR')) {
			return true;
		}
	}

	return false;
}

function bi_build_participant_record(string $groupFolder, string $folder, array $data, string $analysisScope, bool $isMentor = false): array
{
	$groupLabel = bi_format_group_name($groupFolder);
	$folderLabel = bi_format_person_name($folder);
	$isGlobalScope = $analysisScope === 'all';

	return [
		'group' => $groupFolder,
		'group_label' => $groupLabel,
		'folder' => $folder,
		'label' => $folderLabel,
		'full_label' => $isGlobalScope ? $groupLabel . ' / ' . $folderLabel : $folderLabel,
		'mentor' => $isMentor,
		'url' => '/' . rawurlencode($groupFolder) . '/' . rawurlencode($folder) . '/',
		'data' => $data,
		'months' => bi_available_months($data),
		'rawatib_detail_count' => bi_rawatib_detail_count($data),
	];
}

function bi_parse_rawatib_day(array $monthData, int $day, int $rawatibDetailCount): array
{
	$rawValue = $monthData['rawatib'][$day] ?? '';
	$hasData = bi_is_filled_value($rawValue);

	if (is_string($rawValue) && preg_match('/^(\d+)\s*\/\s*(\d+)$/', trim($rawValue), $match)) {
		return [
			'done' => min((int) $match[1], $rawatibDetailCount),
			'possible' => $rawatibDetailCount,
			'has_data' => true,
		];
	}

	$done = 0;
	foreach ($monthData as $key => $values) {
		if (strpos((string) $key, 'rawatib_') !== 0 || !is_array($values)) {
			continue;
		}

		if (!array_key_exists($day, $values)) {
			continue;
		}

		$hasData = true;
		if ($values[$day] === '✓') {
			$done++;
		}
	}

	return [
		'done' => min($done, $rawatibDetailCount),
		'possible' => $rawatibDetailCount,
		'has_data' => $hasData,
	];
}

function bi_analyze_month(array $monthData, int $daysInMonth, array $daftar_amalan, int $rawatibDetailCount): array
{
	$categoryBreakdown = [];
	foreach ($daftar_amalan as $categoryName => $items) {
		$categoryBreakdown[$categoryName] = ['done' => 0, 'possible' => 0, 'percent' => 0];
	}

	$overallDone = 0;
	$overallPossible = 0;
	$rawatibDone = 0;
	$rawatibPossible = 0;
	$activeDays = 0;
	$istighfarTotal = 0;
	$istighfarDays = 0;

	for ($day = 1; $day <= $daysInMonth; $day++) {
		$dayHasData = false;

		foreach ($daftar_amalan as $categoryName => $items) {
			foreach ($items as $key => $label) {
				if ($key === 'rawatib') {
					$rawatib = bi_parse_rawatib_day($monthData, $day, $rawatibDetailCount);
					$done = $rawatib['done'];
					$possible = $rawatib['possible'];
					if ($rawatib['has_data']) {
						$dayHasData = true;
					}

					$rawatibDone += $done;
					$rawatibPossible += $possible;
				} else {
					$value = $monthData[$key][$day] ?? '';
					$possible = 1;
					if ($key === 'istighfar') {
						$done = (is_numeric($value) && (int) $value > 0) ? 1 : 0;
						if ($done) {
							$istighfarTotal += (int) $value;
							$istighfarDays++;
							$dayHasData = true;
						}
					} else {
						$done = bi_is_filled_value($value) ? 1 : 0;
						if ($done) {
							$dayHasData = true;
						}
					}
				}

				$overallDone += $done;
				$overallPossible += $possible;
				$categoryBreakdown[$categoryName]['done'] += $done;
				$categoryBreakdown[$categoryName]['possible'] += $possible;
			}
		}

		if ($dayHasData) {
			$activeDays++;
		}
	}

	foreach ($categoryBreakdown as $categoryName => $stats) {
		$categoryBreakdown[$categoryName]['percent'] = $stats['possible'] > 0 ? round(($stats['done'] / $stats['possible']) * 100, 1) : 0;
	}

	return [
		'overall_done' => $overallDone,
		'overall_possible' => $overallPossible,
		'overall_percent' => $overallPossible > 0 ? round(($overallDone / $overallPossible) * 100, 1) : 0,
		'rawatib_done' => $rawatibDone,
		'rawatib_possible' => $rawatibPossible,
		'rawatib_percent' => $rawatibPossible > 0 ? round(($rawatibDone / $rawatibPossible) * 100, 1) : 0,
		'active_days' => $activeDays,
		'istighfar_total' => $istighfarTotal,
		'istighfar_days' => $istighfarDays,
		'istighfar_average' => $istighfarDays > 0 ? round($istighfarTotal / $istighfarDays, 1) : 0,
		'category_breakdown' => $categoryBreakdown,
	];
}

function bi_scan_participants(string $analysisRoot, string $analysisScope = 'group'): array
{
	$participants = [];
	$skippedFolders = [];

	if (!is_dir($analysisRoot)) {
		return [$participants, $skippedFolders];
	}

	if ($analysisScope === 'all') {
		$groupIterator = new DirectoryIterator($analysisRoot);
		foreach ($groupIterator as $groupItem) {
			if ($groupItem->isDot() || !$groupItem->isDir()) {
				continue;
			}

			$groupFolder = $groupItem->getFilename();
			if ($groupFolder === 'test' || strpos($groupFolder, '.') === 0) {
				continue;
			}

			$groupPath = $groupItem->getPathname();
			$groupDataFile = $groupPath . DIRECTORY_SEPARATOR . 'data_amalan.json';
			$groupHasParticipant = false;

			$childIterator = new DirectoryIterator($groupPath);
			foreach ($childIterator as $childItem) {
				if ($childItem->isDot() || !$childItem->isDir()) {
					continue;
				}

				$folder = $childItem->getFilename();
				if (strpos($folder, '.') === 0) {
					continue;
				}

				list($dataFile, $resolvedPath) = bi_resolve_participant_data_file($childItem->getPathname());
				if ($dataFile === null) {
					$participants[] = bi_build_participant_record($groupFolder, $folder, [], $analysisScope, bi_is_mentor_folder($childItem->getPathname(), $resolvedPath));
					$groupHasParticipant = true;
					continue;
				}

				$data = bi_read_json($dataFile);
				$participants[] = bi_build_participant_record($groupFolder, $folder, $data, $analysisScope, bi_is_mentor_folder($childItem->getPathname(), $resolvedPath));
				$groupHasParticipant = true;
			}

			if (!$groupHasParticipant && is_file($groupDataFile)) {
				$data = bi_read_json($groupDataFile);
				$participants[] = bi_build_participant_record($groupFolder, $groupFolder, $data, $analysisScope, bi_is_mentor_folder($groupPath, $groupPath));
			}
		}

		return [$participants, $skippedFolders];
	}

	$groupFolder = basename($analysisRoot);
	$iterator = new DirectoryIterator($analysisRoot);
	foreach ($iterator as $item) {
		if ($item->isDot() || !$item->isDir()) {
			continue;
		}

		$folder = $item->getFilename();
		if (strpos($folder, '.') === 0) {
			continue;
		}

		list($dataFile, $resolvedPath) = bi_resolve_participant_data_file($item->getPathname());

		if ($dataFile === null) {
			$participants[] = bi_build_participant_record($groupFolder, $folder, [], $analysisScope, bi_is_mentor_folder($item->getPathname(), $resolvedPath));
			continue;
		}

		$data = bi_read_json($dataFile);
		$participants[] = bi_build_participant_record($groupFolder, $folder, $data, $analysisScope, bi_is_mentor_folder($item->getPathname(), $resolvedPath));
	}

	return [$participants, $skippedFolders];
}

function bi_resolve_month(mixed $requestedMonth, array $availableMonths): string
{
	$currentMonth = date('Y-m');

	if (is_string($requestedMonth) && preg_match('/^\d{4}-\d{2}$/', $requestedMonth)) {
		return $requestedMonth;
	}

	if (in_array($currentMonth, $availableMonths, true)) {
		return $currentMonth;
	}

	return $availableMonths[0] ?? $currentMonth;
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
	$matchValue = is_scalar($rule['match_value'] ?? null) ? trim((string) $rule['match_value']) : '';
	if ($matchValue === '') {
		return 0.0;
	}

	$count = 0;
	foreach ($keys as $key) {
		$value = $monthData[$key][$day] ?? null;
		if (is_scalar($value) && trim((string) $value) === $matchValue) {
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
	$possible = 0.0;
	$periodsDone = 0;

	foreach ($periods as $days) {
		$periodRequiredUnits = bi_target_required_units_for_period($rule, $days, $requiredUnits, $period);
		$periodUnits = 0.0;
		foreach ($days as $day) {
			$periodUnits += bi_target_rule_units_for_day($monthData, $rule, $day);
		}

		$done += min($periodUnits, $periodRequiredUnits);
		$possible += $periodRequiredUnits;
		if ($periodUnits >= $periodRequiredUnits) {
			$periodsDone++;
		}
	}

	return [
		'done' => $done,
		'possible' => $possible,
		'percent' => $possible > 0 ? round(($done / $possible) * 100, 1) : 0,
		'periods_done' => $periodsDone,
		'periods_possible' => count($periods),
	];
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

function bi_load_target_settings(string $analysisRoot): array
{
	$targetFile = $analysisRoot . DIRECTORY_SEPARATOR . 'target_settings.json';
	if (!is_file($targetFile)) {
		return [];
	}

	$targetSettings = bi_read_json($targetFile);
	return is_array($targetSettings) ? $targetSettings : [];
}

$daftar_amalan = [
	'SHOLAT WAJIB' => [
		'subuh' => 'Subuh', 'dzuhur' => 'Dzuhur', 'ashar' => 'Ashar', 'maghrib' => 'Maghrib', 'isya' => 'Isya'
	],
	'SHOLAT SUNNAH' => [
		'rawatib' => 'Rawatib', 'dhuha' => 'Dhuha', 'tahajud' => 'Tahajud'
	],
	'PUASA SUNNAH' => [
		'senin_kamis' => 'Senin/Kamis', 'ayamul_bidh' => 'Ayyamul Bidh'
	],
	'TILAWAH' => [
		'tilawah' => 'Tilawah Quran'
	],
	'SEDEKAH' => [
		'sedekah' => 'Sedekah'
	],
	'ALMATSURAT' => [
		'almatsurat_pagi' => 'Al-Matsurat Pagi', 'almatsurat_petang' => 'Al-Matsurat Petang'
	],
	'ISTIGHFAR' => [
		'istighfar' => 'Istighfar'
	]
];

$categoryColors = [
	'SHOLAT WAJIB' => '#7df0c5',
	'SHOLAT SUNNAH' => '#8bc5ff',
	'PUASA SUNNAH' => '#ffd27d',
	'TILAWAH' => '#a8f5b8',
	'SEDEKAH' => '#ff9f7d',
	'ALMATSURAT' => '#9ee7d8',
	'ISTIGHFAR' => '#f6d97a',
];

list($rawParticipants, $skippedFolders) = bi_scan_participants($scanRoot, $analysisScope);

$selectedMenteeKey = bi_resolve_selected_mentee_key($_GET['mentee'] ?? '', $rawParticipants, $analysisScope);
$focusedParticipant = bi_find_focused_participant($rawParticipants, $selectedMenteeKey);
$isMenteeFocus = $focusedParticipant !== null;
$analysisParticipants = $isMenteeFocus ? [$focusedParticipant] : $rawParticipants;

$availableMonths = [];
foreach ($analysisParticipants as $participant) {
	foreach ($participant['months'] as $month) {
		$availableMonths[] = $month;
	}
}

$availableMonths = array_values(array_unique($availableMonths));
rsort($availableMonths);

$selectedMonth = bi_resolve_month($_GET['month'] ?? '', $availableMonths);
if (!in_array($selectedMonth, $availableMonths, true)) {
	$availableMonths[] = $selectedMonth;
	rsort($availableMonths);
}

$daysInMonth = (int) date('t', strtotime($selectedMonth . '-01'));
$analysisLabel = $isMenteeFocus ? ($focusedParticipant['full_label'] ?? $focusedParticipant['label'] ?? 'MENTEE') : ($analysisScope === 'all' ? 'SEMUA KELOMPOK' : $namaPengguna);

$targetSettings = bi_load_target_settings($analysisRoot);
$targetRules = [];
if (isset($targetSettings['rules']) && is_array($targetSettings['rules'])) {
	foreach ($targetSettings['rules'] as $rule) {
		if (is_array($rule)) {
			$targetRules[] = $rule;
		}
	}
}
$hasTargetRules = !empty($targetRules);

$groupSummaries = [];
$groupPalette = ['#7df0c5', '#8bc5ff', '#ffd27d', '#ff9f7d', '#9ee7d8', '#f6d97a'];

$participants = [];
$categoryTotals = [];
foreach (array_keys($daftar_amalan) as $categoryName) {
	$categoryTotals[$categoryName] = ['done' => 0, 'possible' => 0, 'percent' => 0];
}

foreach ($analysisParticipants as $participant) {
	$monthData = $participant['data'][$selectedMonth] ?? [];
	if (!is_array($monthData)) {
		$monthData = [];
	}

	$metrics = bi_analyze_month($monthData, $daysInMonth, $daftar_amalan, $participant['rawatib_detail_count']);
	$targetMetrics = $hasTargetRules ? bi_compute_target_summary($monthData, $daysInMonth, $targetRules, $selectedMonth) : [];

	$participants[] = [
		'group' => $participant['group'],
		'group_label' => $participant['group_label'],
		'folder' => $participant['folder'],
		'label' => $participant['label'],
		'full_label' => $participant['full_label'],
		'mentor' => $participant['mentor'] ?? false,
		'url' => $participant['url'],
		'months_tracked' => count($participant['months']),
		'latest_month' => $participant['months'][0] ?? null,
		'metrics' => $metrics,
		'target_metrics' => $targetMetrics,
	];

	foreach ($metrics['category_breakdown'] as $categoryName => $stats) {
		$categoryTotals[$categoryName]['done'] += $stats['done'];
		$categoryTotals[$categoryName]['possible'] += $stats['possible'];
	}
}

$targetCategorySummary = $hasTargetRules ? bi_compute_target_category_summary($analysisParticipants, $selectedMonth, $daysInMonth, $targetRules) : [];
$targetRuleSummaries = $hasTargetRules ? bi_compute_target_rule_summaries($analysisParticipants, $selectedMonth, $daysInMonth, $targetRules) : [];

if ($analysisScope === 'all') {
	foreach ($participants as $participant) {
		$groupKey = $participant['group'] ?? 'unknown';
		$participantTargetPercent = $hasTargetRules ? (float) ($participant['target_metrics']['percent'] ?? 0) : (float) $participant['metrics']['overall_percent'];
		if (!isset($groupSummaries[$groupKey])) {
			$groupSummaries[$groupKey] = [
				'group' => $groupKey,
				'label' => $participant['group_label'] ?? bi_format_group_name($groupKey),
				'url' => '/' . rawurlencode($groupKey) . '/',
				'members' => 0,
				'score_sum' => 0,
				'target_score_sum' => 0,
				'active_sum' => 0,
				'top_participant' => null,
				'top_target_participant' => null,
				'average_percent' => 0,
				'target_average_percent' => 0,
				'average_active_days' => 0,
			];
		}

		$groupSummaries[$groupKey]['members']++;
		$groupSummaries[$groupKey]['score_sum'] += $participant['metrics']['overall_percent'];
		$groupSummaries[$groupKey]['target_score_sum'] += $participantTargetPercent;
		$groupSummaries[$groupKey]['active_sum'] += $participant['metrics']['active_days'];

		if ($groupSummaries[$groupKey]['top_participant'] === null || $participant['metrics']['overall_percent'] > $groupSummaries[$groupKey]['top_participant']['metrics']['overall_percent']) {
			$groupSummaries[$groupKey]['top_participant'] = $participant;
		}

		$currentTopTarget = $groupSummaries[$groupKey]['top_target_participant'];
		if ($currentTopTarget === null || $participantTargetPercent > (float) ($currentTopTarget['target_metrics']['percent'] ?? $currentTopTarget['metrics']['overall_percent'])) {
			$groupSummaries[$groupKey]['top_target_participant'] = $participant;
		}
	}

	$groupSummaries = array_values(array_map(function ($groupSummary) {
		$groupSummary['average_percent'] = $groupSummary['members'] > 0 ? round($groupSummary['score_sum'] / $groupSummary['members'], 1) : 0;
		$groupSummary['target_average_percent'] = $groupSummary['members'] > 0 ? round($groupSummary['target_score_sum'] / $groupSummary['members'], 1) : 0;
		$groupSummary['average_active_days'] = $groupSummary['members'] > 0 ? round($groupSummary['active_sum'] / $groupSummary['members'], 1) : 0;
		return $groupSummary;
	}, $groupSummaries));

	usort($groupSummaries, function ($a, $b) use ($hasTargetRules) {
		$scoreKey = $hasTargetRules ? 'target_average_percent' : 'average_percent';
		if ($a[$scoreKey] === $b[$scoreKey]) {
			if ($a['members'] === $b['members']) {
				return strcasecmp($a['label'], $b['label']);
			}

			return $b['members'] <=> $a['members'];
		}

		return $b[$scoreKey] <=> $a[$scoreKey];
	});
}

foreach ($categoryTotals as $categoryName => $stats) {
	$categoryTotals[$categoryName]['percent'] = $stats['possible'] > 0 ? round(($stats['done'] / $stats['possible']) * 100, 1) : 0;
}

usort($participants, function ($a, $b) use ($hasTargetRules) {
	$scoreA = $hasTargetRules ? (float) ($a['target_metrics']['percent'] ?? 0) : (float) $a['metrics']['overall_percent'];
	$scoreB = $hasTargetRules ? (float) ($b['target_metrics']['percent'] ?? 0) : (float) $b['metrics']['overall_percent'];

	if ($scoreA === $scoreB) {
		if ($a['metrics']['overall_percent'] === $b['metrics']['overall_percent']) {
			return $b['metrics']['active_days'] <=> $a['metrics']['active_days'];
		}

		return $b['metrics']['overall_percent'] <=> $a['metrics']['overall_percent'];
	}

	return $scoreB <=> $scoreA;
});

$topParticipant = $participants[0] ?? null;
$mostActiveParticipant = null;
foreach ($participants as $participant) {
	if ($mostActiveParticipant === null || $participant['metrics']['active_days'] > $mostActiveParticipant['metrics']['active_days']) {
		$mostActiveParticipant = $participant;
	}
}

$scoreSum = 0;
$targetScoreSum = 0;
foreach ($participants as $participant) {
	$scoreSum += $participant['metrics']['overall_percent'];
	if ($hasTargetRules) {
		$targetScoreSum += (float) ($participant['target_metrics']['percent'] ?? 0);
	}
}

$averageScore = !empty($participants) ? round($scoreSum / count($participants), 1) : 0;
$averageTargetScore = $hasTargetRules && !empty($participants) ? round($targetScoreSum / count($participants), 1) : 0;
$displayAverageScore = $hasTargetRules ? $averageTargetScore : $averageScore;
$topDisplayScore = $hasTargetRules ? (float) ($topParticipant['target_metrics']['percent'] ?? 0) : (float) ($topParticipant['metrics']['overall_percent'] ?? 0);

$bestTargetRule = null;
$weakestTargetRule = null;
foreach ($targetRuleSummaries as $ruleSummary) {
	if ($bestTargetRule === null || $ruleSummary['percent'] > $bestTargetRule['percent']) {
		$bestTargetRule = $ruleSummary;
	}

	if ($weakestTargetRule === null || $ruleSummary['percent'] < $weakestTargetRule['percent']) {
		$weakestTargetRule = $ruleSummary;
	}
}

/*
 * Keep the generic category insight available as a fallback when a group does
 * not have target_settings.json yet.
 */
$topGroupSummary = $groupSummaries[0] ?? null;
$groupCount = count($groupSummaries);
$skippedCount = count($skippedFolders);
$analysisModeLabel = $analysisScope === 'all' ? 'Lintas Grup' : 'Kelompok';
$analysisContextLabel = $analysisScope === 'all' ? 'seluruh kelompok di workspace ini' : basename($analysisRoot);
$resetFocusParams = ['month' => $selectedMonth];
if ($analysisScope === 'all') {
	$resetFocusParams['scope'] = 'all';
}
$resetFocusUrl = '?' . http_build_query($resetFocusParams);
$focusedInputUrl = $isMenteeFocus ? (string) ($focusedParticipant['url'] ?? '') : '';
$bestCategory = '';
$bestCategoryPercent = 0;
foreach ($categoryTotals as $categoryName => $stats) {
	if ($stats['percent'] > $bestCategoryPercent) {
		$bestCategoryPercent = $stats['percent'];
		$bestCategory = $categoryName;
	}
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>BI Amalan - <?= bi_h($analysisLabel) ?></title>
	<?php if (!empty($manifestPath)) : ?>
		<link rel="manifest" href="<?= bi_h($manifestPath) ?>">
	<?php endif; ?>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Roboto+Flex:opsz,wght@8..144,300;8..144,400;8..144,500;8..144,600;8..144,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
	<style>
		* { box-sizing: border-box; }
		:root {
			--bg: #061612;
			--bg2: #091f26;
			--surface: rgba(11, 31, 27, 0.86);
			--surface-strong: rgba(7, 21, 18, 0.96);
			--line: rgba(158, 243, 208, 0.14);
			--line-strong: rgba(158, 243, 208, 0.22);
			--text: #eefbf5;
			--muted: #97b2a8;
			--accent: #7df0c5;
			--accent2: #ffd27d;
			--shadow: 0 24px 80px rgba(0, 0, 0, 0.34);
		}

		html { scroll-behavior: smooth; }
		body {
			margin: 0;
			min-height: 100vh;
			font-family: 'Epilogue', sans-serif;
			color: var(--text);
			background:
				radial-gradient(circle at top left, rgba(125, 240, 197, 0.16), transparent 26%),
				radial-gradient(circle at 82% 8%, rgba(139, 197, 255, 0.14), transparent 22%),
				linear-gradient(180deg, var(--bg) 0%, var(--bg2) 100%);
			overflow-x: hidden;
		}

		body::before {
			content: '';
			position: fixed;
			inset: 0;
			pointer-events: none;
			background-image: linear-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px);
			background-size: 84px 84px;
			opacity: 0.12;
			mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.84), transparent 96%);
		}

		a { color: inherit; text-decoration: none; }

		.shell {
			position: relative;
			z-index: 1;
			max-width: 1480px;
			margin: 0 auto;
			padding: 28px 22px 56px;
		}

		.hero,
		.panel,
		.summary-card,
		.insight-card,
		.mini-card {
			border: 1px solid var(--line);
			background: linear-gradient(180deg, rgba(11, 31, 27, 0.96), rgba(7, 21, 18, 0.82));
			box-shadow: var(--shadow);
			backdrop-filter: blur(18px);
		}

		.hero {
			display: grid;
			grid-template-columns: minmax(0, 1.45fr) minmax(300px, 0.85fr);
			gap: 16px;
			border-radius: 28px;
			padding: 26px;
			margin-bottom: 18px;
		}

		.kicker,
		.section-label,
		.summary-label,
		.insight-label,
		.mini-label,
		.filter-label {
			text-transform: uppercase;
			letter-spacing: 0.18em;
			font-size: 0.75rem;
			color: var(--muted);
		}

		h1, h2, .summary-value, .insight-value, .mini-value, .score-value {
			font-family: 'Space Grotesk', sans-serif;
		}

		h1 {
			margin: 12px 0 10px;
			font-size: clamp(2.3rem, 4vw, 4.6rem);
			line-height: 0.98;
			letter-spacing: -0.05em;
		}

		.subtitle {
			margin: 0;
			max-width: 70ch;
			color: var(--muted);
			line-height: 1.75;
			font-size: 1rem;
		}

		.meta {
			display: flex;
			flex-wrap: wrap;
			gap: 10px;
			margin-top: 20px;
		}

		.pill,
		.tag {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 9px 14px;
			border-radius: 999px;
			border: 1px solid var(--line-strong);
			background: rgba(255, 255, 255, 0.04);
			color: var(--text);
			font-size: 0.92rem;
		}

		.filter-card {
			border-radius: 24px;
			padding: 22px;
			display: flex;
			flex-direction: column;
			gap: 14px;
			justify-content: space-between;
		}

		.filter-stack,
		.filter-actions {
			display: grid;
			gap: 10px;
		}

		.filter-card p,
		.summary-note,
		.insight-note,
		.footer-note,
		.panel-head p,
		.empty-state {
			color: var(--muted);
			line-height: 1.7;
		}

		.select,
		.button {
			width: 100%;
			border: 1px solid var(--line-strong);
			border-radius: 16px;
			font: inherit;
		}

		.select {
			padding: 14px 16px;
			color: var(--text);
			background: rgba(255, 255, 255, 0.06);
			outline: none;
		}

		.button {
			cursor: pointer;
			padding: 14px 16px;
			border: 0;
			color: #041611;
			font-weight: 800;
			background: linear-gradient(135deg, var(--accent), var(--accent2));
			text-align: center;
		}

		.button.secondary {
			border: 1px solid var(--line-strong);
			color: var(--text);
			background: rgba(255, 255, 255, 0.05);
		}

		.button.input-shortcut {
			background: linear-gradient(135deg, #8bc5ff, var(--accent));
		}

		.stats-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
			gap: 16px;
			margin: 18px 0 22px;
		}

		.summary-card {
			border-radius: 22px;
			padding: 20px;
			min-height: 132px;
		}

		.summary-value {
			margin-top: 12px;
			font-size: clamp(1.8rem, 3vw, 2.8rem);
			line-height: 1;
		}

		.summary-note {
			margin-top: 8px;
		}

		.grid-two {
			display: grid;
			grid-template-columns: minmax(0, 1.32fr) minmax(300px, 0.68fr);
			gap: 16px;
			align-items: start;
		}

		.panel {
			border-radius: 24px;
			padding: 22px;
		}

		.panel-head {
			display: flex;
			justify-content: space-between;
			gap: 16px;
			align-items: flex-start;
			margin-bottom: 16px;
		}

		.panel-head h2 {
			margin: 0;
			font-size: 1.55rem;
			letter-spacing: -0.03em;
		}

		.panel-head p {
			margin: 8px 0 0;
		}

		.table-wrap {
			overflow: auto;
			border-radius: 18px;
			border: 1px solid var(--line);
			background: rgba(255, 255, 255, 0.02);
		}

		table {
			width: 100%;
			min-width: 1080px;
			border-collapse: separate;
			border-spacing: 0;
		}

		th, td {
			padding: 14px 16px;
			text-align: left;
			vertical-align: middle;
		}

		th {
			position: sticky;
			top: 0;
			background: rgba(9, 28, 23, 0.96);
			color: var(--muted);
			font-size: 0.72rem;
			text-transform: uppercase;
			letter-spacing: 0.14em;
			border-bottom: 1px solid var(--line);
		}

		tbody tr + tr td {
			border-top: 1px solid rgba(255, 255, 255, 0.05);
		}

		tbody tr:hover {
			background: rgba(255, 255, 255, 0.03);
		}

		.rank {
			color: var(--accent);
			font-weight: 700;
		}

		.participant-link {
			display: flex;
			flex-direction: column;
			gap: 4px;
		}

		.participant-link strong {
			font-family: 'Space Grotesk', sans-serif;
			font-size: 1rem;
			display: flex;
			align-items: center;
			gap: 8px;
			flex-wrap: wrap;
		}

		.participant-link small,
		.muted {
			color: var(--muted);
		}

		.mentor-chip {
			display: inline-flex;
			align-items: center;
			padding: 4px 9px;
			border-radius: 999px;
			border: 1px solid rgba(255, 210, 125, 0.35);
			background: rgba(255, 210, 125, 0.12);
			color: var(--accent2);
			font-size: 0.64rem;
			font-weight: 700;
			letter-spacing: 0.16em;
			text-transform: uppercase;
			white-space: nowrap;
		}

		.score-box {
			min-width: 170px;
		}

		.score-top {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			gap: 10px;
			margin-bottom: 8px;
		}

		.score-value {
			font-size: 1.1rem;
		}

		.bar,
		.mini-bar {
			position: relative;
			height: 9px;
			border-radius: 999px;
			overflow: hidden;
			background: rgba(255, 255, 255, 0.08);
		}

		.bar .target-marker,
		.mini-bar .target-marker {
			position: absolute;
			top: -4px;
			bottom: -4px;
			left: var(--target-left, 0%);
			width: 6px;
			transform: translateX(-50%);
			border-radius: 999px;
			background: linear-gradient(180deg, #fff8d6 0%, #ffbe3d 48%, #ef6c00 100%);
			border: 1px solid rgba(255, 255, 255, 0.92);
			box-shadow: 0 0 0 1px rgba(121, 63, 10, 0.85), 0 0 0 3px rgba(255, 255, 255, 0.3), 0 0 14px rgba(255, 176, 0, 0.95);
			opacity: 0.98;
			pointer-events: none;
			z-index: 2;
		}

		.bar .target-marker::after,
		.mini-bar .target-marker::after {
			content: '';
			position: absolute;
			inset: 1px;
			border-radius: inherit;
			border: 1px solid rgba(255, 255, 255, 0.28);
		}

		.chart-legend {
			display: flex;
			flex-wrap: wrap;
			gap: 10px;
			margin-top: 12px;
		}

		.legend-item {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 7px 11px;
			border-radius: 999px;
			border: 1px solid var(--line-strong);
			background: rgba(255, 255, 255, 0.04);
			color: var(--muted);
			font-size: 0.72rem;
			letter-spacing: 0.14em;
			text-transform: uppercase;
			white-space: nowrap;
		}

		.legend-swatch {
			flex: none;
			display: inline-block;
			position: relative;
		}

		.legend-swatch--fill {
			width: 22px;
			height: 8px;
			border-radius: 999px;
			background: linear-gradient(90deg, var(--accent), var(--accent2));
			box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.16);
		}

		.legend-swatch--target {
			width: 8px;
			height: 18px;
			border-radius: 999px;
			background: linear-gradient(180deg, #fff8d6 0%, #ffbe3d 48%, #ef6c00 100%);
			border: 1px solid rgba(255, 255, 255, 0.9);
			box-shadow: 0 0 0 1px rgba(121, 63, 10, 0.85), 0 0 10px rgba(255, 176, 0, 0.8);
		}

		.legend-swatch--target::after {
			content: '';
			position: absolute;
			inset: 1px 2px;
			border-radius: inherit;
			border: 1px solid rgba(255, 255, 255, 0.3);
		}

		.bar span,
		.mini-bar span {
			display: block;
			height: 100%;
			border-radius: inherit;
			background: linear-gradient(90deg, var(--accent), var(--accent2));
			position: relative;
			z-index: 1;
		}

		.insight-list,
		.mini-grid {
			display: grid;
			gap: 14px;
		}

		.insight-card {
			border-radius: 20px;
			padding: 18px;
		}

		.insight-value,
		.mini-value {
			margin-top: 10px;
			font-size: 1.6rem;
			line-height: 1.1;
			letter-spacing: -0.03em;
		}

		.mini-grid {
			grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		}

		.mini-card {
			position: relative;
			overflow: hidden;
			border-radius: 20px;
			padding: 18px;
			min-height: 156px;
		}

		.mini-card::before {
			content: '';
			position: absolute;
			inset: auto -26px -44px auto;
			width: 180px;
			height: 180px;
			border-radius: 50%;
			background: radial-gradient(circle, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0) 70%);
			box-shadow: 0 0 0 1px var(--accent) inset;
			opacity: 0.25;
			pointer-events: none;
		}

		.mini-sub {
			margin-top: 6px;
			color: var(--muted);
			line-height: 1.6;
		}

		.mini-bar {
			margin-top: 16px;
			position: relative;
			z-index: 1;
		}

		.empty-state {
			padding: 26px;
			border-radius: 18px;
			border: 1px dashed var(--line-strong);
			background: rgba(255, 255, 255, 0.03);
		}

		.footer-note {
			margin-top: 16px;
		}

		@media (max-width: 1180px) {
			.hero,
			.grid-two {
				grid-template-columns: 1fr;
			}

			.stats-grid {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}
		}

		@media (max-width: 720px) {
			.shell {
				padding: 18px 14px 48px;
			}

			.hero,
			.panel,
			.summary-card,
			.filter-card {
				border-radius: 22px;
				padding: 18px;
			}

			.stats-grid {
				grid-template-columns: 1fr;
			}

			.panel-head {
				flex-direction: column;
			}

			h1 {
				font-size: 2.35rem;
			}
		}
	</style>
	<style>
		.material-symbols-rounded {
			font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
		}

		:root {
			--md-sys-color-primary: #006A6A;
			--md-sys-color-on-primary: #FFFFFF;
			--md-sys-color-primary-container: #6FF7F7;
			--md-sys-color-on-primary-container: #002020;
			--md-sys-color-secondary: #4A6363;
			--md-sys-color-on-secondary: #FFFFFF;
			--md-sys-color-secondary-container: #CCE8E7;
			--md-sys-color-on-secondary-container: #051F1F;
			--md-sys-color-tertiary: #4B607C;
			--md-sys-color-on-tertiary: #FFFFFF;
			--md-sys-color-tertiary-container: #D3E4FF;
			--md-sys-color-on-tertiary-container: #041C35;
			--md-sys-color-error: #BA1A1A;
			--md-sys-color-on-error: #FFFFFF;
			--md-sys-color-error-container: #FFDAD6;
			--md-sys-color-on-error-container: #410002;
			--md-sys-color-surface: #FAFDFC;
			--md-sys-color-on-surface: #191C1C;
			--md-sys-color-surface-variant: #DAE5E3;
			--md-sys-color-on-surface-variant: #3F4948;
			--md-sys-color-outline: #6F7978;
			--md-sys-color-outline-variant: #BEC9C7;
			--md-sys-color-background: #F4FBF9;
			--md-sys-color-on-background: #191C1C;
			--md-sys-color-surface-container-lowest: #FFFFFF;
			--md-sys-color-surface-container-low: #F0F7F6;
			--md-sys-color-surface-container: #EAF1F0;
			--md-sys-color-surface-container-high: #E4EBEA;
			--md-sys-color-surface-container-highest: #DEE5E4;
			--md-sys-elevation-level0: none;
			--md-sys-elevation-level1: 0px 1px 2px 0px rgba(0, 0, 0, 0.3), 0px 1px 3px 1px rgba(0, 0, 0, 0.15);
			--md-sys-elevation-level2: 0px 1px 2px 0px rgba(0, 0, 0, 0.3), 0px 2px 6px 2px rgba(0, 0, 0, 0.15);
			--md-sys-elevation-level3: 0px 4px 8px 3px rgba(0, 0, 0, 0.15), 0px 1px 3px 0px rgba(0, 0, 0, 0.3);
			--md-sys-elevation-level4: 0px 6px 10px 4px rgba(0, 0, 0, 0.15), 0px 2px 3px 0px rgba(0, 0, 0, 0.3);
			--md-sys-elevation-level5: 0px 8px 12px 6px rgba(0, 0, 0, 0.15), 0px 4px 4px 0px rgba(0, 0, 0, 0.3);
			--md-sys-shape-corner-none: 0px;
			--md-sys-shape-corner-extra-small: 4px;
			--md-sys-shape-corner-small: 8px;
			--md-sys-shape-corner-medium: 12px;
			--md-sys-shape-corner-large: 16px;
			--md-sys-shape-corner-extra-large: 28px;
			--md-sys-shape-corner-full: 9999px;
			--font-family: 'Roboto Flex', -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
			--color-primary: var(--md-sys-color-primary);
			--color-primary-dark: #004D4D;
			--color-secondary: var(--md-sys-color-secondary-container);
			--color-background: var(--md-sys-color-background);
			--color-surface: var(--md-sys-color-surface);
			--color-text: var(--md-sys-color-on-surface);
			--color-text-light: var(--md-sys-color-on-surface-variant);
			--color-border: var(--md-sys-color-outline-variant);
			--color-good: var(--md-sys-color-primary-container);
			--color-good-text: var(--md-sys-color-on-primary-container);
			--color-ok-2: var(--md-sys-color-tertiary-container);
			--color-ok-2-text: var(--md-sys-color-on-tertiary-container);
			--color-ok-1: var(--md-sys-color-secondary-container);
			--color-ok-1-text: var(--md-sys-color-on-secondary-container);
			--color-qadha: var(--md-sys-color-error-container);
			--color-qadha-text: var(--md-sys-color-error);
			--color-empty: var(--md-sys-color-surface-container);
			--color-empty-text: var(--md-sys-color-on-surface-variant);
			--bg: var(--md-sys-color-background);
			--bg2: var(--md-sys-color-surface-container-low);
			--surface: var(--md-sys-color-surface);
			--surface-strong: var(--md-sys-color-surface-container-low);
			--line: var(--md-sys-color-outline-variant);
			--line-strong: var(--md-sys-color-outline);
			--text: var(--md-sys-color-on-surface);
			--muted: var(--md-sys-color-on-surface-variant);
			--accent: var(--md-sys-color-primary);
			--accent2: var(--md-sys-color-tertiary);
			--shadow: var(--md-sys-elevation-level2);
		}

		html { scroll-behavior: smooth; }
		body {
			font-family: var(--font-family);
			margin: 0;
			background-color: var(--color-background);
			color: var(--color-text);
			line-height: 1.6;
			padding-bottom: 120px;
			position: relative;
			overflow-x: hidden;
		}

		body::before {
			content: '';
			position: fixed;
			inset: 0;
			z-index: -1;
			pointer-events: none;
			opacity: 1;
			background-image:
				radial-gradient(circle at top left, rgba(0, 106, 106, 0.08), transparent 28%),
				radial-gradient(circle at 82% 8%, rgba(75, 96, 124, 0.08), transparent 22%),
				linear-gradient(rgba(0, 0, 0, 0.03) 1px, transparent 1px),
				linear-gradient(90deg, rgba(0, 0, 0, 0.03) 1px, transparent 1px);
			background-size: auto, auto, 84px 84px, 84px 84px;
			mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.85), transparent 96%);
		}

		a { color: inherit; text-decoration: none; }

		.shell {
			position: relative;
			z-index: 1;
			max-width: 1480px;
			margin: 30px auto 0;
			padding: 20px 32px 56px;
		}

		.hero,
		.panel,
		.summary-card,
		.insight-card,
		.mini-card {
			border: 1px solid var(--color-border);
			background: linear-gradient(180deg, var(--md-sys-color-surface-container-lowest) 0%, var(--md-sys-color-surface) 100%);
			box-shadow: var(--md-sys-elevation-level1);
			backdrop-filter: none;
		}

		.hero {
			display: grid;
			grid-template-columns: minmax(0, 1.45fr) minmax(300px, 0.85fr);
			gap: 16px;
			border-radius: var(--md-sys-shape-corner-extra-large);
			padding: 28px;
			margin-bottom: 18px;
		}

		.kicker,
		.section-label,
		.summary-label,
		.insight-label,
		.mini-label,
		.filter-label {
			text-transform: uppercase;
			letter-spacing: 0.18em;
			font-size: 0.75rem;
			color: var(--md-sys-color-primary);
		}

		h1, h2, .summary-value, .insight-value, .mini-value, .score-value {
			font-family: var(--font-family);
		}

		h1 {
			margin: 12px 0 10px;
			font-size: clamp(2.3rem, 4vw, 4.6rem);
			line-height: 0.98;
			letter-spacing: -0.05em;
			color: var(--md-sys-color-on-background);
		}

		.subtitle {
			margin: 0;
			max-width: 70ch;
			color: var(--color-text-light);
			line-height: 1.75;
			font-size: 1rem;
		}

		.meta {
			display: flex;
			flex-wrap: wrap;
			gap: 10px;
			margin-top: 20px;
		}

		.pill,
		.tag {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 8px 14px;
			border-radius: var(--md-sys-shape-corner-full);
			border: 1px solid #B0D0CE;
			background: linear-gradient(180deg, #D6EFED 0%, #CCE8E7 100%);
			color: var(--md-sys-color-on-secondary-container);
			font-size: 0.92rem;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.5);
		}

		.filter-card {
			border-radius: var(--md-sys-shape-corner-large);
			padding: 22px;
			display: flex;
			flex-direction: column;
			gap: 14px;
			justify-content: space-between;
			background: var(--md-sys-color-surface-container-low);
			border: 1px solid var(--color-border);
			box-shadow: var(--md-sys-elevation-level1);
		}

		.filter-stack,
		.filter-actions {
			display: grid;
			gap: 10px;
		}

		.filter-card p,
		.summary-note,
		.insight-note,
		.footer-note,
		.panel-head p,
		.empty-state {
			color: var(--color-text-light);
			line-height: 1.7;
		}

		.select,
		.button {
			width: 100%;
			border: 1px solid #bbb;
			border-radius: 6px;
			font: inherit;
		}

		.select {
			padding: 12px 16px;
			color: var(--md-sys-color-on-surface);
			background: #FFFFFF;
			outline: none;
			box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
		}

		.select:hover {
			border-color: #999;
		}

		.select:focus {
			border: 2px solid var(--md-sys-color-primary);
			box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05), 0 0 0 3px rgba(0, 106, 106, 0.1);
			outline: none;
		}

		.button {
			cursor: pointer;
			padding: 12px 16px;
			border: 1px solid #004D4D;
			color: #FFFFFF;
			font-weight: 700;
			background: var(--md-sys-color-primary);
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.2);
			text-align: center;
		}

		.button:hover {
			background: #008585;
		}

		.button.secondary {
			border-color: var(--md-sys-color-outline-variant);
			color: var(--md-sys-color-on-secondary-container);
			background: var(--md-sys-color-secondary-container);
		}

		.button.input-shortcut {
			border-color: var(--md-sys-color-tertiary);
			background: var(--md-sys-color-tertiary);
		}

		.stats-grid {
			display: grid;
			grid-template-columns: repeat(4, minmax(0, 1fr));
			gap: 16px;
			margin: 18px 0 22px;
		}

		.summary-card {
			border-radius: var(--md-sys-shape-corner-large);
			padding: 20px;
			min-height: 132px;
			background: linear-gradient(180deg, var(--md-sys-color-surface-container-lowest) 0%, var(--md-sys-color-surface-container-low) 100%);
		}

		.summary-value {
			margin-top: 12px;
			font-size: clamp(1.8rem, 3vw, 2.8rem);
			line-height: 1;
		}

		.summary-note {
			margin-top: 8px;
		}

		.grid-two {
			display: grid;
			grid-template-columns: minmax(0, 1.32fr) minmax(300px, 0.68fr);
			gap: 16px;
			align-items: start;
		}

		.panel {
			border-radius: var(--md-sys-shape-corner-large);
			padding: 22px;
			background: var(--md-sys-color-surface-container-lowest);
		}

		.panel-head {
			display: flex;
			justify-content: space-between;
			gap: 16px;
			align-items: flex-start;
			margin-bottom: 16px;
		}

		.panel-head h2 {
			margin: 0;
			font-size: 1.55rem;
			letter-spacing: -0.03em;
			color: var(--md-sys-color-on-surface);
		}

		.panel-head p {
			margin: 8px 0 0;
		}

		.table-wrap {
			overflow: auto;
			border-radius: var(--md-sys-shape-corner-large);
			border: 1px solid var(--color-border);
			background: var(--md-sys-color-surface-container-low);
		}

		table {
			width: 100%;
			min-width: 1080px;
			border-collapse: separate;
			border-spacing: 0;
		}

		th, td {
			padding: 14px 16px;
			text-align: left;
			vertical-align: middle;
		}

		th {
			position: sticky;
			top: 0;
			background: var(--md-sys-color-surface-container-high);
			color: var(--md-sys-color-on-surface-variant);
			font-size: 0.72rem;
			text-transform: uppercase;
			letter-spacing: 0.14em;
			border-bottom: 1px solid var(--color-border);
		}

		tbody tr + tr td {
			border-top: 1px solid rgba(0, 0, 0, 0.05);
		}

		tbody tr:hover {
			background: var(--md-sys-color-surface-container-highest);
		}

		.rank {
			color: var(--md-sys-color-primary);
			font-weight: 700;
		}

		.participant-link {
			display: flex;
			flex-direction: column;
			gap: 4px;
		}

		.participant-link strong {
			font-family: var(--font-family);
			font-size: 1rem;
			font-weight: 600;
			display: flex;
			align-items: center;
			gap: 8px;
			flex-wrap: wrap;
		}

		.participant-link small,
		.muted {
			color: var(--color-text-light);
		}

		.mentor-chip {
			display: inline-flex;
			align-items: center;
			padding: 4px 9px;
			border-radius: 6px;
			border: 1px solid #B0D0CE;
			background: linear-gradient(180deg, #D6EFED 0%, #CCE8E7 100%);
			color: var(--md-sys-color-on-secondary-container);
			font-size: 0.64rem;
			font-weight: 700;
			letter-spacing: 0.16em;
			text-transform: uppercase;
			white-space: nowrap;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.5);
		}

		.score-box {
			min-width: 170px;
		}

		.score-top {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			gap: 10px;
			margin-bottom: 8px;
		}

		.score-value {
			font-size: 1.1rem;
		}

		.score-sub {
			margin-top: 6px;
			color: var(--color-text-light);
			font-size: 0.82rem;
			line-height: 1.45;
		}

		.bar,
		.mini-bar {
			position: relative;
			height: 9px;
			border-radius: 999px;
			overflow: hidden;
			background: var(--md-sys-color-surface-container-highest);
		}

		.bar .target-marker,
		.mini-bar .target-marker {
			position: absolute;
			top: -4px;
			bottom: -4px;
			left: var(--target-left, 0%);
			width: 6px;
			transform: translateX(-50%);
			border-radius: var(--md-sys-shape-corner-full);
			background: linear-gradient(180deg, #fff8d6 0%, #ffbe3d 48%, #ef6c00 100%);
			border: 1px solid rgba(255, 255, 255, 0.92);
			box-shadow: 0 0 0 1px rgba(121, 63, 10, 0.6), 0 0 0 3px rgba(255, 190, 61, 0.22);
			pointer-events: none;
			z-index: 2;
		}

		.bar span,
		.mini-bar span {
			display: block;
			height: 100%;
			border-radius: inherit;
			background: linear-gradient(90deg, var(--md-sys-color-primary), var(--md-sys-color-tertiary));
			position: relative;
			z-index: 1;
		}

		.insight-list,
		.mini-grid {
			display: grid;
			gap: 14px;
		}

		.insight-card {
			border-radius: var(--md-sys-shape-corner-large);
			padding: 18px;
			background: var(--md-sys-color-surface-container-low);
		}

		.insight-value,
		.mini-value {
			margin-top: 10px;
			font-size: 1.6rem;
			line-height: 1.1;
			letter-spacing: -0.03em;
			font-family: var(--font-family);
		}

		.mini-grid {
			grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		}

		.mini-card {
			position: relative;
			overflow: hidden;
			border-radius: var(--md-sys-shape-corner-large);
			padding: 18px;
			min-height: 156px;
			background: var(--md-sys-color-surface-container-lowest);
		}

		.mini-card::before {
			content: '';
			position: absolute;
			inset: auto -26px -44px auto;
			width: 180px;
			height: 180px;
			border-radius: 50%;
			background: radial-gradient(circle, rgba(0, 106, 106, 0.10), rgba(0, 0, 0, 0) 70%);
			box-shadow: 0 0 0 1px var(--md-sys-color-primary-container) inset;
			opacity: 0.2;
			pointer-events: none;
		}

		.mini-sub {
			margin-top: 6px;
			color: var(--color-text-light);
			line-height: 1.6;
		}

		.mini-meta {
			margin-top: 12px;
			color: var(--md-sys-color-primary);
			font-size: 0.82rem;
			font-weight: 650;
			position: relative;
			z-index: 1;
		}

		.mini-bar {
			margin-top: 16px;
			position: relative;
			z-index: 1;
		}

		.empty-state {
			padding: 26px;
			border-radius: var(--md-sys-shape-corner-large);
			border: 1px dashed var(--color-border);
			background: var(--md-sys-color-surface-container-low);
		}

		.footer-note {
			margin-top: 16px;
		}

		@media (max-width: 1180px) {
			.hero,
			.grid-two {
				grid-template-columns: 1fr;
			}

			.stats-grid {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}
		}

		@media (max-width: 720px) {
			.shell {
				padding: 16px 12px 36px;
			}

			h1 {
				font-size: clamp(2rem, 9vw, 2.4rem);
			}

			.hero {
				gap: 12px;
				padding: 18px;
				border-radius: 22px;
			}

			.panel,
			.summary-card,
			.filter-card,
			.insight-card,
			.mini-card {
				padding: 16px;
				border-radius: 18px;
			}

			.meta {
				gap: 8px;
				margin-top: 16px;
			}

			.pill,
			.tag {
				width: 100%;
				justify-content: flex-start;
				padding: 8px 12px;
				font-size: 0.84rem;
			}

			.filter-card {
				gap: 12px;
			}

			.select,
			.button {
				min-height: 48px;
				padding: 12px 14px;
			}

			.stats-grid {
				grid-template-columns: 1fr;
				gap: 12px;
			}

			.panel-head {
				flex-direction: column;
				gap: 10px;
			}

			.panel-head h2 {
				font-size: 1.2rem;
				line-height: 1.2;
			}

			.panel-head p,
			.filter-card p,
			.summary-note,
			.insight-note,
			.footer-note,
			.empty-state {
				font-size: 0.95rem;
				line-height: 1.6;
			}

			.summary-value,
			.insight-value,
			.mini-value {
				font-size: 1.45rem;
			}

			.table-wrap {
				overflow: visible;
				border: 0;
				background: transparent;
				border-radius: 0;
			}

			table {
				display: block;
				width: 100%;
				min-width: 0;
			}

			thead {
				display: none;
			}

			tbody {
				display: grid;
				gap: 12px;
			}

			tbody tr {
				display: grid;
				grid-template-columns: minmax(0, 1fr);
				align-items: start;
				gap: 10px;
				padding: 14px;
				border: 1px solid var(--color-border);
				border-radius: 18px;
				background: var(--md-sys-color-surface-container-lowest);
				box-shadow: var(--md-sys-elevation-level1);
			}

			tbody tr + tr td {
				border-top: 0;
			}

			td {
				display: flex;
				flex-direction: column;
				gap: 4px;
				padding: 0;
				min-width: 0;
			}

			td::before {
				content: attr(data-label);
				font-size: 0.64rem;
				line-height: 1.2;
				text-transform: uppercase;
				letter-spacing: 0.16em;
				font-weight: 700;
				color: var(--md-sys-color-primary);
			}

			td.rank {
				width: fit-content;
				flex-direction: row;
				align-items: center;
				gap: 8px;
				padding: 6px 10px;
				border-radius: 999px;
				background: var(--md-sys-color-primary-container);
				color: var(--md-sys-color-on-primary-container);
				font-weight: 700;
			}

			td.rank::before {
				color: inherit;
				font-size: 0.6rem;
				letter-spacing: 0.14em;
			}

			.participant-link {
				gap: 2px;
			}

			.participant-link strong {
				font-size: 0.95rem;
				line-height: 1.35;
			}

			.participant-link small {
				font-size: 0.84rem;
			}

			.score-box {
				min-width: 0;
				width: 100%;
			}

			.score-top {
				align-items: center;
			}

			.mini-grid {
				grid-template-columns: 1fr;
			}

			.mini-card {
				min-height: 136px;
			}
		}

		@media (max-width: 560px) {
			.shell {
				padding: 12px 10px 28px;
			}

			.hero,
			.panel,
			.summary-card,
			.filter-card,
			.insight-card,
			.mini-card {
				border-radius: 16px;
			}

			.meta {
				flex-direction: column;
			}

			.pill,
			.tag {
				width: 100%;
			}

			.panel-head .tag {
				justify-content: center;
			}

			table {
				min-width: 0;
			}

			tbody tr {
				padding: 12px;
				gap: 8px;
				border-radius: 16px;
			}

			td::before {
				font-size: 0.6rem;
			}

			.hero section,
			.filter-card,
			.panel-head > div,
			.table-wrap {
				min-width: 0;
			}
		}
	</style>
</head>
<body>
	<div class="shell">
		<header class="hero">
			<section>
				<div class="kicker">Business Intelligence <?= bi_h($analysisModeLabel) ?></div>
				<h1><?= $analysisScope === 'all' ? 'Analisis Semua Kelompok' : 'Analisis Mentee ' . bi_h($analysisLabel) ?></h1>
				<p class="subtitle">
					<?php if ($isMenteeFocus) : ?>
						Dashboard ini fokus membaca data_amalan.json milik <?= bi_h($analysisLabel) ?> pada periode yang dipilih.
					<?php elseif ($analysisScope === 'all') : ?>
						Dashboard ini membandingkan file data_amalan.json lintas kelompok di workspace ini.
					<?php else : ?>
						Dashboard ini membandingkan file data_amalan.json antar folder pribadi di bawah <?= bi_h($analysisContextLabel) ?>.
					<?php endif; ?>
				</p>

				<div class="meta">
					<?php if ($analysisScope === 'all') : ?>
						<span class="pill"><?= bi_h($groupCount) ?> kelompok</span>
					<?php else : ?>
						<span class="pill">Folder: <?= bi_h(basename($analysisRoot)) ?></span>
						<a href="/test/bi/" class="pill"><u>Lihat semua kelompok</u></a>
					<?php endif; ?>
					<?php if ($isMenteeFocus) : ?>
						<span class="pill">Mentee: <?= bi_h($focusedParticipant['full_label'] ?? $focusedParticipant['label'] ?? '-') ?></span>
						<a href="<?= bi_h($resetFocusUrl) ?>" class="pill"><u>Kembali ke mode kelompok</u></a>
						<?php if ($focusedInputUrl !== '') : ?>
							<a href="<?= bi_h($focusedInputUrl) ?>" class="pill"><u>Buka Halaman Input</u></a>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</section>

			<form class="filter-card" method="get">
				<div>
					<div class="filter-label"><?= $isMenteeFocus ? 'Fokus Mentee' : 'Filter Analisis' ?></div>
					<p><?= $isMenteeFocus ? 'Angka di dashboard hanya dihitung dari mentee yang dipilih.' : 'Pilih bulan dan mentee yang ingin dilihat.' ?></p>
				</div>
				<?php if ($analysisScope === 'all') : ?>
					<input type="hidden" name="scope" value="all">
				<?php endif; ?>
				<div class="filter-stack">
					<div>
						<label class="filter-label" for="month">Bulan</label>
						<select id="month" name="month" class="select">
							<?php foreach ($availableMonths as $monthOption) : ?>
								<option value="<?= bi_h($monthOption) ?>" <?= $monthOption === $selectedMonth ? 'selected' : '' ?>><?= bi_h(bi_month_label($monthOption)) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="filter-label" for="mentee">Mentee</label>
						<select id="mentee" name="mentee" class="select">
							<option value="__all" <?= !$isMenteeFocus ? 'selected' : '' ?>>Semua mentee</option>
							<?php foreach ($rawParticipants as $participantOption) : ?>
								<?php $optionKey = bi_participant_focus_key($participantOption); ?>
								<option value="<?= bi_h($optionKey) ?>" <?= $optionKey === $selectedMenteeKey ? 'selected' : '' ?>>
									<?= bi_h($analysisScope === 'all' ? ($participantOption['full_label'] ?? $participantOption['label']) : ($participantOption['label'] ?? $participantOption['folder'])) ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div class="filter-actions">
					<button type="submit" class="button"><?= $isMenteeFocus ? 'Tampilkan Fokus' : 'Tampilkan Analisis' ?></button>
				</div>
			</form>
		</header>

		<section class="stats-grid">
			<?php if ($isMenteeFocus) : ?>
				<article class="summary-card">
					<div class="summary-label">Mode fokus</div>
					<div class="summary-value"><?= bi_h($focusedParticipant['label'] ?? 'Mentee') ?></div>
					<div class="summary-note"><?= $analysisScope === 'all' ? bi_h($focusedParticipant['group_label'] ?? '') : 'Mentee yang sedang dianalisis.' ?></div>
				</article>
				<article class="summary-card">
					<div class="summary-label"><?= $hasTargetRules ? 'Capaian target' : 'Skor bulan ini' ?></div>
					<div class="summary-value"><?= bi_h(number_format($topDisplayScore, 1, ',', '.')) ?>%</div>
					<div class="summary-note"><?= $hasTargetRules ? 'Dihitung dari aturan target periode ini.' : 'Dihitung dari keterisian amalan bulan ini.' ?></div>
				</article>
				<article class="summary-card">
					<div class="summary-label">Skor input</div>
					<div class="summary-value"><?= bi_h(number_format($averageScore, 1, ',', '.')) ?>%</div>
					<div class="summary-note">Keterisian semua kolom amalan sebagai pembanding.</div>
				</article>
				<article class="summary-card">
					<div class="summary-label">Hari aktif</div>
					<div class="summary-value"><?= bi_h($topParticipant['metrics']['active_days'] ?? 0) ?>/<?= bi_h($daysInMonth) ?></div>
					<div class="summary-note">Jumlah hari yang punya data pada bulan ini.</div>
				</article>
			<?php elseif ($analysisScope === 'all') : ?>
				<article class="summary-card">
					<div class="summary-label">Kelompok terdata</div>
					<div class="summary-value"><?= bi_h($groupCount) ?></div>
					<div class="summary-note">Kelompok yang terdeteksi.</div>
				</article>
				<article class="summary-card">
					<div class="summary-label">Mentee terdata</div>
					<div class="summary-value"><?= bi_h(count($participants)) ?></div>
					<div class="summary-note">Mentee yang dibandingkan.</div>
				</article>
				<article class="summary-card">
					<div class="summary-label"><?= $hasTargetRules ? 'Rata-rata target' : 'Rata-rata skor' ?></div>
					<div class="summary-value"><?= bi_h(number_format($displayAverageScore, 1, ',', '.')) ?>%</div>
					<div class="summary-note"><?= $hasTargetRules ? 'Capaian target rata-rata seluruh mentee.' : 'Skor rata-rata seluruh mentee pada periode yang dipilih.' ?></div>
				</article>
				<article class="summary-card">
					<div class="summary-label"><?= $hasTargetRules ? 'Kelompok target terkuat' : 'Kelompok terkuat' ?></div>
					<div class="summary-value"><?= bi_h(number_format($hasTargetRules ? ($topGroupSummary['target_average_percent'] ?? 0) : ($topGroupSummary['average_percent'] ?? 0), 1, ',', '.')) ?>%</div>
					<div class="summary-note"><?= bi_h($topGroupSummary['label'] ?? 'Belum ada data') ?> memimpin lintas grup.</div>
				</article>
			<?php else : ?>
				<article class="summary-card">
					<div class="summary-label">Mentee terdata</div>
					<div class="summary-value"><?= bi_h(count($participants)) ?></div>
					<div class="summary-note">Mentee yang dibandingkan.</div>
				</article>
				<article class="summary-card">
					<div class="summary-label"><?= $hasTargetRules ? 'Rata-rata target' : 'Rata-rata skor' ?></div>
					<div class="summary-value"><?= bi_h(number_format($displayAverageScore, 1, ',', '.')) ?>%</div>
					<div class="summary-note"><?= $hasTargetRules ? 'Capaian target rata-rata kelompok ini.' : 'Skor rata-rata seluruh mentee pada periode yang dipilih.' ?></div>
				</article>
				<article class="summary-card">
					<div class="summary-label"><?= $hasTargetRules ? 'Target tertinggi' : 'Skor tertinggi' ?></div>
					<div class="summary-value"><?= bi_h(number_format($topDisplayScore, 1, ',', '.')) ?>%</div>
					<div class="summary-note"><?= bi_h($topParticipant['label'] ?? 'Belum ada data') ?> memimpin bulan ini.</div>
				</article>
				<?php if ($hasTargetRules) : ?>
					<article class="summary-card">
						<div class="summary-label">Rata-rata input</div>
						<div class="summary-value"><?= bi_h(number_format($averageScore, 1, ',', '.')) ?>%</div>
						<div class="summary-note">Keterisian semua kolom amalan sebagai pembanding.</div>
					</article>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<?php if ($analysisScope === 'all' && !$isMenteeFocus && !empty($groupSummaries)) : ?>
			<section class="panel" style="margin-bottom: 16px;">
				<div class="panel-head">
					<div>
						<div class="section-label">Kelompok</div>
						<h2>Ringkasan Semua Kelompok</h2>
						<p>Klik kartu kelompok untuk masuk ke dashboard masing-masing grup.</p>
					</div>
					<span class="tag"><?= bi_h($groupCount) ?> kelompok terurut</span>
				</div>

				<div class="mini-grid">
					<?php foreach ($groupSummaries as $index => $groupSummary) : ?>
						<a class="mini-card" href="<?= bi_h($groupSummary['url']) ?>" style="--accent: <?= bi_h($groupPalette[$index % count($groupPalette)]) ?>;">
							<div class="mini-label"><?= bi_h($groupSummary['label']) ?></div>
							<?php $groupScore = $hasTargetRules ? ($groupSummary['target_average_percent'] ?? 0) : ($groupSummary['average_percent'] ?? 0); ?>
							<?php $groupTop = $hasTargetRules ? ($groupSummary['top_target_participant'] ?? null) : ($groupSummary['top_participant'] ?? null); ?>
							<div class="mini-value"><?= bi_h(number_format($groupScore, 1, ',', '.')) ?>%</div>
							<div class="mini-sub"><?= bi_h($groupSummary['members']) ?> mentee • top <?= bi_h($groupTop['full_label'] ?? '-') ?><?php if (!empty($groupTop['mentor'])) : ?> <span class="mentor-chip">Mentor</span><?php endif; ?></div>
							<?php if ($hasTargetRules) : ?><div class="mini-meta">Input <?= bi_h(number_format($groupSummary['average_percent'] ?? 0, 1, ',', '.')) ?>%</div><?php endif; ?>
							<div class="mini-bar"><span style="width: <?= bi_h($groupScore) ?>%;"></span></div>
						</a>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<section class="grid-two">
			<div class="panel">
				<div class="panel-head">
					<div>
						<div class="section-label">Ranking</div>
						<h2>
							<?php if ($isMenteeFocus) : ?>
								<?= $hasTargetRules ? 'Detail Capaian Target Mentee' : 'Detail Keterisian Mentee' ?>
							<?php elseif ($hasTargetRules) : ?>
								<?= $analysisScope === 'all' ? 'Urutan Capaian Target Lintas Grup' : 'Urutan Capaian Target Bulan Ini' ?>
							<?php else : ?>
								<?= $analysisScope === 'all' ? 'Urutan Keterisian Lintas Grup' : 'Urutan Keterisian Bulan Ini' ?>
							<?php endif; ?>
						</h2>
						<p>
							<?php if ($isMenteeFocus) : ?>
								Menampilkan satu mentee pada periode <?= bi_h(bi_month_label($selectedMonth)) ?> supaya capaian targetnya mudah dibaca.
							<?php elseif ($analysisScope === 'all') : ?>
								<?= $hasTargetRules ? 'Disusun dari capaian target tertinggi ke terendah untuk semua mentee pada periode ' : 'Disusun dari skor tertinggi ke terendah untuk semua mentee pada periode ' ?><?= bi_h(bi_month_label($selectedMonth)) ?>.
							<?php else : ?>
								<?= $hasTargetRules ? 'Disusun dari capaian target tertinggi ke terendah untuk periode ' : 'Disusun dari skor tertinggi ke terendah untuk periode ' ?><?= bi_h(bi_month_label($selectedMonth)) ?>.
							<?php endif; ?>
						</p>
						<?php if (!empty($targetRules)) : ?>
							<div class="chart-legend" aria-label="Legenda grafik">
								<span class="legend-item"><span class="legend-swatch legend-swatch--fill" aria-hidden="true"></span> Capaian target</span>
								<span class="legend-item"><span class="legend-swatch legend-swatch--target" aria-hidden="true"></span> Pembanding kategori</span>
							</div>
						<?php endif; ?>
					</div>
					<span class="tag"><?= $isMenteeFocus ? 'Mode fokus' : ($hasTargetRules ? 'Target tertinggi' : 'Aktif tertinggi') ?>: <?= bi_h($isMenteeFocus ? ($focusedParticipant['label'] ?? '-') : ($hasTargetRules ? ($topParticipant['full_label'] ?? $topParticipant['label'] ?? '-') : ($mostActiveParticipant['full_label'] ?? $mostActiveParticipant['label'] ?? '-'))) ?></span>
				</div>

				<?php if (!empty($participants)) : ?>
					<div class="table-wrap">
						<table>
							<thead>
								<tr>
									<th>#</th>
									<?php if ($analysisScope === 'all') : ?><th>Kelompok</th><?php endif; ?>
									<th>Mentee</th>
									<th><?= $hasTargetRules ? 'Capaian Target' : 'Skor' ?></th>
									<?php if ($hasTargetRules) : ?><th>Skor Input</th><?php endif; ?>
									<th>Rawatib</th>
									<th>Istighfar</th>
									<th>Aktif</th>
									<th>Bulan Data</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($participants as $index => $participant) : ?>
									<?php
										$participantFocusParams = ['month' => $selectedMonth, 'mentee' => bi_participant_focus_key($participant)];
										if ($analysisScope === 'all') {
											$participantFocusParams['scope'] = 'all';
										}
										$participantFocusUrl = '?' . http_build_query($participantFocusParams);
									?>
									<tr>
										<td class="rank" data-label="Peringkat"><?= bi_h($index + 1) ?></td>
										<?php if ($analysisScope === 'all') : ?>
											<td data-label="Kelompok"><?= bi_h($participant['group_label']) ?></td>
										<?php endif; ?>
										<td data-label="Mentee">
											<a class="participant-link" href="<?= bi_h($participantFocusUrl) ?>">
												<strong><?= bi_h($participant['full_label'] ?? $participant['label']) ?><?php if (!empty($participant['mentor'])) : ?> <span class="mentor-chip">Mentor</span><?php endif; ?></strong>
												<small><?= $isMenteeFocus ? 'Sedang difokuskan' : 'Klik untuk fokus BI' ?> • <?= bi_h($participant['months_tracked']) ?> bulan data tersimpan</small>
											</a>
										</td>
										<td data-label="<?= $hasTargetRules ? 'Capaian Target' : 'Skor' ?>">
											<?php $targetPercent = $hasTargetRules ? (float) ($participant['target_metrics']['percent'] ?? 0) : (float) $participant['metrics']['overall_percent']; ?>
											<div class="score-box">
												<div class="score-top">
													<span class="score-value"><?= bi_h(number_format($targetPercent, 1, ',', '.')) ?>%</span>
													<span class="muted">
														<?php if ($hasTargetRules) : ?>
															<?= bi_h(bi_format_target_number($participant['target_metrics']['done'] ?? 0)) ?>/<?= bi_h(bi_format_target_number($participant['target_metrics']['possible'] ?? 0)) ?>
														<?php else : ?>
															<?= bi_h($participant['metrics']['overall_done']) ?>/<?= bi_h($participant['metrics']['overall_possible']) ?>
														<?php endif; ?>
													</span>
												</div>
												<div class="bar">
													<span style="width: <?= bi_h($targetPercent) ?>%;"></span>
												</div>
												<?php if ($hasTargetRules && !empty($participant['target_metrics']['best_rule'])) : ?>
													<div class="score-sub">Terkuat: <?= bi_h($participant['target_metrics']['best_rule']['label'] ?? '-') ?></div>
												<?php endif; ?>
											</div>
										</td>
										<?php if ($hasTargetRules) : ?>
											<td data-label="Skor Input">
												<div class="score-box">
													<div class="score-top">
														<span class="score-value"><?= bi_h(number_format($participant['metrics']['overall_percent'], 1, ',', '.')) ?>%</span>
														<span class="muted"><?= bi_h($participant['metrics']['overall_done']) ?>/<?= bi_h($participant['metrics']['overall_possible']) ?></span>
													</div>
													<div class="bar"><span style="width: <?= bi_h($participant['metrics']['overall_percent']) ?>%;"></span></div>
												</div>
											</td>
										<?php endif; ?>
										<td data-label="Rawatib"><?= bi_h(number_format($participant['metrics']['rawatib_percent'], 1, ',', '.')) ?>%</td>
										<td data-label="Istighfar"><?= bi_h(number_format($participant['metrics']['istighfar_average'], 1, ',', '.')) ?></td>
										<td data-label="Aktif"><?= bi_h($participant['metrics']['active_days']) ?>/<?= bi_h($daysInMonth) ?></td>
										<td data-label="Bulan Data"><?= !empty($participant['latest_month']) ? bi_h(bi_month_label($participant['latest_month'])) : '-' ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<div class="empty-state">Belum ada folder anak yang bisa dianalisis. Tambahkan folder personal lalu muat ulang halaman ini.</div>
				<?php endif; ?>
			</div>

			<aside class="panel">
				<div class="panel-head">
					<div>
						<div class="section-label">Insight</div>
						<h2>Ringkasan Cepat</h2>
						<p>Potongan data yang membantu membaca kondisi <?= $analysisScope === 'all' ? 'lintas grup' : 'kelompok' ?> tanpa perlu menelusuri tabel penuh.</p>
					</div>
				</div>

				<div class="insight-list">
					<article class="insight-card">
						<div class="insight-label"><?= $hasTargetRules ? 'Target peringkat 1' : 'Peringkat 1' ?></div>
						<div class="insight-value"><?= bi_h($topParticipant['full_label'] ?? $topParticipant['label'] ?? '-') ?></div>
						<div class="insight-note"><?= bi_h(number_format($topDisplayScore, 1, ',', '.')) ?>% <?= $hasTargetRules ? 'capaian target' : 'skor bulan ini' ?>.</div>
					</article>
					<?php if ($hasTargetRules) : ?>
						<article class="insight-card">
							<div class="insight-label">Target terkuat</div>
							<div class="insight-value"><?= bi_h($bestTargetRule['label'] ?? '-') ?></div>
							<div class="insight-note"><?= bi_h(number_format($bestTargetRule['percent'] ?? 0, 1, ',', '.')) ?>% rata-rata capaian target.</div>
						</article>
						<article class="insight-card">
							<div class="insight-label">Perlu perhatian</div>
							<div class="insight-value"><?= bi_h($weakestTargetRule['label'] ?? '-') ?></div>
							<div class="insight-note"><?= bi_h(number_format($weakestTargetRule['percent'] ?? 0, 1, ',', '.')) ?>% rata-rata capaian target.</div>
						</article>
					<?php else : ?>
						<article class="insight-card">
							<div class="insight-label">Kategori terkuat</div>
							<div class="insight-value"><?= bi_h($bestCategory !== '' ? $bestCategory : '-') ?></div>
							<div class="insight-note"><?= bi_h(number_format($bestCategoryPercent, 1, ',', '.')) ?>% rata-rata kelompok.</div>
						</article>
					<?php endif; ?>
					<?php if ($analysisScope === 'all') : ?>
						<article class="insight-card">
							<div class="insight-label"><?= $hasTargetRules ? 'Kelompok target terkuat' : 'Kelompok terkuat' ?></div>
							<div class="insight-value"><?= bi_h($topGroupSummary['label'] ?? '-') ?></div>
							<div class="insight-note"><?= bi_h(number_format($hasTargetRules ? ($topGroupSummary['target_average_percent'] ?? 0) : ($topGroupSummary['average_percent'] ?? 0), 1, ',', '.')) ?>% rata-rata grup.</div>
						</article>
					<?php endif; ?>
				</div>
			</aside>
		</section>

		<section class="panel" style="margin-top: 16px;">
			<div class="panel-head">
				<div>
					<div class="section-label">Kategori</div>
					<h2><?= $hasTargetRules ? 'Kinerja per Kategori dan Target' : 'Rata-rata Kinerja per Kategori' ?></h2>
					<p><?= $hasTargetRules ? 'Gambaran keterisian kategori dengan penanda capaian target pada periode yang sedang dipilih.' : 'Gambaran kelompok untuk setiap kategori amalan pada periode yang sedang dipilih.' ?></p>
				</div>
				<span class="tag"><?= bi_h(number_format($displayAverageScore, 1, ',', '.')) ?>% <?= $hasTargetRules ? 'rata-rata target' : 'rata-rata kelompok' ?></span>
			</div>

			<div class="mini-grid">
				<?php foreach ($categoryTotals as $categoryName => $stats) : ?>
					<article class="mini-card" style="--accent: <?= bi_h($categoryColors[$categoryName] ?? '#7df0c5') ?>;">
						<div class="mini-label"><?= bi_h($categoryName) ?></div>
						<div class="mini-value"><?= bi_h(number_format($stats['percent'], 1, ',', '.')) ?>%</div>
						<div class="mini-sub">
							<?= bi_h($stats['done']) ?> dari <?= bi_h($stats['possible']) ?> poin tercatat
							<?php if (!empty($targetCategorySummary[$categoryName])) : ?>
								<br>Target <?= bi_h(number_format($targetCategorySummary[$categoryName]['percent'] ?? 0, 1, ',', '.')) ?>%
							<?php endif; ?>
						</div>
						<div class="mini-bar"<?php if (!empty($targetCategorySummary[$categoryName])) : ?> style="--target-left: <?= bi_h(number_format($targetCategorySummary[$categoryName]['percent'] ?? 0, 1, '.', '')) ?>%;"<?php endif; ?>>
							<?php if (!empty($targetCategorySummary[$categoryName])) : ?><span class="target-marker" title="Target <?= bi_h(number_format($targetCategorySummary[$categoryName]['percent'] ?? 0, 1, ',', '.')) ?>%"></span><?php endif; ?>
							<span style="width: <?= bi_h($stats['percent']) ?>%;"></span>
						</div>
					</article>
				<?php endforeach; ?>
			</div>

			<div class="footer-note">
				Data diambil dari folder <?= bi_h(basename($analysisRoot)) ?>.
			</div>
		</section>

		<?php if (!empty($targetRuleSummaries)) : ?>
			<section class="panel" style="margin-top: 16px;">
				<div class="panel-head">
					<div>
						<div class="section-label">Target</div>
						<h2>Rincian Capaian Target</h2>
						<p>Setiap kartu mengikuti aturan di <?= bi_h(basename($analysisRoot)) ?>/target_settings.json, termasuk target harian, mingguan, dan bulanan.</p>
					</div>
					<span class="tag"><?= bi_h(count($targetRuleSummaries)) ?> target aktif</span>
				</div>

				<div class="mini-grid">
					<?php foreach ($targetRuleSummaries as $index => $ruleSummary) : ?>
						<article class="mini-card" style="--accent: <?= bi_h($categoryColors[$ruleSummary['category']] ?? $groupPalette[$index % count($groupPalette)]) ?>;">
							<div class="mini-label"><?= bi_h($ruleSummary['category'] ?: 'Target') ?></div>
							<div class="mini-value"><?= bi_h(number_format($ruleSummary['percent'], 1, ',', '.')) ?>%</div>
							<div class="mini-sub">
								<strong><?= bi_h($ruleSummary['label']) ?></strong>
								<?php if (trim((string) ($ruleSummary['target'] ?? '')) !== '') : ?>
									<br><?= bi_h($ruleSummary['target']) ?>
								<?php endif; ?>
							</div>
							<div class="mini-meta">
								<?= bi_h(bi_format_target_number($ruleSummary['done'])) ?>/<?= bi_h(bi_format_target_number($ruleSummary['possible'])) ?> unit target
							</div>
							<div class="mini-bar"><span style="width: <?= bi_h($ruleSummary['percent']) ?>%;"></span></div>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>
	</div>
</body>
</html>
