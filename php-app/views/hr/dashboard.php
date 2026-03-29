<main class="hr-page">
  <header class="hr-header">
    <div>
      <p class="eyebrow">HR Console</p>
      <h1>Dashboard DISC Kandidat</h1>
    </div>
    <div class="hr-actions">
      <button type="button" class="btn-secondary" id="timeout-refresh-btn">Refresh Status</button>
      <a href="<?= h(route_path('/hr/questions')) ?>" class="btn-secondary">Kelola Soal</a>
      <a href="<?= h(route_path('/hr/export/excel')) ?>" class="btn-secondary">Export Excel</a>
      <a href="<?= h(route_path('/hr/export/pdf')) ?>" class="btn-secondary">Export PDF</a>
      <a href="<?= h(route_path('/hr/export/answers.csv')) ?>" class="btn-secondary">Export Jawaban (CSV)</a>
      <form method="post" action="<?= h(route_path('/hr/logout')) ?>" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <button type="submit" class="btn-secondary">Logout</button>
      </form>
    </div>
  </header>

  <section class="chart-grid">
    <article class="chart-card chart-card-role">
      <h3>Distribusi Rekomendasi</h3>
      <div class="chart-shell chart-shell-donut">
        <canvas id="roleChart"></canvas>
      </div>
    </article>
    <article class="chart-card chart-card-disc">
      <h3>Rata-rata DISC</h3>
      <div class="chart-shell chart-shell-bar">
        <canvas id="discChart"></canvas>
      </div>
    </article>
  </section>

  <section class="filter-card">
    <form method="get" action="<?= h(route_path('/hr/dashboard')) ?>" class="filter-grid" id="hr-filter-form">
      <input type="text" name="search" placeholder="Cari nama/email/WA" value="<?= h($filters['search'] ?? '') ?>">
      <select name="role">
        <option value="">Semua role dipilih</option>
        <?php foreach ($role_options as $role): ?>
          <option value="<?= h($role) ?>" <?= (($filters['role'] ?? '') === $role) ? 'selected' : '' ?>><?= h($role) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="recommendation">
        <option value="">Semua rekomendasi</option>
        <?php foreach ($recommendation_options as $opt): ?>
          <option value="<?= h($opt['value']) ?>" <?= (($filters['recommendation'] ?? '') === $opt['value']) ? 'selected' : '' ?>><?= h($opt['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn-secondary" id="reset-filter-btn">Reset</button>
    </form>
  </section>

  <section class="table-card">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Nama</th>
          <th>Role Dipilih</th>
          <th>Rekomendasi</th>
          <th>Kelayakan Wawancara</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody id="candidate-table-body">
        <?php if (!empty($candidates)): ?>
          <?php foreach ($candidates as $candidate): ?>
            <tr class="candidate-row" data-href="<?= h(route_path('/hr/candidates/' . $candidate['id'])) ?>">
              <td>#<?= h((string) $candidate['id']) ?></td>
              <td><strong><?= h($candidate['full_name']) ?></strong><br><small><?= h($candidate['email']) ?></small></td>
              <td><?= h($candidate['selected_role']) ?></td>
              <td><?= h(map_recommendation_label($candidate['recommendation'])) ?></td>
              <td><?= h($candidate['interview_recommendation'] ?? '-') ?></td>
              <td><?= h($candidate['status']) ?></td>
              <td>
                <div class="table-actions">
                  <a href="<?= h(route_path('/hr/candidates/' . $candidate['id'])) ?>" class="table-link btn-detail action-btn">Detail Profil</a>
                  <button type="button" class="btn-danger-outline delete-candidate-btn action-btn" data-id="<?= h((string) $candidate['id']) ?>" data-name="<?= h($candidate['full_name']) ?>">Hapus</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="7">Belum ada data kandidat.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  window.initialCandidates = <?= json_encode($candidates, JSON_UNESCAPED_UNICODE) ?>;
  window.roleDistribution = <?= json_encode($stats['roleDistribution'], JSON_UNESCAPED_UNICODE) ?>;
  window.avgDisc = <?= json_encode($stats['avgDisc'], JSON_UNESCAPED_UNICODE) ?>;
  window.csrfToken = <?= json_encode($csrf_token, JSON_UNESCAPED_UNICODE) ?>;
  window.routeBase = <?= json_encode(route_path(''), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= h(asset_path('hr-dashboard.js')) ?>"></script>
