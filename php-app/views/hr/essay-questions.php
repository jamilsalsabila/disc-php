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
    <h3 class="u-mb-10">Bulk Upload Soal Esai (CSV)</h3>
    <p class="subtitle u-mb-10">Gunakan template CSV bawaan agar format konsisten per kelompok role.</p>
    <div class="hr-actions u-mb-10">
      <a href="<?= h(route_path('/hr/essay-questions/template.csv')) ?>" class="btn-secondary">Download Template CSV</a>
      <?php if (!empty($bulk_error_count)): ?>
        <a href="<?= h(route_path('/hr/essay-questions/bulk-errors.csv')) ?>" class="btn-secondary">Download Error CSV (<?= h((string) $bulk_error_count) ?>)</a>
      <?php endif; ?>
    </div>
    <form method="post" action="<?= h(route_path('/hr/essay-questions/bulk-preview')) ?>" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
      <div class="filter-grid hr-bulk-grid">
        <div>
          <label for="bulk_csv_file"><strong>Upload file .csv</strong></label>
          <input id="bulk_csv_file" type="file" name="bulk_csv_file" accept=".csv,text/csv" class="u-mt-6">
        </div>
        <div>
          <label for="import_mode"><strong>Mode import</strong></label>
          <select id="import_mode" name="import_mode" class="u-mt-6">
            <option value="append" <?= (($bulk_preview_mode ?? 'append') === 'append') ? 'selected' : '' ?>>Append (tambah, tidak boleh tabrakan urutan)</option>
            <option value="replace" <?= (($bulk_preview_mode ?? 'append') === 'replace') ? 'selected' : '' ?>>Replace per kelompok role (hapus lalu isi ulang group terkait)</option>
          </select>
        </div>
      </div>
      <label for="bulk_csv" class="u-block u-mt-10"><strong>Atau tempel CSV di sini</strong></label>
      <textarea id="bulk_csv" name="bulk_csv" rows="8" placeholder="role_group,order,question_text,guidance_text,is_active" class="u-mt-6"></textarea>
      <div class="hr-actions u-mt-10">
        <button type="submit" class="btn-primary">Preview Bulk</button>
      </div>
    </form>

    <?php if (!empty($bulk_preview_rows)): ?>
      <div class="hr-bulk-preview">
        <h3 class="u-mb-8">Preview Import Soal Esai</h3>
        <p class="subtitle u-mb-8">
          Total baris valid: <?= h((string) ($bulk_preview_total ?? count($bulk_preview_rows))) ?> |
          Mode: <?= h(($bulk_preview_mode ?? 'append') === 'replace' ? 'Replace per kelompok role' : 'Append') ?>
        </p>
        <?php if (!empty($bulk_preview_summary) && is_array($bulk_preview_summary)): ?>
          <p class="subtitle u-mb-10">
            Ringkasan:
            <?php
              $parts = [];
              foreach ($bulk_preview_summary as $group => $count) {
                  $parts[] = (string) $group . ': ' . (int) $count;
              }
              echo h(implode(' | ', $parts));
            ?>
          </p>
        <?php endif; ?>

        <table class="admin-table essay-question-table">
          <thead>
            <tr>
              <th>Kelompok</th>
              <th>Urutan</th>
              <th>Pertanyaan Esai</th>
              <th>Panduan Jawaban</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bulk_preview_rows as $row): ?>
              <tr>
                <td class="eq-col-group"><?= h((string) ($row['role_group'] ?? '-')) ?></td>
                <td class="eq-col-order"><?= h((string) ((int) ($row['order'] ?? 0))) ?></td>
                <td class="eq-col-question">
                  <span class="cell-clamp" title="<?= h((string) ($row['question_text'] ?? '')) ?>"><?= h((string) ($row['question_text'] ?? '')) ?></span>
                </td>
                <td class="eq-col-guidance">
                  <?php $guide = trim((string) ($row['guidance_text'] ?? '')) !== '' ? (string) ($row['guidance_text'] ?? '') : '-'; ?>
                  <span class="cell-clamp" title="<?= h($guide) ?>"><?= h($guide) ?></span>
                </td>
                <td><?= !empty($row['is_active']) ? '<span class="badge-success">Aktif</span>' : '<span class="badge-muted">Nonaktif</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <form method="post" action="<?= h(route_path('/hr/essay-questions/bulk-import-confirm')) ?>" class="u-mt-10">
          <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
          <div class="hr-actions">
            <button type="submit" class="btn-primary" onclick="return confirm('Lanjutkan import soal esai sesuai preview ini?');">Konfirmasi Import</button>
          </div>
        </form>
        <form method="post" action="<?= h(route_path('/hr/essay-questions/bulk-preview-clear')) ?>" class="u-mt-8">
          <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
          <div class="hr-actions">
            <button type="submit" class="btn-secondary">Hapus Preview</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </section>

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
    <?php
      $stateQuery = [];
      if (($group_filter ?? '') !== '') {
          $stateQuery['group'] = (string) $group_filter;
      }
      if (($sort_by ?? 'default') !== 'default') {
          $stateQuery['sort_by'] = (string) $sort_by;
      }
      if (($sort_dir ?? 'asc') !== 'asc') {
          $stateQuery['sort_dir'] = (string) $sort_dir;
      }
      if ((int) (($pagination['per_page'] ?? 20)) !== 20) {
          $stateQuery['per_page'] = (int) ($pagination['per_page'] ?? 20);
      }
      if ((int) (($pagination['page'] ?? 1)) > 1) {
          $stateQuery['page'] = (int) ($pagination['page'] ?? 1);
      }
      $stateQueryString = http_build_query($stateQuery);
    ?>
    <div class="hr-actions u-mb-10">
      <button type="button" id="essay-select-all-btn" class="btn-secondary">Pilih Semua</button>
      <form id="essay-bulk-delete-form" method="post" action="<?= h(route_path('/hr/essay-questions/bulk-delete')) ?>" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <?php if ($stateQueryString !== ''): ?>
          <input type="hidden" name="return_query" value="<?= h($stateQueryString) ?>">
        <?php endif; ?>
        <input type="hidden" name="ids_csv" id="essay-selected-ids" value="">
        <button type="button" id="essay-delete-selected-btn" class="btn-danger-outline" disabled>Delete</button>
      </form>
    </div>
    <table class="admin-table essay-question-table">
      <thead>
        <tr>
          <th class="bulk-select-col">Pilih</th>
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
              <td class="bulk-select-col">
                <input
                  type="checkbox"
                  class="essay-row-checkbox"
                  data-id="<?= h((string) $q['id']) ?>"
                >
              </td>
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
                  <?php
                    $editPath = '/hr/essay-questions/' . $q['id'] . '/edit';
                    if ($stateQueryString !== '') {
                        $editPath .= '?' . $stateQueryString;
                    }
                  ?>
                  <a href="<?= h(route_path($editPath)) ?>" class="table-link btn-detail action-btn">Edit</a>
                <form method="post" action="<?= h(route_path('/hr/essay-questions/' . $q['id'] . '/toggle-active')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <?php if ($stateQueryString !== ''): ?>
                    <input type="hidden" name="return_query" value="<?= h($stateQueryString) ?>">
                  <?php endif; ?>
                  <button type="submit" class="btn-secondary btn-xs action-btn"><?= $q['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" action="<?= h(route_path('/hr/essay-questions/' . $q['id'] . '/delete')) ?>" class="inline-form" onsubmit="return confirm('Hapus soal esai ini?');">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <?php if ($stateQueryString !== ''): ?>
                    <input type="hidden" name="return_query" value="<?= h($stateQueryString) ?>">
                  <?php endif; ?>
                  <button type="submit" class="btn-danger-outline btn-xs action-btn">Hapus</button>
                </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="8">Belum ada soal esai.</td></tr>
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
<script>
(() => {
  const selectAllBtn = document.getElementById('essay-select-all-btn');
  const deleteBtn = document.getElementById('essay-delete-selected-btn');
  const idsInput = document.getElementById('essay-selected-ids');
  const deleteForm = document.getElementById('essay-bulk-delete-form');
  const selectCols = Array.from(document.querySelectorAll('.bulk-select-col'));
  const rowChecks = Array.from(document.querySelectorAll('.essay-row-checkbox'));
  if (!selectAllBtn || !deleteBtn || !idsInput || !deleteForm || rowChecks.length === 0) return;

  let bulkMode = false;

  const updateDeleteState = () => {
    const selectedIds = rowChecks.filter((cb) => cb.checked).map((cb) => cb.dataset.id).filter(Boolean);
    idsInput.value = selectedIds.join(',');
    deleteBtn.disabled = selectedIds.length === 0;
    if (bulkMode) {
      const allChecked = selectedIds.length > 0 && selectedIds.length === rowChecks.length;
      selectAllBtn.textContent = allChecked ? 'Batal Pilih Semua' : 'Pilih Semua';
    }
  };

  selectAllBtn.addEventListener('click', () => {
    bulkMode = true;
    selectCols.forEach((col) => {
      col.style.display = 'table-cell';
    });
    const currentlyAllChecked = rowChecks.every((cb) => cb.checked);
    rowChecks.forEach((cb) => {
      cb.checked = !currentlyAllChecked;
    });
    updateDeleteState();
  });

  rowChecks.forEach((cb) => {
    cb.addEventListener('change', updateDeleteState);
  });

  deleteBtn.addEventListener('click', () => {
    if (!bulkMode) {
      return;
    }
    const ids = idsInput.value !== '' ? idsInput.value.split(',') : [];
    if (ids.length === 0) {
      alert('Pilih minimal 1 soal esai.');
      return;
    }
    if (!confirm(`Hapus ${ids.length} soal esai terpilih?`)) {
      return;
    }
    deleteForm.submit();
  });
})();
</script>
