<main class="profile-page">
  <header class="profile-header">
    <div>
      <p class="eyebrow">Candidate Profile</p>
      <h1><?= h($candidate['full_name']) ?></h1>
      <p class="subtitle">ID #<?= h((string) $candidate['id']) ?> - <?= h($candidate['email']) ?> - <?= h($candidate['whatsapp']) ?></p>
    </div>
    <div class="hr-actions">
      <button type="button" class="btn-secondary compact-toggle-btn" data-compact-toggle aria-pressed="false">Tabel: Normal</button>
      <a href="<?= h(route_path('/hr/dashboard')) ?>" class="btn-secondary">Kembali ke Dashboard</a>
      <a href="<?= h(route_path('/hr/candidates/' . $candidate['id'] . '/export/answers.csv')) ?>" class="btn-secondary">Download Jawaban CSV</a>
      <a href="<?= h(route_path('/hr/candidates/' . $candidate['id'] . '/export/answers.pdf')) ?>" class="btn-secondary">Download Jawaban PDF</a>
      <a href="<?= h(route_path('/hr/candidates/' . $candidate['id'] . '/export/answers.doc')) ?>" class="btn-secondary">Download Jawaban Word</a>
      <form method="post" action="<?= h(route_path('/hr/candidates/' . $candidate['id'] . '/delete')) ?>" class="inline-form" onsubmit="return confirm('Hapus hasil tes kandidat ini? Tindakan ini tidak bisa dibatalkan.');">
        <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <button type="submit" class="btn-danger-outline">Hapus Hasil Tes</button>
      </form>
      <form method="post" action="<?= h(route_path('/hr/logout')) ?>" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <button type="submit" class="btn-secondary">Logout</button>
      </form>
    </div>
  </header>

  <?php if (!empty($flash_message)): ?>
    <div class="alert <?= ($flash_type ?? 'info') === 'error' ? 'alert-danger' : 'alert-success' ?>">
      <?= h((string) $flash_message) ?>
    </div>
  <?php endif; ?>

  <section class="profile-card u-mb-14">
    <h3>Analisis AI (Deep Mode)</h3>
    <p class="subtitle">Gunakan analisis AI untuk konklusi cepat, saran posisi, dan follow-up wawancara.</p>
    <form method="post" action="<?= h(route_path('/hr/candidates/' . $candidate['id'] . '/ai-evaluate')) ?>" class="inline-form u-mt-10">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
      <button type="submit" class="btn-primary"><?= !empty($ai_evaluation) ? 'Regenerate Analisis AI' : 'Generate Analisis AI' ?></button>
    </form>

    <?php if (!empty($ai_evaluation)): ?>
      <div class="profile-ai-box">
        <p><strong>Status:</strong> <?= h((string) ($ai_evaluation['status'] ?? '-')) ?></p>
        <p><strong>Model:</strong> <?= h((string) ($ai_evaluation['model'] ?? '-')) ?></p>
        <p><strong>Skor AI (1-10):</strong> <?= h((string) ((int) ($ai_evaluation['score_1_10'] ?? 0))) ?></p>
        <p><strong>Saran Posisi:</strong> <?= h((string) ($ai_evaluation['suggested_position'] ?? '-')) ?></p>
        <p><strong>Konklusi:</strong> <?= h((string) ($ai_evaluation['conclusion'] ?? '-')) ?></p>
        <p><strong>Rationale:</strong> <?= h((string) ($ai_evaluation['rationale'] ?? '-')) ?></p>

        <?php $strengths = json_decode((string) ($ai_evaluation['strengths_json'] ?? '[]'), true) ?: []; ?>
        <?php $risks = json_decode((string) ($ai_evaluation['risks_json'] ?? '[]'), true) ?: []; ?>
        <?php $followUps = json_decode((string) ($ai_evaluation['follow_up_json'] ?? '[]'), true) ?: []; ?>

        <p><strong>Kekuatan (AI):</strong> <?= !empty($strengths) ? h(implode(' | ', array_map('strval', $strengths))) : '-' ?></p>
        <p><strong>Risiko (AI):</strong> <?= !empty($risks) ? h(implode(' | ', array_map('strval', $risks))) : '-' ?></p>
        <p><strong>Pertanyaan Follow-up (AI):</strong> <?= !empty($followUps) ? h(implode(' | ', array_map('strval', $followUps))) : '-' ?></p>
        <?php if (!empty($ai_evaluation['error_message'])): ?>
          <p><strong>Error:</strong> <?= h((string) $ai_evaluation['error_message']) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="profile-grid">
    <article class="profile-card profile-summary-card">
      <h3>Ringkasan Penilaian</h3>
      <p><strong>Role dipilih:</strong> <?= h($candidate['selected_role']) ?></p>
      <p><strong>Rekomendasi sistem:</strong> <?= h(map_recommendation_label($candidate['recommendation'])) ?></p>
      <p><strong>Kelayakan wawancara:</strong> <?= h($interview_recommendation ?? '-') ?></p>
      <p><strong>Indikasi integritas:</strong> <?= h((string) (($integrity_risk['level'] ?? 'Low'))) ?></p>
      <p><strong>Signal terdeteksi:</strong> Tab Switch <?= h((string) (($integrity_risk['tab_switches'] ?? 0))) ?>, Paste <?= h((string) (($integrity_risk['paste_count'] ?? 0))) ?></p>
      <p><strong>Typing pattern risk:</strong> <?= h((string) (($typing_risk['level'] ?? 'Low'))) ?> (score <?= h((string) (($typing_risk['score'] ?? 0))) ?>)</p>
      <p><strong>Focus-loss severity:</strong> <?= h((string) (($focus_loss_severity['level'] ?? 'Low'))) ?> (score <?= h((string) (($focus_loss_severity['score'] ?? 0))) ?>, tab <?= h((string) (($focus_loss_severity['tab_switches'] ?? 0))) ?>, per10m <?= h((string) (($focus_loss_severity['switch_per_10min'] ?? 0))) ?>)</p>
      <p><strong>Latency+paste anomaly:</strong> <?= h((string) (($latency_paste_anomaly['level'] ?? 'Low'))) ?> (score <?= h((string) (($latency_paste_anomaly['score'] ?? 0))) ?>, flagged <?= h((string) (($latency_paste_anomaly['flagged_rows'] ?? 0))) ?>)</p>
      <p><strong>Status:</strong> <?= h($candidate['status']) ?></p>
      <p><strong>Mulai tes:</strong> <?= h(format_date_id($candidate['started_at'])) ?></p>
      <p><strong>Selesai tes:</strong> <?= h(format_date_id($candidate['submitted_at'])) ?></p>
      <p><strong>Durasi:</strong> <?= h((string) ($candidate['duration_seconds'] ?? 0)) ?> detik</p>
      <p><strong>Jawaban DISC terisi:</strong> <?= h((string) ((int) ($disc_answered_count ?? 0))) ?>/<?= h((string) ((int) ($disc_total_count ?? 0))) ?></p>
      <p><strong>Jawaban esai terisi:</strong> <?= h((string) ((int) ($essay_answered_count ?? 0))) ?>/<?= h((string) ((int) ($essay_total_count ?? 0))) ?></p>
      <hr>
      <p><strong>Alasan rekomendasi:</strong></p>
      <p><?= h($candidate['reason'] ?? '-') ?></p>
    </article>

    <article class="profile-card profile-chart-card">
      <h3>Grafik DISC</h3>
      <p class="chart-note">Visualisasi profil perilaku kandidat pada dimensi D, I, S, C.</p>
      <div class="chart-shell chart-shell-profile-radar">
        <canvas id="discRadar"></canvas>
      </div>
      <h3>Skor Kecocokan Role</h3>
      <p class="chart-note">Perbandingan kecocokan terhadap role target dalam skala 1-10.</p>
      <div class="chart-shell chart-shell-profile-bar">
        <canvas id="roleBar"></canvas>
      </div>
    </article>
  </section>

  <section class="table-card answer-card">
    <h3>Jawaban DISC Kandidat</h3>
    <table class="admin-table answer-table profile-answer-table disc-answer-table">
      <thead>
        <tr>
          <th>No</th>
          <th>Most</th>
          <th>Least</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($question_rows as $row): ?>
          <tr>
            <td class="pa-col-no"><?= h((string) $row['id']) ?></td>
            <td class="pa-col-most"><span class="cell-clamp" title="<?= h($row['most']) ?>"><?= h($row['most']) ?></span></td>
            <td class="pa-col-least"><span class="cell-clamp" title="<?= h($row['least']) ?>"><?= h($row['least']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="table-card answer-card u-mt-12">
    <h3>Jawaban Esai Kandidat</h3>
    <table class="admin-table answer-table profile-answer-table essay-answer-table">
      <thead>
        <tr>
          <th class="pa-col-no">No</th>
          <th class="pa-col-question">Pertanyaan</th>
          <th class="pa-col-answer">Jawaban</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($essay_rows)): ?>
          <?php foreach ($essay_rows as $row): ?>
            <tr>
              <td class="pa-col-no"><?= h((string) ((int) ($row['question_order'] ?? 0))) ?></td>
              <?php $essayQuestionRaw = trim((string) ($row['question_text'] ?? '')); ?>
              <?php $essayQuestion = $essayQuestionRaw !== '' ? $essayQuestionRaw : '(Soal sudah tidak tersedia di bank soal)'; ?>
              <?php $essayAnswerRaw = trim((string) ($row['answer_text'] ?? '')); ?>
              <?php $essayAnswer = $essayAnswerRaw !== '' ? $essayAnswerRaw : ''; ?>
              <td class="pa-col-question"><span class="cell-clamp" title="<?= h($essayQuestion) ?>"><?= h($essayQuestion) ?></span></td>
              <td class="pa-col-answer"><span class="cell-wrap" title="<?= h($essayAnswer) ?>"><?= h($essayAnswer) ?></span></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="3">Belum ada jawaban esai.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="table-card answer-card u-mt-12">
    <h3>Event Timeline (Integritas)</h3>
    <table class="admin-table answer-table profile-answer-table event-answer-table">
      <thead>
        <tr>
          <th class="pa-col-time">Waktu</th>
          <th class="pa-col-phase">Fase</th>
          <th class="pa-col-event">Event</th>
          <th class="pa-col-value">Nilai</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($integrity_events)): ?>
          <?php foreach ($integrity_events as $ev): ?>
            <tr>
              <?php $timeLabel = (string) format_date_id((string) ($ev['created_at'] ?? '')); ?>
              <td class="pa-col-time">
                <span class="event-time cell-clamp" title="<?= h($timeLabel) ?>"><?= h($timeLabel) ?></span>
              </td>
              <?php $phaseLabel = (string) ($ev['phase_label'] ?? '-'); ?>
              <?php $eventLabel = (string) ($ev['event_type_label'] ?? '-'); ?>
              <?php $valueLabel = (string) ($ev['event_value_label'] ?? '-'); ?>
              <td class="pa-col-phase">
                <span class="event-text cell-clamp" title="<?= h($phaseLabel) ?>"><?= h($phaseLabel) ?></span>
              </td>
              <td class="pa-col-event">
                <span class="event-text cell-clamp" title="<?= h($eventLabel) ?>"><?= h($eventLabel) ?></span>
              </td>
              <td class="pa-col-value">
                <span class="event-text cell-clamp" title="<?= h($valueLabel) ?>"><?= h($valueLabel) ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="4">Belum ada event timeline.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="table-card answer-card u-mt-12">
    <details>
      <summary><strong>Event Timeline Lengkap (Snapshot Journey)</strong></summary>
      <table class="admin-table answer-table profile-answer-table event-answer-table u-mt-12">
        <thead>
          <tr>
            <th class="pa-col-time">Waktu</th>
            <th class="pa-col-phase">Fase</th>
            <th class="pa-col-event">Event</th>
            <th class="pa-col-value">Nilai</th>
            <th class="pa-col-value">Metadata Snapshot</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($journey_events)): ?>
            <?php foreach ($journey_events as $ev): ?>
              <tr>
                <?php $timeLabel = (string) format_date_id((string) ($ev['created_at'] ?? '')); ?>
                <td class="pa-col-time"><span class="event-time cell-clamp" title="<?= h($timeLabel) ?>"><?= h($timeLabel) ?></span></td>
                <td class="pa-col-phase"><span class="event-text cell-clamp" title="<?= h((string) ($ev['phase_label'] ?? '-')) ?>"><?= h((string) ($ev['phase_label'] ?? '-')) ?></span></td>
                <td class="pa-col-event"><span class="event-text cell-clamp" title="<?= h((string) ($ev['event_label'] ?? '-')) ?>"><?= h((string) ($ev['event_label'] ?? '-')) ?></span></td>
                <td class="pa-col-value"><span class="event-text cell-clamp" title="<?= h((string) ($ev['value_label'] ?? '-')) ?>"><?= h((string) ($ev['value_label'] ?? '-')) ?></span></td>
                <td class="pa-col-value"><span class="event-text cell-wrap" title="<?= h((string) ($ev['payload_text'] ?? '-')) ?>"><?= h((string) ($ev['payload_text'] ?? '-')) ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5">Belum ada event journey.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </details>
  </section>

  <section class="table-card answer-card u-mt-12">
    <h3>Typing Metrics (Esai)</h3>
    <table class="admin-table answer-table profile-answer-table typing-answer-table">
      <thead>
        <tr>
          <th class="pa-col-no">No</th>
          <th class="pa-col-num">Keystrokes</th>
          <th class="pa-col-num">Input Events</th>
          <th class="pa-col-num">Paste</th>
          <th class="pa-col-num">Chars</th>
          <th class="pa-col-active">Active (detik)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($typing_metrics_rows)): ?>
          <?php foreach ($typing_metrics_rows as $row): ?>
            <tr>
              <td class="pa-col-no"><?= h((string) ((int) ($row['question_order'] ?? 0))) ?></td>
              <td class="pa-col-num"><?= h((string) ((int) ($row['keystrokes'] ?? 0))) ?></td>
              <td class="pa-col-num"><?= h((string) ((int) ($row['input_events'] ?? 0))) ?></td>
              <td class="pa-col-num"><?= h((string) ((int) ($row['paste_count'] ?? 0))) ?></td>
              <td class="pa-col-num"><?= h((string) ((int) ($row['total_chars'] ?? 0))) ?></td>
              <td class="pa-col-active"><?= h((string) round(((int) ($row['active_ms'] ?? 0)) / 1000, 1)) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="6">Belum ada typing metrics.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="profile-card u-mb-14">
    <h3>Checklist Wawancara HR</h3>
    <p class="subtitle">Isi checklist ini saat interview tatap muka untuk verifikasi jawaban kandidat.</p>

    <form method="post" action="<?= h(route_path('/hr/candidates/' . $candidate['id'] . '/interview-checklist')) ?>" class="identity-form u-mt-12">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">

      <?php foreach (($interview_sections ?? []) as $sectionTitle => $items): ?>
        <div class="profile-interview-box">
          <p class="profile-interview-title"><?= h((string) $sectionTitle) ?></p>
          <div class="profile-interview-grid">
            <?php foreach ($items as $key => $label): ?>
              <label class="check-inline u-m-0">
                <input type="checkbox" name="<?= h((string) $key) ?>" value="1" <?= !empty($interview_saved_checklist[$key]) ? 'checked' : '' ?>>
                <?= h((string) $label) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <label>
        Keputusan Akhir HR
        <select name="final_decision">
          <option value="">Pilih keputusan</option>
          <?php foreach (['Lanjut User Interview', 'Lanjut Trial', 'Hold (Butuh Verifikasi)', 'Tidak Lanjut'] as $decision): ?>
            <option value="<?= h($decision) ?>" <?= (($interview_saved_final_decision ?? '') === $decision) ? 'selected' : '' ?>><?= h($decision) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Kekuatan Utama Kandidat
        <textarea name="strengths_notes" rows="3" placeholder="Contoh: Komunikasi jelas, cepat adaptasi, mampu memimpin shift."><?= h((string) ($interview_saved_strengths_notes ?? '')) ?></textarea>
      </label>

      <label>
        Area Risiko Utama
        <textarea name="risk_notes" rows="3" placeholder="Contoh: Ketelitian SOP belum stabil saat tekanan tinggi."><?= h((string) ($interview_saved_risk_notes ?? '')) ?></textarea>
      </label>

      <label>
        Saran Penempatan Posisi
        <textarea name="placement_notes" rows="3" placeholder="Contoh: Cocok untuk Floor Captain, alternatif Server."><?= h((string) ($interview_saved_placement_notes ?? '')) ?></textarea>
      </label>

      <div class="hr-actions">
        <button type="submit" class="btn-primary">Simpan Checklist</button>
      </div>
    </form>
  </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  window.discData = <?= json_encode($disc_data, JSON_UNESCAPED_UNICODE) ?>;
  window.roleScoreData = <?= json_encode($role_score_data, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= h(asset_path('candidate-profile.js')) ?>"></script>
