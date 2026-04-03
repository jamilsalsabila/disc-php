<main class="test-page essay-page" data-deadline="<?= h($essay_deadline_at ?? '') ?>">
  <header class="test-header">
    <div>
      <p class="eyebrow">Kandidat: <?= h($candidate['full_name'] ?? '-') ?></p>
      <p class="subtitle">Tes Esai - Kelompok <?= h($essay_group ?? '-') ?></p>
    </div>
  </header>

  <div class="floating-timer">
    <span>Sisa Waktu Esai</span>
    <strong id="essay-countdown">15:00</strong>
    <small id="essay-autosave-status" class="autosave-status is-idle">Autosave aktif</small>
  </div>

  <form method="post" action="<?= h(route_path('/essay-submit')) ?>" id="essay-form">
    <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">

    <?php if (!empty($error_message)): ?>
      <div class="alert"><?= h($error_message) ?></div>
    <?php endif; ?>

    <?php if (!empty($essay_questions)): ?>
      <?php foreach ($essay_questions as $q): ?>
        <?php $qid = (int) ($q['id'] ?? 0); ?>
        <article class="question-card">
          <h3><?= h((string) ($q['order'] ?? 0)) ?>. <?= h((string) ($q['question_text'] ?? '')) ?></h3>
          <label style="margin-top:10px;">
            Jawaban Anda
            <textarea name="essay_<?= h((string) $qid) ?>" rows="5" required><?= h((string) ($draft_essay_answers[$qid] ?? '')) ?></textarea>
          </label>
        </article>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="alert alert-success">Saat ini belum ada soal esai aktif untuk kelompok role Anda. Klik submit untuk menyelesaikan asesmen.</div>
    <?php endif; ?>

    <div class="sticky-submit">
      <button type="submit" class="btn-primary">Kirim Jawaban Esai</button>
    </div>
  </form>
</main>
<script src="<?= h(asset_path('essay-timer.js')) ?>"></script>
