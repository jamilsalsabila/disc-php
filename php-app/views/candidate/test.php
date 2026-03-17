<main class="test-page" data-deadline="<?= h($deadline_at ?? '') ?>">
  <header class="test-header">
    <div>
      <p class="eyebrow">Kandidat: <?= h($candidate['full_name'] ?? '-') ?></p>
    </div>
  </header>

  <div class="floating-timer">
    <span>Sisa Waktu</span>
    <strong id="countdown">10:00</strong>
  </div>

  <form method="post" action="<?= h(route_path('/submit')) ?>" id="disc-form">
    <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">

    <?php if (!empty($error_message)): ?>
      <div class="alert"><?= h($error_message) ?></div>
    <?php endif; ?>

    <?php foreach (($questions ?? []) as $question): ?>
      <article class="question-card">
        <h3>
          <?= h((string) $question['order']) ?>. Pilih pernyataan Most dan Least
          <span class="question-status is-invalid" data-question-id="<?= h((string) $question['id']) ?>">Belum valid</span>
        </h3>

        <table class="ml-table">
          <thead>
            <tr>
              <th>Pernyataan</th>
              <th class="pick-col">Most</th>
              <th class="pick-col">Least</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($question['options'] as $option): ?>
              <tr>
                <td>
                  <span class="option-badge"><?= h($option['code']) ?></span>
                  <?= h($option['text']) ?>
                </td>
                <td class="pick-col">
                  <input type="radio" name="q_<?= h((string) $question['id']) ?>_most" value="<?= h($option['code']) ?>" required>
                </td>
                <td class="pick-col">
                  <input type="radio" name="q_<?= h((string) $question['id']) ?>_least" value="<?= h($option['code']) ?>" required>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </article>
    <?php endforeach; ?>

    <div class="sticky-submit">
      <button type="submit" class="btn-primary">Kirim Jawaban</button>
    </div>
  </form>
</main>
<script src="<?= h(asset_path('test-timer.js')) ?>"></script>
