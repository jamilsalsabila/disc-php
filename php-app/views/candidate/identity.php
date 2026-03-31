<main class="hero-page">
  <section class="hero-card">
    <h1>Asesmen Kandidat Cotch</h1>
    <p class="subtitle">Isi data diri Anda lalu lanjut ke tes: <strong>DISC 10 menit</strong> dan <strong>Esai 15 menit</strong>.</p>

    <section class="info-panel">
      <h3>Informasi Penting</h3>
      <ul>
        <li>Durasi tes: <strong>DISC 10 menit</strong> lalu <strong>Esai 15 menit</strong>, masing-masing dengan timer otomatis.</li>
        <li>Setiap nomor wajib pilih <strong>1 Most</strong> dan <strong>1 Least</strong> (tidak boleh sama).</li>
        <li><strong>Most</strong> berarti paling menggambarkan diri Anda saat bekerja.</li>
        <li><strong>Least</strong> berarti paling tidak menggambarkan diri Anda saat bekerja.</li>
        <li>Tes ini hanya boleh diikuti <strong>satu kali</strong> per kandidat.</li>
      </ul>
    </section>

    <?php if (!empty($error_message)): ?>
      <div class="alert"><?= h($error_message) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= h(route_path('/start')) ?>" class="identity-form">
      <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">

      <label>
        Nama Lengkap
        <input type="text" name="full_name" placeholder="Contoh: Rina Setiawan" value="<?= h($values['full_name'] ?? '') ?>" required>
      </label>

      <label>
        Email
        <input type="email" name="email" placeholder="nama@email.com" value="<?= h($values['email'] ?? '') ?>" required>
      </label>

      <label>
        No. HP (WA Aktif)
        <input type="text" name="whatsapp" placeholder="08xxxxxxxxxx" value="<?= h($values['whatsapp'] ?? '') ?>" required>
      </label>

      <label>
        Role yang Dipilih
        <select name="selected_role" required>
          <option value="">Pilih role...</option>
          <?php foreach (($role_options ?? []) as $role): ?>
            <option value="<?= h($role) ?>" <?= (($values['selected_role'] ?? '') === $role) ? 'selected' : '' ?>><?= h($role) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <button type="submit" class="btn-primary">Next: Mulai Tes</button>
    </form>
  </section>
</main>
