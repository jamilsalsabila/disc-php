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

  <section class="profile-grid">
    <article class="profile-card profile-summary-card">
      <h3>Ringkasan Penilaian</h3>
      <p><strong>Role dipilih:</strong> <?= h($candidate['selected_role']) ?></p>
      <p><strong>Rekomendasi sistem:</strong> <?= h(map_recommendation_label($candidate['recommendation'])) ?></p>
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
      <p class="chart-note">Perbandingan persentase kecocokan terhadap role target.</p>
      <div class="chart-shell chart-shell-profile-bar">
        <canvas id="roleBar"></canvas>
      </div>
    </article>
  </section>

  <section class="table-card answer-card">
    <h3>Jawaban Kandidat</h3>
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
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  window.discData = <?= json_encode($disc_data, JSON_UNESCAPED_UNICODE) ?>;
  window.roleScoreData = <?= json_encode($role_score_data, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= h(asset_path('candidate-profile.js')) ?>"></script>
