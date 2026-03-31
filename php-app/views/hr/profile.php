<main class="profile-page">
  <header class="profile-header">
    <div>
      <p class="eyebrow">Candidate Profile</p>
      <h1><?= h($candidate['full_name']) ?></h1>
      <p class="subtitle">ID #<?= h((string) $candidate['id']) ?> - <?= h($candidate['email']) ?> - <?= h($candidate['whatsapp']) ?></p>
    </div>
    <div class="hr-actions">
      <a href="<?= h(route_path('/hr/dashboard')) ?>" class="btn-secondary">Kembali ke Dashboard</a>
      <a href="<?= h(route_path('/hr/candidates/' . $candidate['id'] . '/export/answers.csv')) ?>" class="btn-secondary">Download Jawaban CSV</a>
      <a href="<?= h(route_path('/hr/candidates/' . $candidate['id'] . '/export/answers.pdf')) ?>" class="btn-secondary">Download Jawaban PDF</a>
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

  <section class="profile-grid">
    <article class="profile-card profile-summary-card">
      <h3>Ringkasan Penilaian</h3>
      <p><strong>Role dipilih:</strong> <?= h($candidate['selected_role']) ?></p>
      <p><strong>Rekomendasi sistem:</strong> <?= h(map_recommendation_label($candidate['recommendation'])) ?></p>
      <p><strong>Kelayakan wawancara:</strong> <?= h($interview_recommendation ?? '-') ?></p>
      <p><strong>Indikasi integritas:</strong> <?= h((string) (($integrity_risk['level'] ?? 'Low'))) ?></p>
      <p><strong>Signal terdeteksi:</strong> Tab Switch <?= h((string) (($integrity_risk['tab_switches'] ?? 0))) ?>, Paste <?= h((string) (($integrity_risk['paste_count'] ?? 0))) ?></p>
      <p><strong>Typing pattern risk:</strong> <?= h((string) (($typing_risk['level'] ?? 'Low'))) ?> (score <?= h((string) (($typing_risk['score'] ?? 0))) ?>)</p>
      <p><strong>Status:</strong> <?= h($candidate['status']) ?></p>
      <p><strong>Mulai tes:</strong> <?= h(format_date_id($candidate['started_at'])) ?></p>
      <p><strong>Selesai tes:</strong> <?= h(format_date_id($candidate['submitted_at'])) ?></p>
      <p><strong>Durasi:</strong> <?= h((string) ($candidate['duration_seconds'] ?? 0)) ?> detik</p>
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
    <table class="answer-table">
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
            <td><?= h((string) $row['id']) ?></td>
            <td><?= h($row['most']) ?></td>
            <td><?= h($row['least']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="table-card answer-card" style="margin-top:12px;">
    <h3>Jawaban Esai Kandidat</h3>
    <table class="answer-table">
      <thead>
        <tr>
          <th>No</th>
          <th>Pertanyaan</th>
          <th>Jawaban</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($essay_rows)): ?>
          <?php foreach ($essay_rows as $row): ?>
            <tr>
              <td><?= h((string) ((int) ($row['question_order'] ?? 0))) ?></td>
              <td><?= h((string) ($row['question_text'] ?? '-')) ?></td>
              <td style="text-align:left; white-space:pre-wrap;"><?= h((string) ($row['answer_text'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="3">Belum ada jawaban esai.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="table-card answer-card" style="margin-top:12px;">
    <h3>Event Timeline (Integritas)</h3>
    <table class="answer-table">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>Fase</th>
          <th>Event</th>
          <th>Nilai</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($integrity_events)): ?>
          <?php foreach ($integrity_events as $ev): ?>
            <tr>
              <td><?= h(format_date_id((string) ($ev['created_at'] ?? ''))) ?></td>
              <td><?= h((string) ($ev['phase_label'] ?? '-')) ?></td>
              <td><?= h((string) ($ev['event_type_label'] ?? '-')) ?></td>
              <td><?= h((string) ($ev['event_value_label'] ?? '-')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="4">Belum ada event timeline.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="table-card answer-card" style="margin-top:12px;">
    <h3>Typing Metrics (Esai)</h3>
    <table class="answer-table">
      <thead>
        <tr>
          <th>No</th>
          <th>Keystrokes</th>
          <th>Input Events</th>
          <th>Paste</th>
          <th>Chars</th>
          <th>Active (detik)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($typing_metrics_rows)): ?>
          <?php foreach ($typing_metrics_rows as $row): ?>
            <tr>
              <td><?= h((string) ((int) ($row['question_order'] ?? 0))) ?></td>
              <td><?= h((string) ((int) ($row['keystrokes'] ?? 0))) ?></td>
              <td><?= h((string) ((int) ($row['input_events'] ?? 0))) ?></td>
              <td><?= h((string) ((int) ($row['paste_count'] ?? 0))) ?></td>
              <td><?= h((string) ((int) ($row['total_chars'] ?? 0))) ?></td>
              <td><?= h((string) round(((int) ($row['active_ms'] ?? 0)) / 1000, 1)) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="6">Belum ada typing metrics.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="profile-card" style="margin-bottom:14px;">
    <h3>Checklist Wawancara HR</h3>
    <p class="subtitle">Isi checklist ini saat interview tatap muka untuk verifikasi jawaban kandidat.</p>

    <form method="post" action="<?= h(route_path('/hr/candidates/' . $candidate['id'] . '/interview-checklist')) ?>" class="identity-form" style="margin-top:12px;">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">

      <?php foreach (($interview_sections ?? []) as $sectionTitle => $items): ?>
        <div style="border:1px solid #e2e8f0;border-radius:12px;padding:10px;">
          <p style="font-weight:700;margin-bottom:8px;"><?= h((string) $sectionTitle) ?></p>
          <div style="display:grid;gap:8px;">
            <?php foreach ($items as $key => $label): ?>
              <label class="check-inline" style="margin:0;">
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
