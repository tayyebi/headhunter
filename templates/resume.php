<?php
/**
 * Resume template. Receives:
 *   $d     array  the edited resume content
 *   $dir   string 'rtl' or 'ltr'
 *   $fonts array  filename => path, empty when no local font was installed
 *
 * Rendered by Chromium inside Gotenberg, so modern CSS is fine. JavaScript is not:
 * the renderer runs with scripting disabled.
 */
$isRtl   = $dir === 'rtl';
$contact = $d['contact'] ?? [];

$contactBits = array_values(array_filter([
    $contact['email'] ?? '',
    $contact['phone'] ?? '',
    $contact['location'] ?? '',
]));
foreach (($contact['links'] ?? []) as $link) {
    $label = trim((string) ($link['label'] ?? ''));
    $url   = trim((string) ($link['url'] ?? ''));
    if ($url !== '' || $label !== '') {
        $contactBits[] = $label !== '' ? $label : $url;
    }
}

$sectionTitles = $isRtl
    ? ['summary' => 'خلاصه', 'experience' => 'سوابق شغلی', 'education' => 'تحصیلات',
       'skills' => 'مهارت‌ها', 'projects' => 'پروژه‌ها', 'certifications' => 'گواهی‌نامه‌ها',
       'languages' => 'زبان‌ها', 'present' => 'اکنون']
    : ['summary' => 'Summary', 'experience' => 'Experience', 'education' => 'Education',
       'skills' => 'Skills', 'projects' => 'Projects', 'certifications' => 'Certifications',
       'languages' => 'Languages', 'present' => 'Present'];

/** "start – end", tolerating either side being blank. */
$period = static function (array $row) use ($sectionTitles): string {
    $start = trim((string) ($row['start'] ?? ''));
    $end   = trim((string) ($row['end'] ?? ''));
    if ($start === '' && $end === '') {
        return '';
    }
    if ($start !== '' && $end === '') {
        $end = $sectionTitles['present'];
    }
    return trim($start . ' – ' . $end, ' –');
};
?>
<!doctype html>
<html lang="<?= $isRtl ? 'fa' : 'en' ?>" dir="<?= h($dir) ?>">
<head>
<meta charset="utf-8">
<title><?= h($d['full_name'] ?? 'Resume') ?></title>
<?php if ($fonts === []): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700&display=swap" rel="stylesheet">
<?php endif; ?>
<style>
<?php if ($fonts !== []): ?>
@font-face { font-family: 'Vazirmatn'; src: url('Vazirmatn-Regular.woff2') format('woff2'); font-weight: 400; font-display: block; }
@font-face { font-family: 'Vazirmatn'; src: url('Vazirmatn-Bold.woff2') format('woff2');    font-weight: 700; font-display: block; }
<?php endif; ?>

:root {
  --ink:      #16191d;
  --muted:    #5b636e;
  --hairline: #d7dbe0;
  --accent:   #1f3a5f;
}

* { box-sizing: border-box; }

html, body {
  margin: 0;
  padding: 0;
  color: var(--ink);
  background: #fff;
  font-family: <?= $isRtl ? "'Vazirmatn', Tahoma, sans-serif" : "'Inter', 'Helvetica Neue', Arial, sans-serif" ?>;
  font-size: 10.5pt;
  line-height: 1.6;
}

.page { padding: 0; }

header { border-bottom: 2px solid var(--accent); padding-bottom: 10px; margin-bottom: 18px; }
h1 { font-size: 20pt; font-weight: 700; margin: 0 0 2px; letter-spacing: <?= $isRtl ? '0' : '-0.01em' ?>; }
.headline { color: var(--accent); font-size: 11.5pt; font-weight: 600; margin: 0 0 6px; }
.contact { color: var(--muted); font-size: 9.5pt; }
.contact span:not(:last-child)::after { content: '·'; margin: 0 7px; color: var(--hairline); }

section { margin-bottom: 16px; break-inside: auto; }
h2 {
  font-size: 9.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--accent); margin: 0 0 8px; padding-bottom: 4px; border-bottom: 1px solid var(--hairline);
}
<?php if ($isRtl): ?>
h2 { text-transform: none; letter-spacing: 0; font-size: 11pt; }
<?php endif; ?>

.item { margin-bottom: 11px; break-inside: avoid; }
.item:last-child { margin-bottom: 0; }
.item-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
.item-title { font-weight: 700; }
.item-org { color: var(--accent); font-weight: 600; }
.item-meta { color: var(--muted); font-size: 9pt; white-space: nowrap; }

ul.bullets { margin: 4px 0 0; padding-<?= $isRtl ? 'right' : 'left' ?>: 16px; }
ul.bullets li { margin-bottom: 2px; }

.skill-row { margin-bottom: 4px; }
.skill-group { font-weight: 700; }
.inline-list span:not(:last-child)::after { content: '،'; margin-<?= $isRtl ? 'left' : 'right' ?>: 4px; }
<?php if (!$isRtl): ?>
.inline-list span:not(:last-child)::after { content: ','; }
<?php endif; ?>

.summary { margin: 0; text-align: justify; }
</style>
</head>
<body>
<div class="page">

  <header>
    <h1><?= h($d['full_name'] ?? '') ?></h1>
    <?php if (trim((string) ($d['headline'] ?? '')) !== ''): ?>
      <p class="headline"><?= h($d['headline']) ?></p>
    <?php endif; ?>
    <?php if ($contactBits !== []): ?>
      <div class="contact"><?php foreach ($contactBits as $bit): ?><span><?= h($bit) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
  </header>

  <?php if (trim((string) ($d['summary'] ?? '')) !== ''): ?>
  <section>
    <h2><?= h($sectionTitles['summary']) ?></h2>
    <p class="summary"><?= h($d['summary']) ?></p>
  </section>
  <?php endif; ?>

  <?php if (!empty($d['experience'])): ?>
  <section>
    <h2><?= h($sectionTitles['experience']) ?></h2>
    <?php foreach ($d['experience'] as $job): if (!is_array($job)) { continue; } ?>
      <div class="item">
        <div class="item-head">
          <div>
            <span class="item-title"><?= h($job['title'] ?? '') ?></span>
            <?php if (trim((string) ($job['company'] ?? '')) !== ''): ?>
              — <span class="item-org"><?= h($job['company']) ?></span>
            <?php endif; ?>
            <?php if (trim((string) ($job['location'] ?? '')) !== ''): ?>
              <span class="item-meta"><?= h($job['location']) ?></span>
            <?php endif; ?>
          </div>
          <div class="item-meta"><?= h($period($job)) ?></div>
        </div>
        <?php if (!empty($job['bullets']) && is_array($job['bullets'])): ?>
          <ul class="bullets">
            <?php foreach ($job['bullets'] as $bullet): if (trim((string) $bullet) === '') { continue; } ?>
              <li><?= h($bullet) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if (!empty($d['education'])): ?>
  <section>
    <h2><?= h($sectionTitles['education']) ?></h2>
    <?php foreach ($d['education'] as $edu): if (!is_array($edu)) { continue; } ?>
      <div class="item">
        <div class="item-head">
          <div>
            <span class="item-title"><?= h($edu['degree'] ?? '') ?></span>
            <?php if (trim((string) ($edu['institution'] ?? '')) !== ''): ?>
              — <span class="item-org"><?= h($edu['institution']) ?></span>
            <?php endif; ?>
          </div>
          <div class="item-meta"><?= h($period($edu)) ?></div>
        </div>
        <?php if (trim((string) ($edu['note'] ?? '')) !== ''): ?>
          <div><?= h($edu['note']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if (!empty($d['skills'])): ?>
  <section>
    <h2><?= h($sectionTitles['skills']) ?></h2>
    <?php foreach ($d['skills'] as $group): if (!is_array($group)) { continue; } ?>
      <div class="skill-row">
        <?php if (trim((string) ($group['group'] ?? '')) !== ''): ?>
          <span class="skill-group"><?= h($group['group']) ?>:</span>
        <?php endif; ?>
        <span class="inline-list"><?php foreach (($group['items'] ?? []) as $item): ?><span><?= h($item) ?></span><?php endforeach; ?></span>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if (!empty($d['projects'])): ?>
  <section>
    <h2><?= h($sectionTitles['projects']) ?></h2>
    <?php foreach ($d['projects'] as $project): if (!is_array($project)) { continue; } ?>
      <div class="item">
        <div class="item-title"><?= h($project['name'] ?? '') ?></div>
        <?php if (trim((string) ($project['description'] ?? '')) !== ''): ?>
          <div><?= h($project['description']) ?></div>
        <?php endif; ?>
        <?php if (trim((string) ($project['link'] ?? '')) !== ''): ?>
          <div class="item-meta"><?= h($project['link']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if (!empty($d['certifications'])): ?>
  <section>
    <h2><?= h($sectionTitles['certifications']) ?></h2>
    <?php foreach ($d['certifications'] as $cert): if (!is_array($cert)) { continue; } ?>
      <div class="item-head">
        <div>
          <span class="item-title"><?= h($cert['name'] ?? '') ?></span>
          <?php if (trim((string) ($cert['issuer'] ?? '')) !== ''): ?>
            — <span class="item-org"><?= h($cert['issuer']) ?></span>
          <?php endif; ?>
        </div>
        <div class="item-meta"><?= h($cert['year'] ?? '') ?></div>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if (!empty($d['languages'])): ?>
  <section>
    <h2><?= h($sectionTitles['languages']) ?></h2>
    <div class="inline-list">
      <?php foreach ($d['languages'] as $lang): if (!is_array($lang)) { continue; } ?>
        <span><?= h($lang['name'] ?? '') ?><?= trim((string) ($lang['level'] ?? '')) !== '' ? ' (' . h($lang['level']) . ')' : '' ?></span>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

</div>
</body>
</html>
