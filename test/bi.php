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

// Mesin perhitungan aturan target (bi_read_json, bi_target_*, bi_count_tilawah_pages,
// bi_load_target_settings*, dst) ada di file terpisah agar bisa dipakai bersama
// halaman penginputan mentee (test/index.php).
require_once __DIR__ . '/target_engine.php';

// Logika gembok puasa sunnah (hari bergembok tidak dihitung sebagai possible).
require_once __DIR__ . '/puasa_sunnah.php';

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

/**
 * Daftar resmi opsi detail rawatib (HANYA MU'AKAD SESUAI ANJURAN).
 * Ini sumber kebenaran jumlah opsi rawatib, BUKAN hasil hitungan key
 * yang kebetulan ada di data.
 *
 * Alasan: data lama disimpan sebagai ringkasan "3/5" tanpa key detail.
 * Kalau jumlah dihitung dari key yang ada, satu bulan yang sudah punya
 * key detail akan membuat count jadi kecil dan ringkasan lama ("3/5")
 * terpotong jadi "2/2".
 */

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

// Stub baru (satu orang beberapa grup) TIDAK lagi me-redirect, melainkan
// me-render test/index.php DI TEMPAT lewat require. Deteksi berkas yang
// di-require itu supaya BI bisa menemukan data mentee stub (data-nya ada di
// folder test, bukan di folder mentee).
function bi_extract_require_index_target(string $indexFile): ?string
{
	if (!is_file($indexFile)) {
		return null;
	}

	$contents = file_get_contents($indexFile);
	if ($contents === false) {
		return null;
	}

	// Contoh: require __DIR__ . '/../../test/index.php';
	if (preg_match('/(?:require|include)(?:_once)?\s*(?:\(\s*)?__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/i', $contents, $matches)) {
		$rel = trim($matches[1]);
		if ($rel !== '' && substr($rel, -4) === '.php') {
			return ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel), DIRECTORY_SEPARATOR);
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
		if ($redirectTarget !== null) {
			$currentPath = bi_resolve_redirect_path($currentPath, $redirectTarget);
			if ($currentPath === null) {
				break;
			}

			$directDataFile = $currentPath . DIRECTORY_SEPARATOR . 'data_amalan.json';
			if (is_file($directDataFile)) {
				return [$directDataFile, $currentPath];
			}
			continue;
		}

		// Stub baru: ikuti berkas yang di-require (render in-place).
		$requireTarget = bi_extract_require_index_target($indexFile);
		if ($requireTarget !== null) {
			$requiredFile = realpath($currentPath . DIRECTORY_SEPARATOR . $requireTarget);
			if ($requiredFile === false || !is_file($requiredFile)) {
				break;
			}

			$currentPath = dirname($requiredFile);
			$directDataFile = $currentPath . DIRECTORY_SEPARATOR . 'data_amalan.json';
			if (is_file($directDataFile)) {
				return [$directDataFile, $currentPath];
			}
			continue;
		}

		break;
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

	// Ringkasan tersimpan "3/5". Penyebut yang tersimpan adalah catatan
	// OTORITATIF saat itu, jadi pakai apa adanya dan jangan dipotong
	// dengan count global - kalau tidak "3/5" jadi "3/2".
	if (is_string($rawValue) && preg_match('/^(\d+)\s*\/\s*(\d+)$/', trim($rawValue), $match)) {
		$storedTotal = (int) $match[2];
		$possible = $storedTotal > 0 ? $storedTotal : $rawatibDetailCount;
		return [
			'done' => min((int) $match[1], $possible),
			'possible' => $possible,
			'has_data' => true,
		];
	}

	// Hitung hanya dari key rawatib yang resmi, supaya key lain yang
	// kebetulan diawali "rawatib_" tidak ikut terhitung.
	$officialKeys = bi_rawatib_detail_keys();
	$done = 0;
	foreach ($officialKeys as $key) {
		$values = $monthData[$key] ?? null;
		if (!is_array($values)) {
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

function bi_analyze_month(array $monthData, int $daysInMonth, array $daftar_amalan, int $rawatibDetailCount, string $selectedMonth = ''): array
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
					// Hari bergembok (Senin/Kamis atau Ayyamul Bidh di luar
					// jadwal) tidak mungkin diisi, jadi jangan ikut dihitung
					// sebagai "possible" maupun "done".
					if ($selectedMonth !== '' && ($key === 'senin_kamis' || $key === 'ayamul_bidh')) {
						$dateStr = $selectedMonth . '-' . sprintf('%02d', $day);
						if (bi_puasa_key_locked($key, $dateStr)) {
							continue;
						}
					}

					$value = $monthData[$key][$day] ?? '';
					$possible = 1;
					if ($key === 'istighfar') {
						// Batas ideal istighfar = 2 poin/hari (200 kali):
						// 100 = 1 poin, 200 ke atas = 2 poin (maksimal).
						$possible = 2;
						$done = (is_numeric($value) && (float) $value > 0)
							? min((float) $value / 100, 2.0)
							: 0;
						if ($done > 0) {
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

/*
 * Aturan target:
 *   - mode grup          -> <folder grup>/target_settings.json
 *   - mode semua kelompok -> test/bi/target_settings.json
 * Berkas lintas grup itu bernama sama (target_settings.json) tapi diletakkan
 * di folder halaman analisisnya sendiri. Jadi aturan target khusus lintas
 * grup berdiri sendiri, tidak memakai target_settings.json milik grup mana pun.
 */
$crossGroupTargetFile = __DIR__ . DIRECTORY_SEPARATOR . 'bi' . DIRECTORY_SEPARATOR . 'target_settings.json';
$targetSettings = $analysisScope === 'all'
	? bi_load_target_settings_file($crossGroupTargetFile)
	: bi_load_target_settings($analysisRoot);
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

	$metrics = bi_analyze_month($monthData, $daysInMonth, $daftar_amalan, $participant['rawatib_detail_count'], $selectedMonth);
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
 * Data grafik untuk halaman analisis. Diserialisasi ke JSON lalu dipakai
 * oleh skrip kecil di bagian bawah halaman untuk menggambar model tampilan
 * (batang, garis, donat, radar, peta panas). Struktur tiap butir:
 *   label, sub, percent, done, possible, target, color
 */
$biCategoryChart = [];
foreach ($categoryTotals as $categoryName => $stats) {
	$targetPercent = $targetCategorySummary[$categoryName]['percent'] ?? null;
	$biCategoryChart[] = [
		'label'    => (string) $categoryName,
		'sub'      => number_format((float) $stats['done'], 0, ',', '.') . '/' . number_format((float) $stats['possible'], 0, ',', '.'),
		'percent'  => round((float) $stats['percent'], 1),
		'done'     => round((float) $stats['done'], 2),
		'possible' => round((float) $stats['possible'], 2),
		'target'   => $targetPercent === null ? null : round((float) $targetPercent, 1),
		'color'    => $categoryColors[$categoryName] ?? '#7df0c5',
	];
}

$biRuleChart = [];
foreach ($targetRuleSummaries as $index => $ruleSummary) {
	$biRuleChart[] = [
		'label'    => (string) ($ruleSummary['label'] ?? 'Target'),
		'sub'      => (string) ($ruleSummary['category'] ?? ''),
		'percent'  => round((float) ($ruleSummary['percent'] ?? 0), 1),
		'done'     => round((float) ($ruleSummary['done'] ?? 0), 2),
		'possible' => round((float) ($ruleSummary['possible'] ?? 0), 2),
		'target'   => null,
		'color'    => $categoryColors[$ruleSummary['category'] ?? ''] ?? $groupPalette[$index % count($groupPalette)],
	];
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

/*
 * Sumber data sesungguhnya berbeda dari $analysisRoot saat mode lintas grup:
 * pemindaian memakai $scanRoot (akar workspace), sedangkan $analysisRoot hanya
 * menunjuk folder pemanggil (mis. test/). Label di bawah dibuat eksplisit agar
 * tidak lagi tertulis "folder test".
 */
$dataSourceLabel = 'folder ' . basename($analysisRoot);
$targetSettingsLabel = basename($analysisRoot) . '/target_settings.json';
if ($analysisScope === 'all') {
	$dataSourceLabel = 'seluruh kelompok mentoring di workspace ini';
	$targetSettingsLabel = 'bi/target_settings.json (target khusus lintas grup)';
	if (!empty($groupSummaries)) {
		$groupLabelList = [];
		foreach ($groupSummaries as $groupSummary) {
			$groupLabel = (string) ($groupSummary['label'] ?? $groupSummary['group'] ?? '');
			if ($groupLabel !== '') {
				$groupLabelList[] = $groupLabel;
			}
		}
		if (!empty($groupLabelList)) {
			$totalGroups = count($groupLabelList);
			$shownGroups = array_slice($groupLabelList, 0, 6);
			$groupLabelList = implode(', ', $shownGroups) . ($totalGroups > count($shownGroups) ? ', …' : '');
			$dataSourceLabel = $totalGroups . ' kelompok (' . $groupLabelList . ')';
		}
	}
}
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
	<link rel="icon" type="image/png" sizes="192x192" href="https://raw.githubusercontent.com/krasyid822/for-manifest-json-pwa/main/icons/icon-192.png">
	<link rel="apple-touch-icon" href="https://raw.githubusercontent.com/krasyid822/for-manifest-json-pwa/main/icons/icon-192.png">
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

		/* --- Model tampilan grafik analisis --- */
		.bi-chart-bar {
			display: flex;
			align-items: center;
			gap: 10px;
			flex-wrap: wrap;
			margin: 4px 0 14px;
		}
		.bi-chart-hint {
			font-size: 0.72rem;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: var(--muted);
		}
		.bi-chart-tabs {
			display: inline-flex;
			flex-wrap: wrap;
			gap: 4px;
			padding: 4px;
			border: 1px solid var(--line-strong);
			border-radius: 999px;
			background: var(--surface-strong);
		}
		.bi-chart-tabs button {
			appearance: none;
			border: 1px solid transparent;
			background: transparent;
			color: var(--muted);
			font: inherit;
			font-size: 0.78rem;
			font-weight: 600;
			padding: 6px 13px;
			border-radius: 999px;
			cursor: pointer;
			line-height: 1.2;
			transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
		}
		.bi-chart-tabs button:hover {
			color: var(--text);
			background: var(--md-sys-color-surface-container-high);
		}
		.bi-chart-tabs button.on {
			background: var(--md-sys-color-secondary-container);
			color: var(--md-sys-color-on-secondary-container);
			font-weight: 800;
		}
		.bi-chart-tabs button:focus-visible {
			outline: 2px solid var(--accent);
			outline-offset: 2px;
		}
		.bi-chart-stage {
			margin-bottom: 6px;
		}
		.bi-svg {
			display: block;
			width: 100%;
			height: auto;
		}
		.bi-grid-line {
			stroke: var(--line);
			stroke-width: 1;
		}
		.bi-axis-text {
			fill: var(--muted);
			font-size: 11px;
		}
		.bi-bar-rect {
			transition: opacity 0.15s ease;
		}
		.bi-bar-rect:hover {
			opacity: 0.82;
		}
		.bi-value-text {
			fill: var(--text);
			font-size: 11px;
			font-weight: 700;
		}
		.bi-target-line,
		.bi-target-tick {
			stroke: var(--accent2);
			stroke-width: 2;
			stroke-dasharray: 5 4;
			fill: none;
		}
		.bi-line-path {
			fill: none;
			stroke: var(--accent);
			stroke-width: 2.5;
			stroke-linejoin: round;
			stroke-linecap: round;
		}
		.bi-line-dot {
			fill: var(--accent);
		}
		.bi-area-path {
			fill: var(--md-sys-color-primary-container);
			fill-opacity: 0.45;
			stroke: none;
		}
		.bi-donut-track {
			stroke: var(--line);
		}
		.bi-radar-target {
			fill: var(--md-sys-color-tertiary-container);
			fill-opacity: 0.35;
			stroke: var(--accent2);
			stroke-width: 2;
			stroke-dasharray: 5 4;
		}
		.bi-radar-area {
			fill: var(--md-sys-color-primary-container);
			fill-opacity: 0.45;
			stroke: var(--accent);
			stroke-width: 2.5;
		}
		.bi-legend {
			display: flex;
			flex-wrap: wrap;
			gap: 8px 16px;
			margin-top: 12px;
		}
		.bi-legend-item {
			display: inline-flex;
			align-items: center;
			gap: 7px;
			font-size: 0.78rem;
			color: var(--muted);
		}
		.bi-legend-dot {
			width: 11px;
			height: 11px;
			border-radius: 3px;
			flex: none;
		}
		.bi-legend-strong {
			color: var(--text);
			font-weight: 700;
		}
		.bi-heat-legend {
			display: flex;
			align-items: center;
			gap: 10px;
			margin: 0 0 12px;
			font-size: 0.72rem;
			color: var(--muted);
		}
		.bi-heat-scale {
			flex: 1;
			height: 12px;
			border-radius: 999px;
			border: 1px solid var(--line);
			background: linear-gradient(90deg, hsl(8, 62%, 13%), hsl(44, 62%, 22%), hsl(80, 62%, 27%), hsl(116, 62%, 31%), hsl(153, 62%, 37%));
		}
		.bi-heat {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(102px, 1fr));
			gap: 6px;
		}
		.bi-heat-cell {
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			text-align: center;
			border-radius: 7px;
			padding: 10px 7px;
			min-height: 86px;
			color: #eafff6;
			min-width: 0;
			text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45);
		}
		.bi-heat-value {
			font-size: 1.25rem;
			font-weight: 800;
			line-height: 1.05;
		}
		.bi-heat-label {
			font-size: 0.64rem;
			letter-spacing: 0.04em;
			text-transform: uppercase;
			opacity: 0.92;
			margin-top: 4px;
			max-width: 100%;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		.bi-chart-empty {
			color: var(--muted);
			font-size: 0.85rem;
			padding: 10px 0;
		}
		@media (max-width: 620px) {
			.bi-chart-tabs button {
				padding: 6px 10px;
				font-size: 0.74rem;
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

			<div class="bi-chart" data-bi-chart="categories" data-bi-data="bi-data-categories">
				<div class="bi-chart-bar">
					<span class="bi-chart-hint">Model tampilan</span>
					<div class="bi-chart-tabs" role="tablist" aria-label="Model tampilan grafik kategori">
						<button type="button" data-bi-model="cards" class="on">Kartu</button>
						<button type="button" data-bi-model="bar">Batang</button>
						<button type="button" data-bi-model="line">Garis</button>
						<button type="button" data-bi-model="donut">Donat</button>
						<button type="button" data-bi-model="radar">Radar</button>
						<button type="button" data-bi-model="heatmap">Peta Panas</button>
					</div>
				</div>
				<div class="bi-chart-stage" hidden></div>
				<div class="bi-chart-cards">
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
				</div>
			</div>

			<div class="footer-note">
				Data diambil dari <?= bi_h($dataSourceLabel) ?>.
			</div>
		</section>

		<?php if (!empty($targetRuleSummaries)) : ?>
			<section class="panel" style="margin-top: 16px;">
				<div class="panel-head">
					<div>
						<div class="section-label">Target</div>
						<h2>Rincian Capaian Target</h2>
						<p>Setiap kartu mengikuti aturan di <?= bi_h($targetSettingsLabel) ?>, termasuk target harian, mingguan, dan bulanan.</p>
					</div>
					<span class="tag"><?= bi_h(count($targetRuleSummaries)) ?> target aktif</span>
				</div>

				<div class="bi-chart" data-bi-chart="rules" data-bi-data="bi-data-rules">
					<div class="bi-chart-bar">
						<span class="bi-chart-hint">Model tampilan</span>
						<div class="bi-chart-tabs" role="tablist" aria-label="Model tampilan grafik target">
							<button type="button" data-bi-model="cards" class="on">Kartu</button>
							<button type="button" data-bi-model="bar">Batang</button>
							<button type="button" data-bi-model="line">Garis</button>
							<button type="button" data-bi-model="donut">Donat</button>
							<button type="button" data-bi-model="radar">Radar</button>
							<button type="button" data-bi-model="heatmap">Peta Panas</button>
						</div>
					</div>
					<div class="bi-chart-stage" hidden></div>
					<div class="bi-chart-cards">
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
					</div>
				</div>
			</section>
		<?php endif; ?>
	</div>
	<script type="application/json" id="bi-data-categories"><?= json_encode($biCategoryChart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
	<?php if (!empty($biRuleChart)) : ?>
	<script type="application/json" id="bi-data-rules"><?= json_encode($biRuleChart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
	<?php endif; ?>
	<script>
	/*
	 * Pengganti model tampilan grafik pada halaman analisis. Data diambil dari
	 * <script type="application/json" data di atas, lalu digambar ulang sebagai
	 * batang / garis / donat / radar / peta panas. Pilihan terakhir disimpan di
	 * localStorage supaya tetap sama saat halaman dibuka lagi.
	 */
	(function () {
		'use strict';

		var NS = 'http://www.w3.org/2000/svg';
		var PALETTE = ['#7df0c5', '#8bc5ff', '#ffd27d', '#ff9f7d', '#9ee7d8', '#f6d97a'];
		var GRID = { w: 760, h: 300, left: 46, right: 16, top: 22, bottom: 72 };

		function clampPercent(value) {
			var n = Number(value);
			if (!isFinite(n)) return 0;
			return Math.max(0, Math.min(100, n));
		}

		function num(value) {
			var n = Number(value);
			return isFinite(n) ? n : 0;
		}

		function svgNode(tag, attrs) {
			var node = document.createElementNS(NS, tag);
			if (attrs) {
				for (var key in attrs) {
					if (Object.prototype.hasOwnProperty.call(attrs, key)) {
						node.setAttribute(key, attrs[key]);
					}
				}
			}
			return node;
		}

		function htmlNode(tag, className, text) {
			var node = document.createElement(tag);
			if (className) node.className = className;
			if (text != null) node.textContent = text;
			return node;
		}

		function truncate(text, max) {
			text = String(text == null ? '' : text).trim();
			if (text.length <= max) return text;
			return text.slice(0, max - 1).replace(/\s+\S*$/, '') + '\u2026';
		}

		function wrapLabel(text, max) {
			text = String(text == null ? '' : text).trim();
			if (text.length <= max) return [text];
			var words = text.split(/\s+/);
			var lines = [];
			var current = '';
			for (var i = 0; i < words.length && lines.length < 2; i++) {
				var candidate = (current ? current + ' ' : '') + words[i];
				if (candidate.length <= max || !current) {
					current = candidate;
				} else {
					lines.push(current);
					current = words[i];
				}
			}
			if (current && lines.length < 2) lines.push(current);
			if (!lines.length) lines = [truncate(text, max)];
			return lines.slice(0, 2).map(function (line) { return truncate(line, max); });
		}

		/*
		 * Lebar teks diukur sungguhan lewat canvas (bukan perkiraan jumlah huruf)
		 * supaya tata letak label bisa menyesuaikan diri: ruang yang dibutuhkan
		 * dihitung dulu, baru ukuran diagram ditentukan.
		 */
		var measureCtx = null;
		var AXIS_FONT = '11px "Roboto Flex", -apple-system, "Segoe UI", system-ui, sans-serif';

		function textWidth(text, font) {
			var str = String(text == null ? '' : text);
			if (!measureCtx && typeof document !== 'undefined') {
				var canvas = document.createElement('canvas');
				measureCtx = canvas.getContext ? canvas.getContext('2d') : null;
			}
			if (measureCtx) {
				measureCtx.font = font || AXIS_FONT;
				return measureCtx.measureText(str).width;
			}
			return str.length * 6.3;
		}

		/* Pangkas teks agar lebarnya tidak melebihi maxWidth (piksel). */
		function fitText(text, maxWidth, font) {
			var str = String(text == null ? '' : text).trim();
			if (maxWidth <= 0) return '';
			if (textWidth(str, font) <= maxWidth) return str;
			var ellipsis = '\u2026';
			var lo = 0;
			var hi = str.length;
			while (lo < hi) {
				var mid = Math.ceil((lo + hi) / 2);
				if (textWidth(str.slice(0, mid) + ellipsis, font) <= maxWidth) lo = mid;
				else hi = mid - 1;
			}
			if (lo <= 0) return ellipsis;
			return str.slice(0, lo).replace(/\s+\S*$/, '') + ellipsis;
		}

		function polar(cx, cy, radius, angle) {
			return [cx + radius * Math.cos(angle), cy + radius * Math.sin(angle)];
		}

		function arcPath(cx, cy, outer, inner, start, span) {
			if (span <= 0) return '';
			if (span >= Math.PI * 2) span = Math.PI * 2 - 0.0001;
			var large = span > Math.PI ? 1 : 0;
			var a = polar(cx, cy, outer, start);
			var b = polar(cx, cy, outer, start + span);
			var c = polar(cx, cy, inner, start + span);
			var d = polar(cx, cy, inner, start);
			return 'M ' + a[0] + ' ' + a[1] +
				' A ' + outer + ' ' + outer + ' 0 ' + large + ' 1 ' + b[0] + ' ' + b[1] +
				' L ' + c[0] + ' ' + c[1] +
				' A ' + inner + ' ' + inner + ' 0 ' + large + ' 0 ' + d[0] + ' ' + d[1] + ' Z';
		}

		function colorOf(item, index) {
			return item.color || PALETTE[index % PALETTE.length];
		}

		function valueToY(percent) {
			var inner = GRID.h - GRID.top - GRID.bottom;
			return GRID.top + inner * (1 - clampPercent(percent) / 100);
		}

		function chartFrame() {
			var svg = svgNode('svg', {
				viewBox: '0 0 ' + GRID.w + ' ' + GRID.h,
				class: 'bi-svg',
				preserveAspectRatio: 'xMidYMid meet',
				role: 'img'
			});
			for (var p = 0; p <= 100; p += 25) {
				var y = valueToY(p);
				svg.appendChild(svgNode('line', {
					x1: GRID.left, y1: y, x2: GRID.w - GRID.right, y2: y, class: 'bi-grid-line'
				}));
				var label = svgNode('text', {
					x: GRID.left - 8, y: y + 4, class: 'bi-axis-text', 'text-anchor': 'end'
				});
				label.textContent = p + '%';
				svg.appendChild(label);
			}
			return svg;
		}

		function appendBottomLabels(svg, items, centerX, maxChars) {
			items.forEach(function (item, index) {
				wrapLabel(item.label, maxChars).forEach(function (line, lineIndex) {
					var text = svgNode('text', {
						x: centerX(index),
						y: GRID.h - GRID.bottom + 18 + lineIndex * 13,
						class: 'bi-axis-text',
						'text-anchor': 'middle'
					});
					text.textContent = line;
					svg.appendChild(text);
				});
			});
		}

		function renderBar(items) {
			var svg = chartFrame();
			var inner = GRID.w - GRID.left - GRID.right;
			var slot = inner / Math.max(1, items.length);
			var width = Math.max(6, Math.min(64, slot * 0.62));

			items.forEach(function (item, index) {
				var value = clampPercent(item.percent);
				var x = GRID.left + slot * index + (slot - width) / 2;
				var y = valueToY(value);
				var rect = svgNode('rect', {
					x: x, y: y, width: width, height: (GRID.h - GRID.bottom) - y,
					rx: 6, fill: colorOf(item, index), class: 'bi-bar-rect'
				});
				var title = svgNode('title');
				title.textContent = item.label + ': ' + value.toFixed(1) + '%';
				rect.appendChild(title);
				svg.appendChild(rect);

				var valueText = svgNode('text', {
					x: x + width / 2, y: Math.max(GRID.top + 10, y - 6),
					class: 'bi-value-text', 'text-anchor': 'middle'
				});
				valueText.textContent = value.toFixed(1) + '%';
				svg.appendChild(valueText);

				if (item.target != null && isFinite(Number(item.target))) {
					var ty = valueToY(item.target);
					svg.appendChild(svgNode('line', {
						x1: x - 4, y1: ty, x2: x + width + 4, y2: ty, class: 'bi-target-tick'
					}));
				}
			});

			appendBottomLabels(svg, items, function (index) {
				return GRID.left + slot * index + slot / 2;
			}, Math.max(8, Math.floor(slot / 6.2)));
			return svg;
		}

		function renderLine(items) {
			var svg = chartFrame();
			var inner = GRID.w - GRID.left - GRID.right;
			var step = items.length > 1 ? inner / (items.length - 1) : 0;
			var xOf = function (index) {
				return items.length > 1 ? GRID.left + step * index : GRID.left + inner / 2;
			};
			var points = items.map(function (item, index) {
				return [xOf(index), valueToY(item.percent)];
			});

			if (points.length > 1) {
				var area = 'M ' + points[0][0] + ' ' + (GRID.h - GRID.bottom);
				points.forEach(function (point) { area += ' L ' + point[0] + ' ' + point[1]; });
				area += ' L ' + points[points.length - 1][0] + ' ' + (GRID.h - GRID.bottom) + ' Z';
				svg.appendChild(svgNode('path', { d: area, class: 'bi-area-path' }));

				var targets = items.map(function (item, index) {
					return (item.target != null && isFinite(Number(item.target)))
						? [xOf(index), valueToY(item.target)]
						: null;
				}).filter(Boolean);
				if (targets.length > 1) {
					svg.appendChild(svgNode('path', {
						d: targets.map(function (point, index) {
							return (index ? 'L ' : 'M ') + point[0] + ' ' + point[1];
						}).join(' '),
						class: 'bi-target-line'
					}));
				}

				svg.appendChild(svgNode('path', {
					d: points.map(function (point, index) {
						return (index ? 'L ' : 'M ') + point[0] + ' ' + point[1];
					}).join(' '),
					class: 'bi-line-path'
				}));
			}

			points.forEach(function (point, index) {
				svg.appendChild(svgNode('circle', {
					cx: point[0], cy: point[1], r: 4.5,
					fill: colorOf(items[index], index), class: 'bi-line-dot'
				}));
				var valueText = svgNode('text', {
					x: point[0], y: Math.max(GRID.top + 10, point[1] - 10),
					class: 'bi-value-text', 'text-anchor': 'middle'
				});
				valueText.textContent = clampPercent(items[index].percent).toFixed(1) + '%';
				svg.appendChild(valueText);
			});

			appendBottomLabels(svg, items, xOf, 11);
			return svg;
		}

		function renderDonut(items) {
			var viewW = 420;
			var rowH = 26;
			var padTop = 16;
			var padRight = 8;
			var viewH = Math.max(300, items.length * rowH + padTop * 2);
			var cy = viewH / 2;

			// Lebar kolom angka di kanan (mis. "100.0%").
			var valueW = 40;
			items.forEach(function (item) {
				valueW = Math.max(valueW, textWidth(clampPercent(item.percent).toFixed(1) + '%'));
			});

			// Label terpanjang menentukan berapa lebar ruang yang dibutuhkan.
			var longest = 0;
			items.forEach(function (item) {
				longest = Math.max(longest, textWidth(item.label));
			});

			var swatchW = 12;
			var swatchGap = 6;
			var valueGap = 10;
			var gap = 16;          // jarak tepi donat ke kolom legenda
			var baseOuter = 118;
			var minOuter = 84;

			// Tata letak untuk satu ukuran donat: makin kecil donat, makin lebar
			// ruang yang tersisa untuk label (donat digeser agar tetap seimbang).
			function layout(outer) {
				var cx = 150 - (baseOuter - outer) / 2;
				var legendX = cx + outer + gap;
				var nameX = legendX + swatchW + swatchGap;
				var nameMax = (viewW - padRight - valueW - valueGap) - nameX;
				return {
					cx: cx,
					outer: outer,
					inner: Math.max(44, Math.round(outer * 0.63)),
					legendX: legendX,
					nameX: nameX,
					nameMax: nameMax
				};
			}

			// Kecilkan donat sedikit demi sedikit sampai label punya cukup ruang,
			// supaya teks label tidak tertimpa/terpotong.
			var fit = layout(baseOuter);
			while (fit.nameMax < longest && fit.outer > minOuter) {
				fit = layout(Math.max(minOuter, fit.outer - 4));
			}
			var cx = fit.cx;
			var outer = fit.outer;
			var inner = fit.inner;
			var legendX = fit.legendX;
			var nameX = fit.nameX;
			var nameMax = Math.max(36, fit.nameMax);
			var valueRight = viewW - padRight;

			var svg = svgNode('svg', {
				viewBox: '0 0 ' + viewW + ' ' + viewH, class: 'bi-svg',
				preserveAspectRatio: 'xMidYMid meet', role: 'img'
			});
			var total = items.reduce(function (sum, item) {
				return sum + Math.max(0, num(item.done != null ? item.done : item.percent));
			}, 0);
			var angle = -Math.PI / 2;

			if (total <= 0) {
				svg.appendChild(svgNode('circle', {
					cx: cx, cy: cy, r: (outer + inner) / 2, fill: 'none',
					class: 'bi-donut-track', 'stroke-width': outer - inner
				}));
			} else {
				items.forEach(function (item, index) {
					var value = Math.max(0, num(item.done != null ? item.done : item.percent));
					var span = (value / total) * Math.PI * 2;
					if (span <= 0) return;
					var path = svgNode('path', {
						d: arcPath(cx, cy, outer, inner, angle, span),
						fill: colorOf(item, index), class: 'bi-bar-rect'
					});
					var title = svgNode('title');
					title.textContent = item.label + ': ' + clampPercent(item.percent).toFixed(1) + '%';
					path.appendChild(title);
					svg.appendChild(path);
					angle += span;
				});
			}

			var average = items.reduce(function (sum, item) {
				return sum + clampPercent(item.percent);
			}, 0) / Math.max(1, items.length);
			var centerSize = Math.max(15, Math.round(outer * 0.19));
			var centerValue = svgNode('text', {
				x: cx, y: cy - 2, class: 'bi-value-text', 'text-anchor': 'middle'
			});
			// Ukuran diatur lewat style (bukan atribut) karena aturan kelas
			// .bi-value-text menang atas atribut presentasi SVG.
			centerValue.style.fontSize = centerSize + 'px';
			centerValue.textContent = average.toFixed(1) + '%';
			svg.appendChild(centerValue);
			var centerLabel = svgNode('text', {
				x: cx, y: cy + centerSize * 0.75 + 4, class: 'bi-axis-text', 'text-anchor': 'middle'
			});
			centerLabel.textContent = 'rata-rata';
			svg.appendChild(centerLabel);

			var legendTop = Math.max(padTop, (viewH - items.length * rowH) / 2);
			items.forEach(function (item, index) {
				var y = legendTop + index * rowH;
				svg.appendChild(svgNode('rect', {
					x: legendX, y: y - 9, width: swatchW, height: 12, rx: 3,
					fill: colorOf(item, index)
				}));
				var name = svgNode('text', { x: nameX, y: y + 1, class: 'bi-axis-text' });
				name.textContent = fitText(item.label, nameMax);
				if (name.textContent !== item.label) {
					var title = svgNode('title');
					title.textContent = item.label;
					name.appendChild(title);
				}
				svg.appendChild(name);
				var val = svgNode('text', {
					x: valueRight, y: y + 1, class: 'bi-value-text', 'text-anchor': 'end'
				});
				val.textContent = clampPercent(item.percent).toFixed(1) + '%';
				svg.appendChild(val);
			});
			return svg;
		}

		function renderRadar(items) {
			if (items.length < 3) return renderBar(items);
			var size = 430;
			var pad = 8;
			var cx = size / 2;
			var cy = size / 2 + 4;
			var count = items.length;
			var angleOf = function (index) { return -Math.PI / 2 + (index * 2 * Math.PI) / count; };
			var labelGap = 16;

			// Ruang horizontal yang tersedia untuk sebuah label pada posisi x:
			// teks di kiri dijangkar ke kanan (end), di kanan ke kiri (start),
			// dan di tengah dipusatkan (middle) sehingga ruangnya dua arah.
			function roomFor(x) {
				if (x > cx + 6) return (size - pad) - x;
				if (x < cx - 6) return x - pad;
				return 2 * Math.min(x - pad, size - pad - x);
			}

			// Label ganjil digeser sedikit keluar bila label saling berdekatan,
			// supaya tidak saling menimpa.
			function labelOffset(index, stagger) {
				return labelGap + (stagger && index % 2 === 1 ? 16 : 0);
			}

			function labelAnchorIn(radius, index, stagger) {
				var point = polar(cx, cy, radius + labelOffset(index, stagger), angleOf(index));
				return {
					x: point[0],
					y: point[1],
					mode: Math.abs(point[0] - cx) < 6 ? 'middle' : (point[0] > cx ? 'start' : 'end')
				};
			}

			function labelBox(anchor, width) {
				var x1 = anchor.mode === 'middle' ? anchor.x - width / 2 : (anchor.mode === 'start' ? anchor.x : anchor.x - width);
				var x2 = anchor.mode === 'middle' ? anchor.x + width / 2 : (anchor.mode === 'start' ? anchor.x + width : anchor.x);
				return { x1: x1, y1: anchor.y - 10, x2: x2, y2: anchor.y + 3 };
			}

			/*
			 * Nilai satu tata letak = total lebar label yang benar-benar muat.
			 * Lebar tiap label dibatasi ruang horizontal yang ada (agar tidak
			 * keluar kotak), lalu dikurangi bertahap selama masih ada label yang
			 * saling menimpa (label yang lebih lebar dikorbankan lebih dulu).
			 */
			function evaluateLayout(radius, stagger) {
				var anchored = items.map(function (item, index) { return labelAnchorIn(radius, index, stagger); });
				var labelWidths = items.map(function (item, index) {
					return Math.max(24, Math.min(textWidth(item.label), roomFor(anchored[index].x)));
				});
				for (var pass = 0; pass < 40; pass++) {
					var changed = false;
					for (var i = 0; i < anchored.length; i++) {
						for (var j = i + 1; j < anchored.length; j++) {
							var boxA = labelBox(anchored[i], labelWidths[i]);
							var boxB = labelBox(anchored[j], labelWidths[j]);
							var ox = Math.min(boxA.x2, boxB.x2) - Math.max(boxA.x1, boxB.x1);
							var oy = Math.min(boxA.y2, boxB.y2) - Math.max(boxA.y1, boxB.y1);
							if (ox <= 0 || oy <= 0) continue;
							var target = labelWidths[i] >= labelWidths[j] ? i : j;
							var next = Math.max(24, labelWidths[target] - (ox + 2));
							if (next < labelWidths[target]) {
								labelWidths[target] = next;
								changed = true;
							}
						}
					}
					if (!changed) break;
				}
				var score = radius * 0.01;
				labelWidths.forEach(function (width) { score += width; });
				return { radius: radius, stagger: stagger, anchors: anchored, widths: labelWidths, score: score };
			}

			// Coba beberapa ukuran radar (dan opsi berselang-seling) lalu pilih
			// yang paling banyak menampilkan teks label tanpa tertimpa.
			var maxRadius = size / 2 - 76;
			var candidates = [];
			for (var candidateRadius = 56; candidateRadius <= maxRadius; candidateRadius += 4) {
				candidates.push(candidateRadius);
			}
			if (candidates[candidates.length - 1] !== maxRadius) candidates.push(maxRadius);

			var best = null;
			candidates.forEach(function (candidateRadius) {
				[false, true].forEach(function (candidateStagger) {
					var layout = evaluateLayout(candidateRadius, candidateStagger);
					if (!best || layout.score > best.score) best = layout;
				});
			});
			var radius = best.radius;
			var anchors = best.anchors;
			var widths = best.widths;

			var svg = svgNode('svg', {
				viewBox: '0 0 ' + size + ' ' + size, class: 'bi-svg',
				preserveAspectRatio: 'xMidYMid meet', role: 'img'
			});

			[25, 50, 75, 100].forEach(function (ring) {
				var points = [];
				for (var i = 0; i < count; i++) {
					var point = polar(cx, cy, radius * ring / 100, angleOf(i));
					points.push(point[0] + ',' + point[1]);
				}
				svg.appendChild(svgNode('polygon', { points: points.join(' '), fill: 'none', class: 'bi-grid-line' }));
			});

			items.forEach(function (item, index) {
				var anchor = anchors[index];
				var edge = polar(cx, cy, radius, angleOf(index));
				svg.appendChild(svgNode('line', { x1: cx, y1: cy, x2: edge[0], y2: edge[1], class: 'bi-grid-line' }));
				var text = svgNode('text', {
					x: anchor.x, y: anchor.y, class: 'bi-axis-text', 'text-anchor': anchor.mode
				});
				text.textContent = fitText(item.label, widths[index]);
				if (text.textContent !== item.label) {
					var title = svgNode('title');
					title.textContent = item.label;
					text.appendChild(title);
				}
				svg.appendChild(text);
			});

			var targets = items.map(function (item, index) {
				return (item.target != null && isFinite(Number(item.target)))
					? polar(cx, cy, radius * clampPercent(item.target) / 100, angleOf(index))
					: null;
			});
			if (targets.every(Boolean)) {
				svg.appendChild(svgNode('polygon', {
					points: targets.map(function (point) { return point[0] + ',' + point[1]; }).join(' '),
					class: 'bi-radar-target'
				}));
			}

			var main = items.map(function (item, index) {
				return polar(cx, cy, radius * clampPercent(item.percent) / 100, angleOf(index));
			});
			svg.appendChild(svgNode('polygon', {
				points: main.map(function (point) { return point[0] + ',' + point[1]; }).join(' '),
				class: 'bi-radar-area'
			}));
			main.forEach(function (point, index) {
				svg.appendChild(svgNode('circle', { cx: point[0], cy: point[1], r: 4, fill: colorOf(items[index], index) }));
			});
			return svg;
		}

		// Warna dihitung langsung sebagai hsl(H, S%, L%) bentuk klasik (koma)
		// supaya kompatibel luas; tidak bergantung pada sintaks spasi modern
		// atau variabel CSS, yang bisa gagal di browser/WebView lama.
		function heatColor(percent) {
			var hue = 8 + (clampPercent(percent) / 100) * 145;
			var light = 13 + (clampPercent(percent) / 100) * 24;
			return 'hsl(' + hue.toFixed(0) + ', 62%, ' + light.toFixed(0) + '%)';
		}

		function renderHeat(items) {
			var wrap = htmlNode('div', 'bi-heat-wrap');

			var legend = htmlNode('div', 'bi-heat-legend');
			legend.appendChild(htmlNode('span', null, '0%'));
			legend.appendChild(htmlNode('div', 'bi-heat-scale'));
			legend.appendChild(htmlNode('span', null, '100%'));
			wrap.appendChild(legend);

			var grid = htmlNode('div', 'bi-heat');
			items.forEach(function (item) {
				var value = clampPercent(item.percent);
				var cell = htmlNode('div', 'bi-heat-cell');
				cell.style.background = heatColor(value);
				cell.title = item.label + (item.sub ? ' \u2014 ' + item.sub : '') + ': ' + value.toFixed(1) + '%';
				cell.appendChild(htmlNode('div', 'bi-heat-value', value.toFixed(1) + '%'));
				cell.appendChild(htmlNode('div', 'bi-heat-label', truncate(item.label, 20)));
				grid.appendChild(cell);
			});
			wrap.appendChild(grid);
			return wrap;
		}

		var RENDERERS = { bar: renderBar, line: renderLine, donut: renderDonut, radar: renderRadar, heatmap: renderHeat };

		function readData(id) {
			if (!id) return [];
			var node = document.getElementById(id);
			if (!node) return [];
			try {
				var data = JSON.parse(node.textContent || '[]');
				return Array.isArray(data) ? data : [];
			} catch (error) {
				return [];
			}
		}

		Array.prototype.forEach.call(document.querySelectorAll('[data-bi-chart]'), function (box) {
			var key = box.getAttribute('data-bi-chart');
			var items = readData(box.getAttribute('data-bi-data'));
			var tabs = box.querySelector('.bi-chart-tabs');
			var stage = box.querySelector('.bi-chart-stage');
			var cards = box.querySelector('.bi-chart-cards');
			if (!tabs || !stage || !items.length) return;

			var storageKey = 'bi-chart-model:' + key;
			var model = 'cards';
			try {
				var saved = window.localStorage.getItem(storageKey);
				if (saved && (saved === 'cards' || RENDERERS[saved])) model = saved;
			} catch (error) { /* localStorage bisa diblokir */ }

			function showCards() {
				stage.hidden = true;
				if (cards) cards.hidden = false;
			}

			function paint() {
				Array.prototype.forEach.call(tabs.querySelectorAll('button[data-bi-model]'), function (button) {
					button.classList.toggle('on', button.getAttribute('data-bi-model') === model);
				});
				stage.innerHTML = '';
				var renderer = RENDERERS[model];
				if (!renderer) { showCards(); return; }
				var node = null;
				try { node = renderer(items); } catch (error) { node = null; }
				if (!node) { showCards(); return; }
				stage.hidden = false;
				if (cards) cards.hidden = true;
				stage.appendChild(node);
			}

			tabs.addEventListener('click', function (event) {
				var button = event.target.closest('button[data-bi-model]');
				if (!button) return;
				model = button.getAttribute('data-bi-model');
				try { window.localStorage.setItem(storageKey, model); } catch (error) { /* abaikan */ }
				paint();
			});

			paint();
		});
	})();
	</script>
</body>
</html>
