document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('logoutModal');
  const openBtn = document.querySelector('[data-open-logout]');
  const cancelBtn = document.querySelector('[data-cancel-logout]');

  if (openBtn && modal) {
    openBtn.addEventListener('click', (event) => {
      event.preventDefault();
      modal.classList.add('open');
    });
  }

  if (cancelBtn && modal) {
    cancelBtn.addEventListener('click', () => {
      modal.classList.remove('open');
    });
  }

  if (modal) {
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        modal.classList.remove('open');
      }
    });
  }
});
