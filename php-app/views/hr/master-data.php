<main class="hr-page">
  <header class="hr-header">
    <div>
      <p class="eyebrow">HR Console</p>
      <h1>Kelola Role & Kelompok Soal Esai</h1>
    </div>
    <div class="hr-actions">
      <a href="<?= h(route_path('/hr/dashboard')) ?>" class="btn-secondary">Kembali ke Dashboard</a>
      <form method="post" action="<?= h(route_path('/hr/logout')) ?>" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <button type="submit" class="btn-secondary">Logout</button>
      </form>
    </div>
  </header>

  <?php if (!empty($flash_message)): ?>
    <section class="table-card">
      <div class="toast <?= ($flash_type ?? 'info') === 'success' ? 'toast-success' : (($flash_type ?? 'info') === 'error' ? 'toast-error' : 'toast-info') ?>">
        <?= h((string) $flash_message) ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="table-card">
    <h3>Kelompok Soal Esai</h3>
    <form method="post" action="<?= h(route_path('/hr/master-data/essay-groups/new')) ?>" class="filter-grid u-mt-12">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
      <input type="text" name="group_name" placeholder="Nama kelompok (contoh: Kitchen)" required>
      <input type="number" name="sort_order" min="1" value="999" required>
      <label class="checkbox-inline"><input type="checkbox" name="is_active" value="1" checked> Aktif</label>
      <button type="submit" class="btn-secondary">Tambah Kelompok</button>
    </form>

    <table class="admin-table compact">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nama Kelompok</th>
          <th>Urutan</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($essay_groups)): ?>
          <?php foreach ($essay_groups as $g): ?>
            <tr>
              <td>#<?= h((string) ($g['id'] ?? 0)) ?></td>
              <td>
                <form method="post" action="<?= h(route_path('/hr/master-data/essay-groups/' . (int) ($g['id'] ?? 0) . '/edit')) ?>" class="inline-form multi-input">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <input type="text" name="group_name" value="<?= h((string) ($g['group_name'] ?? '')) ?>" required>
              </td>
              <td><input type="number" name="sort_order" min="1" value="<?= h((string) ((int) ($g['sort_order'] ?? 999))) ?>" required></td>
              <td>
                  <label class="checkbox-inline"><input type="checkbox" name="is_active" value="1" <?= !empty($g['is_active']) ? 'checked' : '' ?>> Aktif</label>
              </td>
              <td>
                  <button type="submit" class="btn-secondary action-btn">Simpan</button>
                </form>
                <form method="post" action="<?= h(route_path('/hr/master-data/essay-groups/' . (int) ($g['id'] ?? 0) . '/toggle-active')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-secondary action-btn"><?= !empty($g['is_active']) ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" action="<?= h(route_path('/hr/master-data/essay-groups/' . (int) ($g['id'] ?? 0) . '/delete')) ?>" class="inline-form" onsubmit="return confirm('Hapus kelompok ini? Pastikan tidak dipakai role/soal.');">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-danger-outline action-btn">Hapus</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="5">Belum ada kelompok soal esai.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="table-card">
    <h3>Role Kandidat</h3>
    <form method="post" action="<?= h(route_path('/hr/master-data/roles/new')) ?>" class="filter-grid u-mt-12">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
      <input type="text" name="role_name" placeholder="Nama role (contoh: Server)" required>
      <select name="essay_group" required>
        <option value="">Pilih kelompok esai</option>
        <?php foreach ($essay_groups as $g): ?>
          <option value="<?= h((string) ($g['group_name'] ?? '')) ?>"><?= h((string) ($g['group_name'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="number" name="sort_order" min="1" value="999" required>
      <label class="checkbox-inline"><input type="checkbox" name="is_active" value="1" checked> Aktif</label>
      <button type="submit" class="btn-secondary">Tambah Role</button>
    </form>

    <table class="admin-table compact">
      <thead>
        <tr>
          <th>ID</th>
          <th>Role</th>
          <th>Kelompok Esai</th>
          <th>Urutan</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($roles)): ?>
          <?php foreach ($roles as $r): ?>
            <tr>
              <td>#<?= h((string) ($r['id'] ?? 0)) ?></td>
              <td>
                <form method="post" action="<?= h(route_path('/hr/master-data/roles/' . (int) ($r['id'] ?? 0) . '/edit')) ?>" class="inline-form multi-input">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <input type="text" name="role_name" value="<?= h((string) ($r['role_name'] ?? '')) ?>" required>
              </td>
              <td>
                  <select name="essay_group" required>
                    <?php foreach ($essay_groups as $g): ?>
                      <?php $groupName = (string) ($g['group_name'] ?? ''); ?>
                      <option value="<?= h($groupName) ?>" <?= ((string) ($r['essay_group'] ?? '') === $groupName) ? 'selected' : '' ?>><?= h($groupName) ?></option>
                    <?php endforeach; ?>
                  </select>
              </td>
              <td><input type="number" name="sort_order" min="1" value="<?= h((string) ((int) ($r['sort_order'] ?? 999))) ?>" required></td>
              <td>
                  <label class="checkbox-inline"><input type="checkbox" name="is_active" value="1" <?= !empty($r['is_active']) ? 'checked' : '' ?>> Aktif</label>
              </td>
              <td>
                  <button type="submit" class="btn-secondary action-btn">Simpan</button>
                </form>
                <form method="post" action="<?= h(route_path('/hr/master-data/roles/' . (int) ($r['id'] ?? 0) . '/toggle-active')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-secondary action-btn"><?= !empty($r['is_active']) ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" action="<?= h(route_path('/hr/master-data/roles/' . (int) ($r['id'] ?? 0) . '/delete')) ?>" class="inline-form" onsubmit="return confirm('Hapus role ini? Jika masih dipakai kandidat, sistem akan menolak.');">
                  <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                  <button type="submit" class="btn-danger-outline action-btn">Hapus</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="6">Belum ada role.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>
</main>
