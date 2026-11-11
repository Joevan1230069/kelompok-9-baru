    </main>
  </div>

  <div class="modal-backdrop" id="logoutModal">
    <div class="modal-card">
      <h3 style="margin-top:0">Konfirmasi Logout</h3>
      <p class="note">Apakah Anda yakin ingin keluar dari SIMRS Rumah Sakit Permata Asri?</p>
      <div class="actions" style="justify-content:flex-end;margin-top:18px">
        <button class="btn btn-secondary" type="button" data-cancel-logout>Tidak</button>
        <form method="POST" action="logout.php" style="margin:0">
          <button class="btn btn-danger" type="submit">Ya, Logout</button>
        </form>
      </div>
    </div>
  </div>
  <script src="assets/js/app.js"></script>
</body>
</html>
