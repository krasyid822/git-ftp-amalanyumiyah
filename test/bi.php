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

function bi_h($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bi_month_label($month)
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

function bi_read_json($file)
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

function bi_available_months(array $data)
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

function bi_rawatib_detail_count(array $data)
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

function bi_is_filled_value($value)
{
	if (is_string($value)) {
		return trim($value) !== '';
	}

	return !empty($value);
}

function bi_format_person_name($name)
{
	return ucwords(str_replace(['_', '-'], ' ', $name));
}

function bi_format_group_name($name)
{
	$normalised = str_replace(['_', '-'], ' ', $name);
	if (preg_match('/^[a-z0-9]+$/i', $normalised) && strlen($normalised) <= 4) {
		return strtoupper($normalised);
	}

	return ucwords($normalised);
}

function bi_extract_redirect_target($indexFile)
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

function bi_resolve_redirect_path($basePath, $relativeTarget)
{
	if (!is_string($relativeTarget)) {
		return null;
	}

	$relativeTarget = trim($relativeTarget);
	if ($relativeTarget === '' || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $relativeTarget)) {
		return null;
	}

	$relativeTarget = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeTarget), DIRECTORY_SEPARATOR);
	$resolvedPath = realpath($basePath . DIRECTORY_SEPARATOR . $relativeTarget);

	return $resolvedPath !== false && is_dir($resolvedPath) ? $resolvedPath : null;
}

function bi_resolve_participant_data_file($folderPath)
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

function bi_is_mentor_folder($folderPath, $resolvedPath = null)
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

function bi_build_participant_record($groupFolder, $folder, array $data, $analysisScope, $isMentor = false)
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

function bi_parse_rawatib_day(array $monthData, $day, $rawatibDetailCount)
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

function bi_analyze_month(array $monthData, $daysInMonth, array $daftar_amalan, $rawatibDetailCount)
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

function bi_scan_participants($analysisRoot, $analysisScope = 'group')
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

function bi_resolve_month($requestedMonth, array $availableMonths)
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

$availableMonths = [];
foreach ($rawParticipants as $participant) {
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
$analysisLabel = $analysisScope === 'all' ? 'SEMUA KELOMPOK' : $namaPengguna;

$groupSummaries = [];
$groupPalette = ['#7df0c5', '#8bc5ff', '#ffd27d', '#ff9f7d', '#9ee7d8', '#f6d97a'];

$participants = [];
$categoryTotals = [];
foreach (array_keys($daftar_amalan) as $categoryName) {
	$categoryTotals[$categoryName] = ['done' => 0, 'possible' => 0, 'percent' => 0];
}

foreach ($rawParticipants as $participant) {
	$monthData = $participant['data'][$selectedMonth] ?? [];
	if (!is_array($monthData)) {
		$monthData = [];
	}

	$metrics = bi_analyze_month($monthData, $daysInMonth, $daftar_amalan, $participant['rawatib_detail_count']);

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
	];

	foreach ($metrics['category_breakdown'] as $categoryName => $stats) {
		$categoryTotals[$categoryName]['done'] += $stats['done'];
		$categoryTotals[$categoryName]['possible'] += $stats['possible'];
	}
}

if ($analysisScope === 'all') {
	foreach ($participants as $participant) {
		$groupKey = $participant['group'] ?? 'unknown';
		if (!isset($groupSummaries[$groupKey])) {
			$groupSummaries[$groupKey] = [
				'group' => $groupKey,
				'label' => $participant['group_label'] ?? bi_format_group_name($groupKey),
				'url' => '/' . rawurlencode($groupKey) . '/',
				'members' => 0,
				'score_sum' => 0,
				'active_sum' => 0,
				'top_participant' => null,
				'average_percent' => 0,
				'average_active_days' => 0,
			];
		}

		$groupSummaries[$groupKey]['members']++;
		$groupSummaries[$groupKey]['score_sum'] += $participant['metrics']['overall_percent'];
		$groupSummaries[$groupKey]['active_sum'] += $participant['metrics']['active_days'];

		if ($groupSummaries[$groupKey]['top_participant'] === null || $participant['metrics']['overall_percent'] > $groupSummaries[$groupKey]['top_participant']['metrics']['overall_percent']) {
			$groupSummaries[$groupKey]['top_participant'] = $participant;
		}
	}

	$groupSummaries = array_values(array_map(function ($groupSummary) {
		$groupSummary['average_percent'] = $groupSummary['members'] > 0 ? round($groupSummary['score_sum'] / $groupSummary['members'], 1) : 0;
		$groupSummary['average_active_days'] = $groupSummary['members'] > 0 ? round($groupSummary['active_sum'] / $groupSummary['members'], 1) : 0;
		return $groupSummary;
	}, $groupSummaries));

	usort($groupSummaries, function ($a, $b) {
		if ($a['average_percent'] === $b['average_percent']) {
			if ($a['members'] === $b['members']) {
				return strcasecmp($a['label'], $b['label']);
			}

			return $b['members'] <=> $a['members'];
		}

		return $b['average_percent'] <=> $a['average_percent'];
	});
}

foreach ($categoryTotals as $categoryName => $stats) {
	$categoryTotals[$categoryName]['percent'] = $stats['possible'] > 0 ? round(($stats['done'] / $stats['possible']) * 100, 1) : 0;
}

usort($participants, function ($a, $b) {
	if ($a['metrics']['overall_percent'] === $b['metrics']['overall_percent']) {
		return $b['metrics']['active_days'] <=> $a['metrics']['active_days'];
	}

	return $b['metrics']['overall_percent'] <=> $a['metrics']['overall_percent'];
});

$topParticipant = $participants[0] ?? null;
$mostActiveParticipant = null;
foreach ($participants as $participant) {
	if ($mostActiveParticipant === null || $participant['metrics']['active_days'] > $mostActiveParticipant['metrics']['active_days']) {
		$mostActiveParticipant = $participant;
	}
}

$scoreSum = 0;
foreach ($participants as $participant) {
	$scoreSum += $participant['metrics']['overall_percent'];
}

$averageScore = !empty($participants) ? round($scoreSum / count($participants), 1) : 0;
$topGroupSummary = $groupSummaries[0] ?? null;
$groupCount = count($groupSummaries);
$skippedCount = count($skippedFolders);
$analysisModeLabel = $analysisScope === 'all' ? 'Lintas Grup' : 'Kelompok';
$analysisContextLabel = $analysisScope === 'all' ? 'seluruh kelompok di workspace ini' : basename($analysisRoot);
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
		}

		.stats-grid {
			display: grid;
			grid-template-columns: repeat(4, minmax(0, 1fr));
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
			min-width: 960px;
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
			height: 9px;
			border-radius: 999px;
			overflow: hidden;
			background: rgba(255, 255, 255, 0.08);
		}

		.bar span,
		.mini-bar span {
			display: block;
			height: 100%;
			border-radius: inherit;
			background: linear-gradient(90deg, var(--accent), var(--accent2));
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
		}

		.button:hover {
			background: #008585;
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
			min-width: 960px;
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

		.bar,
		.mini-bar {
			height: 9px;
			border-radius: 999px;
			overflow: hidden;
			background: var(--md-sys-color-surface-container-highest);
		}

		.bar span,
		.mini-bar span {
			display: block;
			height: 100%;
			border-radius: inherit;
			background: linear-gradient(90deg, var(--md-sys-color-primary), var(--md-sys-color-tertiary));
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
					<?php if ($analysisScope === 'all') : ?>
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
				</div>
			</section>

			<form class="filter-card" method="get">
				<div>
					<div class="filter-label">Pilih Bulan</div>
					<p>Pilih bulan yang ingin dilihat.</p>
				</div>
				<?php if ($analysisScope === 'all') : ?>
					<input type="hidden" name="scope" value="all">
				<?php endif; ?>
				<div>
					<select id="month" name="month" class="select">
						<?php foreach ($availableMonths as $monthOption) : ?>
							<option value="<?= bi_h($monthOption) ?>" <?= $monthOption === $selectedMonth ? 'selected' : '' ?>><?= bi_h(bi_month_label($monthOption)) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="submit" class="button">Tampilkan Analisis</button>
			</form>
		</header>

		<section class="stats-grid">
			<?php if ($analysisScope === 'all') : ?>
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
					<div class="summary-label">Rata-rata skor</div>
					<div class="summary-value"><?= bi_h(number_format($averageScore, 1, ',', '.')) ?>%</div>
					<div class="summary-note">Skor rata-rata seluruh mentee pada periode yang dipilih.</div>
				</article>
				<article class="summary-card">
					<div class="summary-label">Kelompok terkuat</div>
					<div class="summary-value"><?= bi_h(number_format($topGroupSummary['average_percent'] ?? 0, 1, ',', '.')) ?>%</div>
					<div class="summary-note"><?= bi_h($topGroupSummary['label'] ?? 'Belum ada data') ?> memimpin lintas grup.</div>
				</article>
			<?php else : ?>
				<article class="summary-card">
					<div class="summary-label">Mentee terdata</div>
					<div class="summary-value"><?= bi_h(count($participants)) ?></div>
					<div class="summary-note">Mentee yang dibandingkan.</div>
				</article>
				<article class="summary-card">
					<div class="summary-label">Rata-rata skor</div>
					<div class="summary-value"><?= bi_h(number_format($averageScore, 1, ',', '.')) ?>%</div>
					<div class="summary-note">Skor rata-rata seluruh mentee pada periode yang dipilih.</div>
				</article>
				<article class="summary-card">
					<div class="summary-label">Skor tertinggi</div>
					<div class="summary-value"><?= bi_h(number_format($topParticipant['metrics']['overall_percent'] ?? 0, 1, ',', '.')) ?>%</div>
					<div class="summary-note"><?= bi_h($topParticipant['label'] ?? 'Belum ada data') ?> memimpin bulan ini.</div>
				</article>
			<?php endif; ?>
		</section>

		<?php if ($analysisScope === 'all' && !empty($groupSummaries)) : ?>
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
							<div class="mini-value"><?= bi_h(number_format($groupSummary['average_percent'], 1, ',', '.')) ?>%</div>
							<div class="mini-sub"><?= bi_h($groupSummary['members']) ?> mentee • top <?= bi_h($groupSummary['top_participant']['full_label'] ?? '-') ?><?php if (!empty($groupSummary['top_participant']['mentor'])) : ?> <span class="mentor-chip">Mentor</span><?php endif; ?></div>
							<div class="mini-bar"><span style="width: <?= bi_h($groupSummary['average_percent']) ?>%;"></span></div>
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
						<h2><?= $analysisScope === 'all' ? 'Urutan Keterisian Lintas Grup' : 'Urutan Keterisian Bulan Ini' ?></h2>
						<p>
							<?php if ($analysisScope === 'all') : ?>
								Disusun dari skor tertinggi ke terendah untuk semua mentee pada periode <?= bi_h(bi_month_label($selectedMonth)) ?>.
							<?php else : ?>
								Disusun dari skor tertinggi ke terendah untuk periode <?= bi_h(bi_month_label($selectedMonth)) ?>.
							<?php endif; ?>
						</p>
					</div>
					<span class="tag">Aktif tertinggi: <?= bi_h($mostActiveParticipant['full_label'] ?? $mostActiveParticipant['label'] ?? '-') ?></span>
				</div>

				<?php if (!empty($participants)) : ?>
					<div class="table-wrap">
						<table>
							<thead>
								<tr>
									<th>#</th>
									<?php if ($analysisScope === 'all') : ?><th>Kelompok</th><?php endif; ?>
									<th>Mentee</th>
									<th>Skor</th>
									<th>Rawatib</th>
									<th>Istighfar</th>
									<th>Aktif</th>
									<th>Bulan Data</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($participants as $index => $participant) : ?>
									<tr>
										<td class="rank" data-label="Peringkat"><?= bi_h($index + 1) ?></td>
										<?php if ($analysisScope === 'all') : ?>
											<td data-label="Kelompok"><?= bi_h($participant['group_label']) ?></td>
										<?php endif; ?>
										<td data-label="Mentee">
											<?php if ($analysisScope === 'all') : ?>
												<span class="participant-link" aria-disabled="true">
													<strong><?= bi_h($participant['full_label'] ?? $participant['label']) ?><?php if (!empty($participant['mentor'])) : ?> <span class="mentor-chip">Mentor</span><?php endif; ?></strong>
													<small><?= bi_h($participant['months_tracked']) ?> bulan data tersimpan</small>
												</span>
											<?php else : ?>
												<a class="participant-link" href="<?= bi_h($participant['url']) ?>">
													<strong><?= bi_h($participant['full_label'] ?? $participant['label']) ?><?php if (!empty($participant['mentor'])) : ?> <span class="mentor-chip">Mentor</span><?php endif; ?></strong>
													<small><?= bi_h($participant['months_tracked']) ?> bulan data tersimpan</small>
												</a>
											<?php endif; ?>
										</td>
										<td data-label="Skor">
											<div class="score-box">
												<div class="score-top">
													<span class="score-value"><?= bi_h(number_format($participant['metrics']['overall_percent'], 1, ',', '.')) ?>%</span>
													<span class="muted"><?= bi_h($participant['metrics']['overall_done']) ?>/<?= bi_h($participant['metrics']['overall_possible']) ?></span>
												</div>
												<div class="bar"><span style="width: <?= bi_h($participant['metrics']['overall_percent']) ?>%;"></span></div>
											</div>
										</td>
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
						<div class="insight-label">Peringkat 1</div>
						<div class="insight-value"><?= bi_h($topParticipant['full_label'] ?? $topParticipant['label'] ?? '-') ?></div>
						<div class="insight-note"><?= bi_h(number_format($topParticipant['metrics']['overall_percent'] ?? 0, 1, ',', '.')) ?>% skor bulan ini.</div>
					</article>
					<article class="insight-card">
						<div class="insight-label">Paling aktif</div>
						<div class="insight-value"><?= bi_h($mostActiveParticipant['full_label'] ?? $mostActiveParticipant['label'] ?? '-') ?></div>
						<div class="insight-note"><?= bi_h($mostActiveParticipant['metrics']['active_days'] ?? 0) ?> hari terisi pada periode ini.</div>
					</article>
					<article class="insight-card">
						<div class="insight-label">Kategori terkuat</div>
						<div class="insight-value"><?= bi_h($bestCategory !== '' ? $bestCategory : '-') ?></div>
						<div class="insight-note"><?= bi_h(number_format($bestCategoryPercent, 1, ',', '.')) ?>% rata-rata kelompok.</div>
					</article>
					<?php if ($analysisScope === 'all') : ?>
						<article class="insight-card">
							<div class="insight-label">Kelompok terkuat</div>
							<div class="insight-value"><?= bi_h($topGroupSummary['label'] ?? '-') ?></div>
							<div class="insight-note"><?= bi_h(number_format($topGroupSummary['average_percent'] ?? 0, 1, ',', '.')) ?>% rata-rata grup.</div>
						</article>
					<?php endif; ?>
				</div>
			</aside>
		</section>

		<section class="panel" style="margin-top: 16px;">
			<div class="panel-head">
				<div>
					<div class="section-label">Kategori</div>
					<h2>Rata-rata Kinerja per Kategori</h2>
					<p>Gambaran kelompok untuk setiap kategori amalan pada periode yang sedang dipilih.</p>
				</div>
				<span class="tag"><?= bi_h(number_format($averageScore, 1, ',', '.')) ?>% rata-rata kelompok</span>
			</div>

			<div class="mini-grid">
				<?php foreach ($categoryTotals as $categoryName => $stats) : ?>
					<article class="mini-card" style="--accent: <?= bi_h($categoryColors[$categoryName] ?? '#7df0c5') ?>;">
						<div class="mini-label"><?= bi_h($categoryName) ?></div>
						<div class="mini-value"><?= bi_h(number_format($stats['percent'], 1, ',', '.')) ?>%</div>
						<div class="mini-sub"><?= bi_h($stats['done']) ?> dari <?= bi_h($stats['possible']) ?> poin tercatat</div>
						<div class="mini-bar"><span style="width: <?= bi_h($stats['percent']) ?>%;"></span></div>
					</article>
				<?php endforeach; ?>
			</div>

			<div class="footer-note">
				Data diambil dari folder <?= bi_h(basename($analysisRoot)) ?>.
			</div>
		</section>
	</div>
</body>
</html>
