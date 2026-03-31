<main class="hero-page">
  <section class="hero-card question-form-card">
    <p class="eyebrow">HR Console</p>
    <h1><?= h($form_title) ?></h1>
    <p class="subtitle">Kelola pertanyaan esai untuk asesmen tulisan kandidat.</p>

    <?php if (!empty($error_message)): ?>
      <div class="alert"><?= h($error_message) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= h($action_url) ?>" class="identity-form">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">

      <label>
        Kelompok Role
        <select name="role_group" id="essay-role-group" required>
          <?php foreach (($essay_group_options ?? []) as $group): ?>
            <option value="<?= h($group) ?>" <?= (($values['role_group'] ?? 'Manager') === $group) ? 'selected' : '' ?>><?= h($group) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Urutan Soal
        <input type="number" min="1" name="order" id="essay-order-input" value="<?= h((string) ($values['order'] ?? '')) ?>" required>
      </label>

      <label>
        Pertanyaan Esai
        <textarea name="question_text" rows="4" required><?= h((string) ($values['question_text'] ?? '')) ?></textarea>
      </label>

      <label>
        Panduan Jawaban (opsional)
        <textarea name="guidance_text" rows="3" placeholder="Contoh: Jelaskan dengan contoh pengalaman kerja nyata."><?= h((string) ($values['guidance_text'] ?? '')) ?></textarea>
      </label>

      <label class="check-inline">
        <input type="checkbox" name="is_active" value="1" <?= !empty($values['is_active']) ? 'checked' : '' ?>>
        Aktifkan soal ini
      </label>

      <div class="hr-actions">
        <button type="submit" class="btn-primary">Simpan</button>
        <a href="<?= h(route_path('/hr/essay-questions')) ?>" class="btn-secondary">Batal</a>
      </div>
    </form>
  </section>
</main>
<?php if (!empty($auto_order_enabled)): ?>
  <script>
    (function () {
      const roleSelect = document.getElementById('essay-role-group');
      const orderInput = document.getElementById('essay-order-input');
      const nextOrderMap = <?= json_encode($next_order_map ?? [], JSON_UNESCAPED_UNICODE) ?>;
      if (!roleSelect || !orderInput || !nextOrderMap) return;

      roleSelect.addEventListener('change', function () {
        const role = roleSelect.value || '';
        const nextOrder = Number(nextOrderMap[role] || 1);
        orderInput.value = String(nextOrder > 0 ? nextOrder : 1);
      });
    })();
  </script>
<?php endif; ?>
