<?php
date_default_timezone_set('Asia/Jakarta');

if (!isset($analysisRoot) || !is_string($analysisRoot) || trim($analysisRoot) === '') {
	$callerFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
	$analysisRoot = $callerFile !== '' ? dirname($callerFile) : dirname(__DIR__);
}

$analysisRoot = realpath($analysisRoot) ?: $analysisRoot;
$folder_name = $folder_name ?? basename($analysisRoot);
$namaPengguna = $namaPengguna ?? strtoupper(str_replace(['_', '-'], ' ', $folder_name));
$manifestPath = $manifestPath ?? '';

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

function bi_scan_participants($analysisRoot)
{
	$participants = [];
	$skippedFolders = [];

	if (!is_dir($analysisRoot)) {
		return [$participants, $skippedFolders];
	}

	$iterator = new DirectoryIterator($analysisRoot);
	foreach ($iterator as $item) {
		if ($item->isDot() || !$item->isDir()) {
			continue;
		}

		$folder = $item->getFilename();
		$dataFile = $item->getPathname() . DIRECTORY_SEPARATOR . 'data_amalan.json';

		if (!is_file($dataFile)) {
			$skippedFolders[] = $folder;
			continue;
		}

		$data = bi_read_json($dataFile);
		$participants[] = [
			'folder' => $folder,
			'label' => ucwords(str_replace(['_', '-'], ' ', $folder)),
			'url' => rawurlencode($folder) . '/',
			'data' => $data,
			'months' => bi_available_months($data),
			'rawatib_detail_count' => bi_rawatib_detail_count($data),
		];
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

list($rawParticipants, $skippedFolders) = bi_scan_participants($analysisRoot);

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
$analysisLabel = $namaPengguna;

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
		'folder' => $participant['folder'],
		'label' => $participant['label'],
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
$skippedCount = count($skippedFolders);
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
	<link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
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
		}

		.participant-link small,
		.muted {
			color: var(--muted);
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
</head>
<body>
	<div class="shell">
		<header class="hero">
			<section>
				<div class="kicker">Business Intelligence</div>
				<h1>Analisis Mentee <?= bi_h($analysisLabel) ?></h1>
				<p class="subtitle">Dashboard ini membandingkan file data_amalan.json antar folder pribadi di bawah <?= bi_h(basename($analysisRoot)) ?>. Fokusnya adalah skor keterisian bulan <?= bi_h(bi_month_label($selectedMonth)) ?>, ditambah ringkasan kategori yang paling kuat dan folder yang belum punya data lokal.</p>

				<div class="meta">
					<span class="pill">Folder: <?= bi_h(basename($analysisRoot)) ?></span>
					<span class="pill">Periode: <?= bi_h(bi_month_label($selectedMonth)) ?></span>
					<span class="pill"><?= bi_h(count($participants)) ?> mentee terdata</span>
				</div>
			</section>

			<form class="filter-card" method="get">
				<div>
					<div class="filter-label">Pilih Bulan</div>
					<p>Gunakan filter ini untuk melihat periode lain yang tersedia di folder ini.</p>
				</div>
				<div>
					<select id="month" name="month" class="select">
						<?php foreach ($availableMonths as $monthOption) : ?>
							<option value="<?= bi_h($monthOption) ?>" <?= $monthOption === $selectedMonth ? 'selected' : '' ?>><?= bi_h(bi_month_label($monthOption)) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="submit" class="button">Tampilkan Analisis</button>
				<p><?= bi_h($skippedCount) ?> folder tanpa data lokal dilewati dari ranking.</p>
			</form>
		</header>

		<section class="stats-grid">
			<article class="summary-card">
				<div class="summary-label">Mentee terdata</div>
				<div class="summary-value"><?= bi_h(count($participants)) ?></div>
				<div class="summary-note">Hanya folder yang memiliki data_amalan.json yang dihitung.</div>
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
			<article class="summary-card">
				<div class="summary-label">Folder diabaikan</div>
				<div class="summary-value"><?= bi_h($skippedCount) ?></div>
				<div class="summary-note">Folder redirect atau folder tanpa data tidak dimasukkan ke analisis.</div>
			</article>
		</section>

		<section class="grid-two">
			<div class="panel">
				<div class="panel-head">
					<div>
						<div class="section-label">Ranking</div>
						<h2>Urutan Keterisian Bulan Ini</h2>
						<p>Disusun dari skor tertinggi ke terendah untuk periode <?= bi_h(bi_month_label($selectedMonth)) ?>.</p>
					</div>
					<span class="tag">Aktif tertinggi: <?= bi_h($mostActiveParticipant['label'] ?? '-') ?></span>
				</div>

				<?php if (!empty($participants)) : ?>
					<div class="table-wrap">
						<table>
							<thead>
								<tr>
									<th>#</th>
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
										<td class="rank"><?= bi_h($index + 1) ?></td>
										<td>
											<a class="participant-link" href="<?= bi_h($participant['url']) ?>">
												<strong><?= bi_h($participant['label']) ?></strong>
												<small><?= bi_h($participant['months_tracked']) ?> bulan data tersimpan</small>
											</a>
										</td>
										<td>
											<div class="score-box">
												<div class="score-top">
													<span class="score-value"><?= bi_h(number_format($participant['metrics']['overall_percent'], 1, ',', '.')) ?>%</span>
													<span class="muted"><?= bi_h($participant['metrics']['overall_done']) ?>/<?= bi_h($participant['metrics']['overall_possible']) ?></span>
												</div>
												<div class="bar"><span style="width: <?= bi_h($participant['metrics']['overall_percent']) ?>%;"></span></div>
											</div>
										</td>
										<td><?= bi_h(number_format($participant['metrics']['rawatib_percent'], 1, ',', '.')) ?>%</td>
										<td><?= bi_h(number_format($participant['metrics']['istighfar_average'], 1, ',', '.')) ?></td>
										<td><?= bi_h($participant['metrics']['active_days']) ?>/<?= bi_h($daysInMonth) ?></td>
										<td><?= bi_h(bi_month_label($participant['latest_month'] ?? $selectedMonth)) ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<div class="empty-state">Belum ada file <strong>data_amalan.json</strong> di folder anak yang bisa dianalisis. Tambahkan data ke salah satu folder personal lalu muat ulang halaman ini.</div>
				<?php endif; ?>
			</div>

			<aside class="panel">
				<div class="panel-head">
					<div>
						<div class="section-label">Insight</div>
						<h2>Ringkasan Cepat</h2>
						<p>Potongan data yang membantu membaca kondisi kelompok tanpa perlu menelusuri tabel penuh.</p>
					</div>
				</div>

				<div class="insight-list">
					<article class="insight-card">
						<div class="insight-label">Peringkat 1</div>
						<div class="insight-value"><?= bi_h($topParticipant['label'] ?? '-') ?></div>
						<div class="insight-note"><?= bi_h(number_format($topParticipant['metrics']['overall_percent'] ?? 0, 1, ',', '.')) ?>% skor bulan ini.</div>
					</article>
					<article class="insight-card">
						<div class="insight-label">Paling aktif</div>
						<div class="insight-value"><?= bi_h($mostActiveParticipant['label'] ?? '-') ?></div>
						<div class="insight-note"><?= bi_h($mostActiveParticipant['metrics']['active_days'] ?? 0) ?> hari terisi pada periode ini.</div>
					</article>
					<article class="insight-card">
						<div class="insight-label">Kategori terkuat</div>
						<div class="insight-value"><?= bi_h($bestCategory !== '' ? $bestCategory : '-') ?></div>
						<div class="insight-note"><?= bi_h(number_format($bestCategoryPercent, 1, ',', '.')) ?>% rata-rata kelompok.</div>
					</article>
					<article class="insight-card">
						<div class="insight-label">Folder diabaikan</div>
						<div class="insight-value"><?= bi_h($skippedCount) ?></div>
						<div class="insight-note">Folder redirect atau folder tanpa data lokal tidak dihitung.</div>
					</article>
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
				Data diambil dari folder <?= bi_h(basename($analysisRoot)) ?> pada bulan <?= bi_h(bi_month_label($selectedMonth)) ?>. Folder yang hanya berisi redirect atau tidak memiliki <strong>data_amalan.json</strong> tidak ikut dihitung.
			</div>
		</section>
	</div>
</body>
</html>
