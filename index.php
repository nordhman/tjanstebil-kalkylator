<?php
declare(strict_types=1);

/**
 * START BROWSER
 * $ php -S 0.0.0.0:8000 -t . AAAA
 * 
 * COMMIT AND PUSCH
 * git status git add index.php git commit -m "Add initial calculator" git push
 * 
 * Tjänstebilskalkylator (v0.1)
 * - Single-file index.php
 * - Tailwind via CDN
 * - Skatt: förenklad modell (kommun+region + statlig 20% över skiktgräns 2026)
 * - Pension-indikator: PGI-tak 2026
 *
 * DISCLAIMER: Skatt blir ungefärlig (ingen exakt skattetabell, jobbskatteavdrag,
 * grundavdrag, kyrkoavgift, jämkning, avdrag etc).
 */

// 2026-parametrar (källor i chatten)
const PBB_2026 = 59200;                 // Prisbasbelopp 2026
const STATE_TAX_THRESHOLD_2026 = 643000; // Skiktgräns 2026 (beskattningsbar förvärvsinkomst efter grundavdrag)
const STATE_TAX_RATE = 0.20;            // Statlig skatt: 20% över skiktgräns (2026)
const PGI_MONTHLY_CAP_2026 = 52125;     // "Högsta pensionsgrundande inkomsten" per månad (indikator)
const DEFAULT_GROSS_DEDUCTION_RATE = 0.0195; // 1,95%

// Minimal kommunlista (summa kommun+region exkl kyrkoavgift). Utökar vi med full lista via JSON sen.
$kommunSkatt = [
  'Håbo' => 0.3393,              // Exempel (du nämnde 33,93%)
  'Stockholm' => 0.2978,
  'Göteborg' => 0.3200,
  'Malmö' => 0.3280,
  'Uppsala' => 0.3290,
];

function money(float $v): string {
  return number_format($v, 0, ' ', ' ') . ' kr';
}
function pct(float $v): string {
  return number_format($v * 100, 2, ',', ' ') . ' %';
}

$input = [
  'salary' => $_POST['salary'] ?? '',
  'kommun' => $_POST['kommun'] ?? 'Håbo',
  'tax_rate_manual' => $_POST['tax_rate_manual'] ?? '',
  'car_price' => $_POST['car_price'] ?? '',
  'benefit' => $_POST['benefit'] ?? '',
  'deduction_rate' => $_POST['deduction_rate'] ?? (string)(DEFAULT_GROSS_DEDUCTION_RATE * 100),
  'age_group' => $_POST['age_group'] ?? 'under66',
];

$calc = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $salary = (float)str_replace([' ', ','], ['', '.'], (string)$input['salary']);
  $carPrice = (float)str_replace([' ', ','], ['', '.'], (string)$input['car_price']);
  $benefit = (float)str_replace([' ', ','], ['', '.'], (string)$input['benefit']);
  $deductionRatePct = (float)str_replace([' ', ','], ['', '.'], (string)$input['deduction_rate']);
  $deductionRate = $deductionRatePct / 100.0;

  if ($salary <= 0) $errors[] = 'Månadslön måste vara > 0.';
  if ($carPrice <= 0) $errors[] = 'Bilpris måste vara > 0.';
  if ($benefit < 0) $errors[] = 'Förmånsvärde kan inte vara negativt.';
  if ($deductionRate <= 0 || $deductionRate > 0.10) $errors[] = 'Procentsats för bruttolöneavdrag verkar orimlig (0–10%).';

  // Skattesats
  $selectedKommun = (string)$input['kommun'];
  $taxRate = $kommunSkatt[$selectedKommun] ?? null;

  $manual = trim((string)$input['tax_rate_manual']);
  if ($taxRate === null) {
    if ($manual === '') {
      $errors[] = 'Kommun saknas i listan. Ange skattesats manuellt (t.ex. 33,93).';
    } else {
      $manualPct = (float)str_replace([' ', ','], ['', '.'], $manual);
      $taxRate = $manualPct / 100.0;
    }
  } else {
    // Om användaren fyllt i manuellt: respektera den (för test)
    if ($manual !== '') {
      $manualPct = (float)str_replace([' ', ','], ['', '.'], $manual);
      $taxRate = $manualPct / 100.0;
    }
  }

  if (empty($errors)) {
    $grossDeduction = $carPrice * $deductionRate; // bruttolöneavdrag/mån

    // Beskattningsbar lön (förenklad modell)
    $taxableMonthly = $salary - $grossDeduction + $benefit;
    if ($taxableMonthly < 0) $taxableMonthly = 0;

    // Kommun+region skatt (förenklat)
    $municipalTaxMonthly = $taxableMonthly * $taxRate;

    // Statlig skatt (förenklad):
    // Skiktgränsen gäller "beskattningsbar förvärvsinkomst efter grundavdrag".
    // Vi använder taxableMonthly*12 som approximation => något överskattande.
    $taxableAnnualApprox = $taxableMonthly * 12.0;
    $stateTaxAnnual = 0.0;
    if ($taxableAnnualApprox > STATE_TAX_THRESHOLD_2026) {
      $stateTaxAnnual = ($taxableAnnualApprox - STATE_TAX_THRESHOLD_2026) * STATE_TAX_RATE;
    }
    $stateTaxMonthly = $stateTaxAnnual / 12.0;

    $totalTaxMonthly = $municipalTaxMonthly + $stateTaxMonthly;

    // Utbetalt på konto (förenklad):
    // Lön utbetalas från bruttolön minus preliminär skatt.
    // Bruttolöneavdraget har redan sänkt beskattningsbar lön (och därmed skatt),
    // men i praktiken syns det som en “bruttolöneväxling”. Vi visar effekten via lägre skatt.
    $netOnAccount = $salary - $totalTaxMonthly;

    // Pension-indikator (allmän pension):
    // Vi jämför "lön efter bruttolöneavdrag" mot PGI-tak (indikativt)
    $pensionBaseMonthly = $salary - $grossDeduction;
    $affectsPension = $pensionBaseMonthly < PGI_MONTHLY_CAP_2026;

    // Policy-info (max bilpris)
    $maxNormal = 7.5 * PBB_2026;
    $maxPhev = 10.0 * PBB_2026;

    $calc = [
      'grossDeduction' => $grossDeduction,
      'taxableMonthly' => $taxableMonthly,
      'municipalTaxMonthly' => $municipalTaxMonthly,
      'stateTaxMonthly' => $stateTaxMonthly,
      'totalTaxMonthly' => $totalTaxMonthly,
      'netOnAccount' => $netOnAccount,
      'netOnAccountAnnual' => $netOnAccount * 12.0,
      'taxRate' => $taxRate,
      'pensionBaseMonthly' => $pensionBaseMonthly,
      'affectsPension' => $affectsPension,
      'maxNormal' => $maxNormal,
      'maxPhev' => $maxPhev,
    ];
  }
}

?>
<!doctype html>
<html lang="sv">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Tjänstebilskalkylator (Consid)</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
  <div class="max-w-6xl mx-auto px-4 py-10">
    <header class="mb-8">
      <h1 class="text-3xl font-bold tracking-tight">Tjänstebilskalkylator (Consid)</h1>
      <p class="text-slate-600 mt-2">
        Räkna ungefärlig nettolön med bruttolöneavdrag + bilförmån. (Skatt är en uppskattning.)
      </p>
      <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl bg-white p-4 shadow-sm border border-slate-200">
          <div class="text-sm text-slate-500">Prisbasbelopp 2026</div>
          <div class="text-xl font-semibold"><?= money(PBB_2026) ?></div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm border border-slate-200">
          <div class="text-sm text-slate-500">Max bilpris (vanlig)</div>
          <div class="text-xl font-semibold"><?= money(7.5 * PBB_2026) ?></div>
          <div class="text-xs text-slate-500 mt-1">7,5 × PBB</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm border border-slate-200">
          <div class="text-sm text-slate-500">Max bilpris (el/laddhybrid)</div>
          <div class="text-xl font-semibold"><?= money(10 * PBB_2026) ?></div>
          <div class="text-xs text-slate-500 mt-1">10 × PBB</div>
        </div>
      </div>
    </header>

    <main class="grid gap-6 lg:grid-cols-2">
      <!-- INPUT -->
      <section class="rounded-2xl bg-white p-6 shadow-sm border border-slate-200">
        <h2 class="text-xl font-semibold mb-4">Inmatning</h2>

        <?php if (!empty($errors)): ?>
          <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-4">
            <div class="font-semibold text-red-700 mb-1">Kolla detta:</div>
            <ul class="list-disc ml-5 text-red-700 text-sm">
              <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
          <div>
            <label class="block text-sm font-medium">Månadslön (brutto)</label>
            <input name="salary" value="<?= htmlspecialchars((string)$input['salary']) ?>" inputmode="decimal"
                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-400"
                   placeholder="t.ex. 67000" />
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium">Kommun</label>
              <select name="kommun"
                      class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-slate-400">
                <?php foreach ($kommunSkatt as $k => $_r): ?>
                  <option value="<?= htmlspecialchars($k) ?>" <?= $input['kommun'] === $k ? 'selected' : '' ?>>
                    <?= htmlspecialchars($k) ?>
                  </option>
                <?php endforeach; ?>
                <option value="ANNAN" <?= !isset($kommunSkatt[$input['kommun']]) ? 'selected' : '' ?>>Annan (ange manuellt)</option>
              </select>
              <p class="text-xs text-slate-500 mt-1">Summa kommun+region (exkl kyrkoavgift). Full kommunlista lägger vi in som JSON i nästa version.</p>
            </div>

            <div>
              <label class="block text-sm font-medium">Skattesats manuellt (valfritt)</label>
              <input name="tax_rate_manual" value="<?= htmlspecialchars((string)$input['tax_rate_manual']) ?>" inputmode="decimal"
                     class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-400"
                     placeholder="t.ex. 33,93" />
              <p class="text-xs text-slate-500 mt-1">Lämna tomt om kommun finns i listan.</p>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium">Bilpris inkl. moms</label>
              <input name="car_price" value="<?= htmlspecialchars((string)$input['car_price']) ?>" inputmode="decimal"
                     class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-400"
                     placeholder="t.ex. 592000" />
            </div>
            <div>
              <label class="block text-sm font-medium">Förmånsvärde / månad</label>
              <input name="benefit" value="<?= htmlspecialchars((string)$input['benefit']) ?>" inputmode="decimal"
                     class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-400"
                     placeholder="t.ex. 4000" />
            </div>
          </div>

          <details class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <summary class="cursor-pointer font-medium">Avancerat</summary>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <div>
                <label class="block text-sm font-medium">Bruttolöneavdrag (%)</label>
                <input name="deduction_rate" value="<?= htmlspecialchars((string)$input['deduction_rate']) ?>" inputmode="decimal"
                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-400"
                       placeholder="1,95" />
                <p class="text-xs text-slate-500 mt-1">Default 1,95% enligt policyn.</p>
              </div>
              <div>
                <label class="block text-sm font-medium">Ålder vid årets ingång</label>
                <select name="age_group"
                        class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-slate-400">
                  <option value="under66" <?= $input['age_group']==='under66'?'selected':'' ?>>Under 66</option>
                  <option value="66plus" <?= $input['age_group']==='66plus'?'selected':'' ?>>66 eller äldre</option>
                </select>
                <p class="text-xs text-slate-500 mt-1">Just nu påverkar detta bara info (vi kan bygga ut med exakta regler senare).</p>
              </div>
            </div>
          </details>

          <button class="w-full rounded-xl bg-slate-900 text-white py-3 font-semibold hover:bg-slate-800">
            Beräkna
          </button>

          <div class="text-xs text-slate-500">
            Obs: lösa tillbehör (takbox, takräcke, barnstol) betalas privat enligt policy.
          </div>
        </form>
      </section>

      <!-- RESULT -->
      <section class="rounded-2xl bg-white p-6 shadow-sm border border-slate-200">
        <h2 class="text-xl font-semibold mb-4">Resultat</h2>

        <?php if ($calc === null): ?>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-6 text-slate-600">
            Fyll i uppgifterna och klicka <span class="font-semibold">Beräkna</span>.
          </div>
        <?php else: ?>
          <div class="grid gap-4">
            <div class="rounded-2xl bg-slate-900 text-white p-5">
              <div class="text-sm opacity-80">Utbetalt på konto (mån)</div>
              <div class="text-3xl font-bold mt-1"><?= money($calc['netOnAccount']) ?></div>
              <div class="text-sm opacity-80 mt-3">Utbetalt på konto (år)</div>
              <div class="text-xl font-semibold"><?= money($calc['netOnAccountAnnual']) ?></div>
            </div>

            <div class="rounded-2xl border border-slate-200 p-5">
              <div class="font-semibold mb-3">Breakdown</div>
              <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span>Bruttolöneavdrag / mån</span><span class="font-semibold"><?= money($calc['grossDeduction']) ?></span></div>
                <div class="flex justify-between"><span>Förmånsvärde / mån</span><span class="font-semibold"><?= money((float)str_replace([' ', ','], ['', '.'], (string)$input['benefit'])) ?></span></div>
                <div class="flex justify-between"><span>Beskattningsbar lön / mån</span><span class="font-semibold"><?= money($calc['taxableMonthly']) ?></span></div>
                <div class="flex justify-between"><span>Kommun + region-skatt (≈)</span><span class="font-semibold"><?= money($calc['municipalTaxMonthly']) ?></span></div>
                <div class="flex justify-between"><span>Statlig skatt (≈)</span><span class="font-semibold"><?= money($calc['stateTaxMonthly']) ?></span></div>
                <div class="pt-2 border-t border-slate-200 flex justify-between"><span>Preliminär skatt totalt (≈)</span><span class="font-semibold"><?= money($calc['totalTaxMonthly']) ?></span></div>
                <div class="text-xs text-slate-500 mt-3">
                  Skatt är en uppskattning (vi använder inte full skattetabell, jobbskatteavdrag/grundavdrag m.m.).
                </div>
              </div>
            </div>

            <div class="rounded-2xl border border-slate-200 p-5">
              <div class="font-semibold mb-2">Pension</div>
              <?php if ($calc['affectsPension']): ?>
                <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm">
                  <div class="font-semibold text-amber-800">Allmän pension kan påverkas</div>
                  <div class="text-amber-800 mt-1">
                    Din lön efter bruttolöneavdrag (≈ <?= money($calc['pensionBaseMonthly']) ?>) ligger under PGI-tak-indikatorn
                    (<?= money(PGI_MONTHLY_CAP_2026) ?>/mån).
                  </div>
                </div>
              <?php else: ?>
                <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-4 text-sm">
                  <div class="font-semibold text-emerald-800">Allmän pension påverkas inte (indikator)</div>
                  <div class="text-emerald-800 mt-1">
                    Din lön efter bruttolöneavdrag (≈ <?= money($calc['pensionBaseMonthly']) ?>) ligger över PGI-tak-indikatorn
                    (<?= money(PGI_MONTHLY_CAP_2026) ?>/mån).
                  </div>
                </div>
              <?php endif; ?>

              <div class="text-xs text-slate-500 mt-3">
                Notis: Tjänstepension kan påverkas beroende på ditt avtal (du kan ha fast premie).
              </div>
            </div>

            <details class="rounded-2xl border border-slate-200 p-5">
              <summary class="cursor-pointer font-semibold">Detaljer & antaganden</summary>
              <div class="mt-3 text-sm text-slate-700 space-y-2">
                <div><span class="font-semibold">Skattesats använd:</span> <?= pct($calc['taxRate']) ?></div>
                <div><span class="font-semibold">Skiktgräns statlig skatt (2026):</span> <?= money(STATE_TAX_THRESHOLD_2026) ?> / år</div>
                <div class="text-xs text-slate-500">
                  Statlig skatt beräknas på beskattningsbar inkomst efter grundavdrag. Vi använder en förenklad approximation (taxableMonthly*12),
                  så värdet kan bli något högre än verkligheten.
                </div>
              </div>
            </details>
          </div>
        <?php endif; ?>
      </section>
    </main>

    <footer class="mt-10 text-xs text-slate-500">
      v0.1 – byggd för snabbtest i GitHub Codespaces.
    </footer>
  </div>
</body>
</html>

