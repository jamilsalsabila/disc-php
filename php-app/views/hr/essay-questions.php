<main class="hr-page">
  <header class="hr-header">
    <div>
      <p class="eyebrow">HR Console</p>
      <h1>Kelola Soal Esai</h1>
      <p class="subtitle">Tambah, edit, hapus, dan aktif/nonaktifkan soal esai kandidat.</p>
    </div>
    <div class="hr-actions">
      <button type="button" class="btn-secondary compact-toggle-btn" data-compact-toggle aria-pressed="false">Tabel: Normal</button>
      <a href="<?= h(route_path('/hr/dashboard')) ?>" class="btn-secondary">Kembali ke Dashboard</a>
      <a href="<?= h(route_path('/hr/questions')) ?>" class="btn-secondary">Kelola Soal DISC</a>
      <a href="<?= h(route_path('/hr/essay-questions/new')) ?>" class="btn-primary">Tambah Soal Esai</a>
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

  <section class="table-card">
    <form method="get" action="<?= h(route_path('/hr/essay-questions')) ?>" class="filter-grid hr-essay-filter-grid">
      <select name="group">
        <option value="">Semua kelompok role</option>
        <?php foreach (($essay_group_options ?? []) as $group): ?>
          <option value="<?= h($group) ?>" <?= (($group_filter ?? '') === $group) ? 'selected' : '' ?>><?= h($group) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="sort_by">
        <?php $sortBy = (string) ($sort_by ?? 'default'); ?>
        <option value="default" <?= $sortBy === 'default' ? 'selected' : '' ?>>Urutan Default</option>
        <option value="order" <?= $sortBy === 'order' ? 'selected' : '' ?>>Urut Soal</option>
        <option value="group" <?= $sortBy === 'group' ? 'selected' : '' ?>>Kelompok Role</option>
        <option value="status" <?= $sortBy === 'status' ? 'selected' : '' ?>>Status</option>
        <option value="updated" <?= $sortBy === 'updated' ? 'selected' : '' ?>>Terakhir Update</option>
        <option value="id" <?= $sortBy === 'id' ? 'selected' : '' ?>>ID</option>
      </select>
      <select name="sort_dir">
        <?php $sortDir = (string) ($sort_dir ?? 'asc'); ?>
        <option value="asc" <?= $sortDir === 'asc' ? 'selected' : '' ?>>A-Z / Kecil ke Besar</option>
        <option value="desc" <?= $sortDir === 'desc' ? 'selected' : '' ?>>Z-A / Besar ke Kecil</option>
      </select>
      <select name="per_page">
        <?php $perPage = (int) (($pagination['per_page'] ?? 20)); ?>
        <?php foreach ([10, 20, 50, 100] as $n): ?>
          <option value="<?= h((string) $n) ?>" <?= ($perPage === $n) ? 'selected' : '' ?>><?= h((string) $n) ?> / halaman</option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="page" value="1">
      <button type="submit" class="btn-secondary">Filter</button>
      <a href="<?= h(route_path('/hr/essay-questions')) ?>" class="btn-secondary">Reset</a>
    </form>

    <div class="u-space-10"></div>
    <table class="admin-table essay-question-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Kelompok</th>
          <th>Urutan</th>
          <th>Pertanyaan Esai</th>
          <th>Panduan Jawaban</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($essay_questions)): ?>
          <?php foreach ($essay_questions as $q): ?>
            <tr>
              <td class="eq-col-id">#<?= h((string) $q['id']) ?></td>
              <td class="eq-col-group"><?= h((string) ($q['role_group'] ?? '-')) ?></td>
              <td class="eq-col-order"><?= h((string) $q['order']) ?></td>
              <td class="eq-col-question">
                <span class="cell-clamp" title="<?= h($q['question_text']) ?>"><?= h($q['question_text']) ?></span>
              </td>
              <td class="eq-col-guidance">
                <?php $guide = $q['guidance_text'] !== '' ? $q['guidance_text'] : '-'; ?>
                <span class="cell-clamp" title="<?= h($guide) ?>"><?= h($guide) ?></span>
              </td>
              <?php $essayStatusLabel = $q['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
              <td title="<?= h($essayStatusLabel) ?>">
                <?= $q['is_active'] ? '<span class="badge-success">Aktif</span>' : '<span class="badge-muted">Nonaktif</span>' ?>
              </td>
              <td class="eq-col-action">
                <div class="table-actions">
                  <a href="<?= h(route_path('/hr/essay-questions/' . $q['id'] . '/edit')) ?>" class="table-link btn-detail action-btn">Edit</a>
                <form method="post" action="<?= h(route_path('/hr/essay-questions/' . $q['id'] . '/toggle-active')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-secondary btn-xs action-btn"><?= $q['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" action="<?= h(route_path('/hr/essay-questions/' . $q['id'] . '/delete')) ?>" class="inline-form" onsubmit="return confirm('Hapus soal esai ini?');">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-danger-outline btn-xs action-btn">Hapus</button>
                </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="7">Belum ada soal esai.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php
      $pg = $pagination ?? ['page' => 1, 'total_pages' => 1, 'total' => 0, 'from' => 0, 'to' => 0, 'per_page' => 20];
      $currPage = (int) ($pg['page'] ?? 1);
      $totalPages = (int) ($pg['total_pages'] ?? 1);
      $baseQuery = [];
      if (($group_filter ?? '') !== '') {
          $baseQuery['group'] = (string) $group_filter;
      }
      if (($sort_by ?? 'default') !== 'default') {
          $baseQuery['sort_by'] = (string) $sort_by;
      }
      if (($sort_dir ?? 'asc') !== 'asc') {
          $baseQuery['sort_dir'] = (string) $sort_dir;
      }
      $baseQuery['per_page'] = (int) ($pg['per_page'] ?? 20);
      $prevQuery = $baseQuery;
      $prevQuery['page'] = max(1, $currPage - 1);
      $nextQuery = $baseQuery;
      $nextQuery['page'] = min($totalPages, $currPage + 1);
    ?>
    <div class="dashboard-pagination">
      <div class="dashboard-pagination-meta">
        Menampilkan <?= h((string) ((int) ($pg['from'] ?? 0))) ?>-<?= h((string) ((int) ($pg['to'] ?? 0))) ?>
        dari <?= h((string) ((int) ($pg['total'] ?? 0))) ?> soal esai
      </div>
      <div class="dashboard-pagination-controls">
        <a href="<?= h(route_path('/hr/essay-questions?' . http_build_query($prevQuery))) ?>" class="btn-secondary <?= $currPage <= 1 ? 'is-disabled' : '' ?>" <?= $currPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Sebelumnya</a>
        <span class="pagination-page-label">Halaman <?= h((string) $currPage) ?> / <?= h((string) $totalPages) ?></span>
        <a href="<?= h(route_path('/hr/essay-questions?' . http_build_query($nextQuery))) ?>" class="btn-secondary <?= $currPage >= $totalPages ? 'is-disabled' : '' ?>" <?= $currPage >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Berikutnya</a>
      </div>
    </div>
  </section>
</main>
