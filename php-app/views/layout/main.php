<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($page_title ?? 'DISC Assessment') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(asset_path('styles.css')) ?>">
</head>
<body>
  <?php require dirname(__DIR__) . '/' . $view . '.php'; ?>
  <script src="<?= h(asset_path('admin-table-toggle.js')) ?>" defer></script>
</body>
</html>
