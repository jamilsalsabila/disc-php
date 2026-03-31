<main class="hr-page">
  <header class="hr-header">
    <div>
      <p class="eyebrow">HR Console</p>
      <h1>Kelola Soal DISC</h1>
      <p class="subtitle">Tambah, edit, hapus, dan aktif/nonaktifkan soal.</p>
    </div>
    <div class="hr-actions">
      <button type="button" class="btn-secondary compact-toggle-btn" data-compact-toggle aria-pressed="false">Tabel: Normal</button>
      <a href="<?= h(route_path('/hr/dashboard')) ?>" class="btn-secondary">Kembali ke Dashboard</a>
      <a href="<?= h(route_path('/hr/essay-questions')) ?>" class="btn-secondary">Kelola Soal Esai</a>
      <a href="<?= h(route_path('/hr/questions/new')) ?>" class="btn-primary">Tambah Soal</a>
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
    <h3 class="u-mb-10">Bulk Upload Soal (CSV)</h3>
    <p class="subtitle u-mb-10">Gunakan template CSV bawaan agar format konsisten.</p>
    <div class="hr-actions u-mb-10">
      <a href="<?= h(route_path('/hr/questions/template.csv')) ?>" class="btn-secondary">Download Template CSV</a>
      <?php if (!empty($bulk_error_count)): ?>
        <a href="<?= h(route_path('/hr/questions/bulk-errors.csv')) ?>" class="btn-secondary">Download Error CSV (<?= h((string) $bulk_error_count) ?>)</a>
      <?php endif; ?>
    </div>
    <form method="post" action="<?= h(route_path('/hr/questions/bulk-preview')) ?>" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
      <div class="filter-grid hr-bulk-grid">
        <div>
          <label for="bulk_csv_file"><strong>Upload file .csv</strong></label>
          <input id="bulk_csv_file" type="file" name="bulk_csv_file" accept=".csv,text/csv" class="u-mt-6">
        </div>
        <div>
          <label for="import_mode"><strong>Mode import</strong></label>
          <select id="import_mode" name="import_mode" class="u-mt-6">
            <option value="append" <?= (($bulk_preview_mode ?? 'append') === 'append') ? 'selected' : '' ?>>Append (tambah, tidak boleh tabrakan order)</option>
            <option value="replace" <?= (($bulk_preview_mode ?? 'append') === 'replace') ? 'selected' : '' ?>>Replace semua soal aktif (hapus semua lalu isi baru)</option>
          </select>
        </div>
      </div>
      <label for="bulk_csv" class="u-block u-mt-10"><strong>Atau tempel CSV di sini</strong></label>
      <textarea id="bulk_csv" name="bulk_csv" rows="8" placeholder="role_key,order,option_a,option_b,option_c,option_d,disc_a,disc_b,disc_c,disc_d,is_active" class="u-mt-6"></textarea>
      <div class="hr-actions u-mt-10">
        <button type="submit" class="btn-primary">Preview Bulk</button>
      </div>
    </form>

    <?php if (!empty($bulk_preview_rows)): ?>
      <div class="hr-bulk-preview">
        <h3 class="u-mb-8">Preview Import</h3>
        <p class="subtitle u-mb-8">
          Total baris valid: <?= h((string) ($bulk_preview_total ?? count($bulk_preview_rows))) ?> |
          Mode: <?= h(($bulk_preview_mode ?? 'append') === 'replace' ? 'Replace semua soal' : 'Append') ?>
        </p>
        <p class="subtitle u-mb-10">Scope bank soal: <strong>All</strong>.</p>

        <table class="admin-table disc-preview-table">
          <thead>
            <tr>
              <th class="dq-col-role">Role</th>
              <th class="dq-col-order">Order</th>
              <th class="dq-col-option">Opsi A</th>
              <th class="dq-col-option">Opsi B</th>
              <th class="dq-col-option">Opsi C</th>
              <th class="dq-col-option">Opsi D</th>
              <th class="dq-col-status">Aktif</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bulk_preview_rows as $row): ?>
              <tr>
                <td class="dq-col-role">All</td>
                <td class="dq-col-order"><?= h((string) ((int) ($row['order'] ?? 0))) ?></td>
                <td class="dq-col-option"><span class="cell-clamp" title="<?= h((string) ($row['option_a'] ?? '')) ?>"><?= h((string) ($row['option_a'] ?? '')) ?></span></td>
                <td class="dq-col-option"><span class="cell-clamp" title="<?= h((string) ($row['option_b'] ?? '')) ?>"><?= h((string) ($row['option_b'] ?? '')) ?></span></td>
                <td class="dq-col-option"><span class="cell-clamp" title="<?= h((string) ($row['option_c'] ?? '')) ?>"><?= h((string) ($row['option_c'] ?? '')) ?></span></td>
                <td class="dq-col-option"><span class="cell-clamp" title="<?= h((string) ($row['option_d'] ?? '')) ?>"><?= h((string) ($row['option_d'] ?? '')) ?></span></td>
                <td class="dq-col-status">
                  <span class="cell-clamp" title="<?= !empty($row['is_active']) ? '1' : '0' ?>">
                    <?= !empty($row['is_active']) ? '1' : '0' ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <form method="post" action="<?= h(route_path('/hr/questions/bulk-import-confirm')) ?>" class="u-mt-10">
          <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
          <div class="hr-actions">
            <button type="submit" class="btn-primary" onclick="return confirm('Lanjutkan import sesuai preview ini?');">Konfirmasi Import</button>
          </div>
        </form>
        <form method="post" action="<?= h(route_path('/hr/questions/bulk-preview-clear')) ?>" class="u-mt-8">
          <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
          <div class="hr-actions">
            <button type="submit" class="btn-secondary">Hapus Preview</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </section>

  <section class="table-card">
    <p class="subtitle u-mb-12">Semua soal pada bank ini berlaku untuk semua posisi (Role: All).</p>

    <table class="admin-table disc-question-table">
      <thead>
        <tr>
          <th class="dq-col-id">ID</th>
          <th class="dq-col-role">Role</th>
          <th class="dq-col-order">Urutan</th>
          <th class="dq-col-preview">Preview Opsi</th>
          <th class="dq-col-status">Status</th>
          <th class="dq-col-action">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($question_bank)): ?>
          <?php foreach ($question_bank as $q): ?>
            <tr>
              <td class="dq-col-id">#<?= h((string) $q['id']) ?></td>
              <td class="dq-col-role">All</td>
              <td class="dq-col-order"><?= h((string) $q['order']) ?></td>
              <td class="dq-col-preview">
                <div class="disc-option-preview">
                  <span class="cell-clamp" title="<?= h($q['optionA']) ?>">A. <?= h($q['optionA']) ?></span>
                  <span class="cell-clamp" title="<?= h($q['optionB']) ?>">B. <?= h($q['optionB']) ?></span>
                  <span class="cell-clamp" title="<?= h($q['optionC']) ?>">C. <?= h($q['optionC']) ?></span>
                  <span class="cell-clamp" title="<?= h($q['optionD']) ?>">D. <?= h($q['optionD']) ?></span>
                </div>
              </td>
              <?php $statusLabel = $q['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
              <td class="dq-col-status" title="<?= h($statusLabel) ?>">
                <?= $q['is_active'] ? '<span class="badge-success">Aktif</span>' : '<span class="badge-muted">Nonaktif</span>' ?>
              </td>
              <td class="dq-col-action">
                <div class="table-actions">
                <a href="<?= h(route_path('/hr/questions/' . $q['id'] . '/edit')) ?>" class="table-link btn-detail action-btn">Edit</a>
                <form method="post" action="<?= h(route_path('/hr/questions/' . $q['id'] . '/toggle-active')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-secondary btn-xs action-btn"><?= $q['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" action="<?= h(route_path('/hr/questions/' . $q['id'] . '/delete')) ?>" class="inline-form" onsubmit="return confirm('Hapus soal ini? Tindakan ini tidak bisa dibatalkan.');">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-danger-outline btn-xs action-btn">Hapus</button>
                </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="6">Belum ada soal.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>
</main>
