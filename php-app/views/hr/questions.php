<main class="hr-page">
  <header class="hr-header">
    <div>
      <p class="eyebrow">HR Console</p>
      <h1>Kelola Soal DISC</h1>
      <p class="subtitle">Tambah, edit, hapus, dan aktif/nonaktifkan soal.</p>
    </div>
    <div class="hr-actions">
      <a href="<?= h(route_path('/hr/dashboard')) ?>" class="btn-secondary">Kembali ke Dashboard</a>
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
    <h3 style="margin-bottom:10px;">Bulk Upload Soal (CSV)</h3>
    <p class="subtitle" style="margin-bottom:10px;">Gunakan template CSV bawaan agar format konsisten.</p>
    <div class="hr-actions" style="margin-bottom:10px;">
      <a href="<?= h(route_path('/hr/questions/template.csv')) ?>" class="btn-secondary">Download Template CSV</a>
      <?php if (!empty($bulk_error_count)): ?>
        <a href="<?= h(route_path('/hr/questions/bulk-errors.csv')) ?>" class="btn-secondary">Download Error CSV (<?= h((string) $bulk_error_count) ?>)</a>
      <?php endif; ?>
    </div>
    <form method="post" action="<?= h(route_path('/hr/questions/bulk-preview')) ?>" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
      <div class="filter-grid" style="grid-template-columns:1fr 1fr;gap:10px;">
        <div>
          <label for="bulk_csv_file"><strong>Upload file .csv</strong></label>
          <input id="bulk_csv_file" type="file" name="bulk_csv_file" accept=".csv,text/csv" style="margin-top:6px;">
        </div>
        <div>
          <label for="import_mode"><strong>Mode import</strong></label>
          <select id="import_mode" name="import_mode" style="margin-top:6px;">
            <option value="append" <?= (($bulk_preview_mode ?? 'append') === 'append') ? 'selected' : '' ?>>Append (tambah, tidak boleh tabrakan role+order)</option>
            <option value="replace" <?= (($bulk_preview_mode ?? 'append') === 'replace') ? 'selected' : '' ?>>Replace per role (hapus role terkait lalu isi baru)</option>
          </select>
        </div>
      </div>
      <label for="bulk_csv" style="display:block;margin-top:10px;"><strong>Atau tempel CSV di sini</strong></label>
      <textarea id="bulk_csv" name="bulk_csv" rows="8" placeholder="role_key,order,option_a,option_b,option_c,option_d,is_active" style="margin-top:6px;"></textarea>
      <div class="hr-actions" style="margin-top:10px;">
        <button type="submit" class="btn-primary">Preview Bulk</button>
      </div>
    </form>

    <?php if (!empty($bulk_preview_rows)): ?>
      <div style="margin-top:14px;border-top:1px solid #e2e8f0;padding-top:12px;">
        <h3 style="margin-bottom:8px;">Preview Import</h3>
        <p class="subtitle" style="margin-bottom:8px;">
          Total baris valid: <?= h((string) ($bulk_preview_total ?? count($bulk_preview_rows))) ?> |
          Mode: <?= h(($bulk_preview_mode ?? 'append') === 'replace' ? 'Replace per role' : 'Append') ?>
        </p>
        <?php if (!empty($bulk_preview_summary) && is_array($bulk_preview_summary)): ?>
          <p class="subtitle" style="margin-bottom:10px;">
            Ringkasan role:
            <?php
              $parts = [];
              foreach ($bulk_preview_summary as $role => $count) {
                  $parts[] = $role . ' (' . $count . ')';
              }
              echo h(implode(', ', $parts));
            ?>
          </p>
        <?php endif; ?>

        <table>
          <thead>
            <tr>
              <th>Role</th>
              <th>Order</th>
              <th>Opsi A</th>
              <th>Opsi B</th>
              <th>Opsi C</th>
              <th>Opsi D</th>
              <th>Aktif</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bulk_preview_rows as $row): ?>
              <tr>
                <td><?= h((string) ($row['role_key'] ?? '')) ?></td>
                <td><?= h((string) ((int) ($row['order'] ?? 0))) ?></td>
                <td><?= h((string) ($row['option_a'] ?? '')) ?></td>
                <td><?= h((string) ($row['option_b'] ?? '')) ?></td>
                <td><?= h((string) ($row['option_c'] ?? '')) ?></td>
                <td><?= h((string) ($row['option_d'] ?? '')) ?></td>
                <td><?= !empty($row['is_active']) ? '1' : '0' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <form method="post" action="<?= h(route_path('/hr/questions/bulk-import-confirm')) ?>" style="margin-top:10px;">
          <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
          <div class="hr-actions">
            <button type="submit" class="btn-primary" onclick="return confirm('Lanjutkan import sesuai preview ini?');">Konfirmasi Import</button>
          </div>
        </form>
        <form method="post" action="<?= h(route_path('/hr/questions/bulk-preview-clear')) ?>" style="margin-top:8px;">
          <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
          <div class="hr-actions">
            <button type="submit" class="btn-secondary">Hapus Preview</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </section>

  <section class="table-card">
    <form method="get" action="<?= h(route_path('/hr/questions')) ?>" class="filter-grid" style="margin-bottom:12px;">
      <div class="subtitle" style="display:flex;align-items:center;padding:0 6px;">Filter soal berdasarkan role</div>
      <select name="role">
        <option value="">Semua role</option>
        <?php foreach (($role_options ?? []) as $role): ?>
          <option value="<?= h($role) ?>" <?= (($role_filter ?? '') === $role) ? 'selected' : '' ?>><?= h($role) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-secondary">Filter</button>
      <a href="<?= h(route_path('/hr/questions')) ?>" class="btn-secondary">Reset</a>
    </form>

    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Role</th>
          <th>Urutan</th>
          <th>Preview Opsi</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($question_bank)): ?>
          <?php foreach ($question_bank as $q): ?>
            <tr>
              <td>#<?= h((string) $q['id']) ?></td>
              <td><?= h($q['role_key']) ?></td>
              <td><?= h((string) $q['order']) ?></td>
              <td>
                <small>A. <?= h($q['optionA']) ?></small><br>
                <small>B. <?= h($q['optionB']) ?></small><br>
                <small>C. <?= h($q['optionC']) ?></small><br>
                <small>D. <?= h($q['optionD']) ?></small>
              </td>
              <td><?= $q['is_active'] ? '<span class="badge-success">Aktif</span>' : '<span class="badge-muted">Nonaktif</span>' ?></td>
              <td>
                <a href="<?= h(route_path('/hr/questions/' . $q['id'] . '/edit')) ?>" class="table-link btn-detail">Edit</a>
                <form method="post" action="<?= h(route_path('/hr/questions/' . $q['id'] . '/toggle-active')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-secondary btn-xs"><?= $q['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" action="<?= h(route_path('/hr/questions/' . $q['id'] . '/delete')) ?>" class="inline-form" onsubmit="return confirm('Hapus soal ini? Tindakan ini tidak bisa dibatalkan.');">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-danger-outline btn-xs">Hapus</button>
                </form>
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
