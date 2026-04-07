<main class="hr-page">
  <header class="hr-header">
    <div>
      <p class="eyebrow">HR Console</p>
      <h1>Dashboard DISC Kandidat</h1>
    </div>
    <div class="hr-actions">
      <button type="button" class="btn-secondary compact-toggle-btn" data-compact-toggle aria-pressed="false">Tabel: Normal</button>
      <button type="button" class="btn-secondary" id="timeout-refresh-btn">Refresh Status</button>
      <form method="post" action="<?= h(route_path('/hr/tools/normalize-legacy-essay')) ?>" class="inline-form" onsubmit="return confirm('Jalankan perbaikan data lama sekarang? Sistem akan backup dulu sebelum normalisasi.');">
        <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <button type="submit" class="btn-secondary">Perbaiki Data Lama</button>
      </form>
      <form method="post" action="<?= h(route_path('/hr/tools/normalize-legacy-essay-preview')) ?>" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <button type="submit" class="btn-secondary">Preview Perbaikan</button>
      </form>
      <a href="<?= h(route_path('/hr/questions')) ?>" class="btn-secondary">Kelola Soal DISC</a>
      <a href="<?= h(route_path('/hr/essay-questions')) ?>" class="btn-secondary">Kelola Soal Esai</a>
      <a href="<?= h(route_path('/hr/master-data')) ?>" class="btn-secondary">Kelola Role & Kelompok</a>
      <a href="<?= h(route_path('/hr/export/excel')) ?>" class="btn-secondary">Export Excel</a>
      <a href="<?= h(route_path('/hr/export/pdf')) ?>" class="btn-secondary">Export PDF</a>
      <a href="<?= h(route_path('/hr/export/answers.csv')) ?>" class="btn-secondary">Export Jawaban (CSV)</a>
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
      <input type="hidden" name="page" value="<?= h((string) (($pagination['page'] ?? 1))) ?>" id="page-input">
      <input type="hidden" name="per_page" value="<?= h((string) (($pagination['per_page'] ?? 20))) ?>" id="per-page-input">
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
    <table class="admin-table dashboard-table">
      <thead>
        <tr>
          <th class="db-col-id">ID</th>
          <th class="db-col-name">Nama</th>
          <th class="db-col-role">Role Dipilih</th>
          <th class="db-col-reco">Rekomendasi</th>
          <th class="db-col-interview">Kelayakan Wawancara</th>
          <th class="db-col-status">Status</th>
          <th class="db-col-action">Aksi</th>
        </tr>
      </thead>
      <tbody id="candidate-table-body">
        <?php if (!empty($candidates)): ?>
          <?php foreach ($candidates as $candidate): ?>
            <tr class="candidate-row" data-href="<?= h(route_path('/hr/candidates/' . $candidate['id'])) ?>">
              <td class="db-col-id">#<?= h((string) $candidate['id']) ?></td>
              <td class="db-col-name">
                <strong class="cell-clamp" title="<?= h($candidate['full_name']) ?>"><?= h($candidate['full_name']) ?></strong>
                <br>
                <small class="cell-clamp" title="<?= h($candidate['email']) ?>"><?= h($candidate['email']) ?></small>
              </td>
              <td class="db-col-role"><span class="cell-clamp" title="<?= h($candidate['selected_role']) ?>"><?= h($candidate['selected_role']) ?></span></td>
              <td class="db-col-reco"><span class="cell-clamp" title="<?= h(map_recommendation_label($candidate['recommendation'])) ?>"><?= h(map_recommendation_label($candidate['recommendation'])) ?></span></td>
              <td class="db-col-interview"><span class="cell-clamp" title="<?= h($candidate['interview_recommendation'] ?? '-') ?>"><?= h($candidate['interview_recommendation'] ?? '-') ?></span></td>
              <td class="db-col-status"><span class="cell-clamp" title="<?= h($candidate['status']) ?>"><?= h($candidate['status']) ?></span></td>
              <td class="db-col-action">
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

    <div class="dashboard-pagination" id="candidate-pagination">
      <div class="dashboard-pagination-meta" id="pagination-meta">
        Menampilkan <?= h((string) (($pagination['from'] ?? 0))) ?>-<?= h((string) (($pagination['to'] ?? 0))) ?>
        dari <?= h((string) (($pagination['total'] ?? 0))) ?> kandidat
      </div>
      <div class="dashboard-pagination-controls">
        <label for="per-page-select" class="pagination-per-page">
          Baris
          <select id="per-page-select">
            <?php foreach ([10, 20, 50, 100] as $n): ?>
              <option value="<?= h((string) $n) ?>" <?= ((int) (($pagination['per_page'] ?? 20)) === $n) ? 'selected' : '' ?>><?= h((string) $n) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="button" class="btn-secondary" id="prev-page-btn">Sebelumnya</button>
        <span class="pagination-page-label" id="page-indicator">
          Halaman <?= h((string) (($pagination['page'] ?? 1))) ?> / <?= h((string) (($pagination['total_pages'] ?? 1))) ?>
        </span>
        <button type="button" class="btn-secondary" id="next-page-btn">Berikutnya</button>
      </div>
    </div>
  </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  window.initialCandidates = <?= json_encode($candidates, JSON_UNESCAPED_UNICODE) ?>;
  window.roleDistribution = <?= json_encode($stats['roleDistribution'], JSON_UNESCAPED_UNICODE) ?>;
  window.avgDisc = <?= json_encode($stats['avgDisc'], JSON_UNESCAPED_UNICODE) ?>;
  window.csrfToken = <?= json_encode($csrf_token, JSON_UNESCAPED_UNICODE) ?>;
  window.routeBase = <?= json_encode(route_path(''), JSON_UNESCAPED_UNICODE) ?>;
  window.initialPagination = <?= json_encode($pagination ?? ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1, 'from' => 0, 'to' => 0], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= h(asset_path('hr-dashboard.js')) ?>"></script>
