</div><!-- end main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', function() {
  const toggleBtn = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  const closeSidebar = function() {
    if (!sidebar) return;
    sidebar.classList.remove('show');
    backdrop?.classList.remove('show');
    toggleBtn?.setAttribute('aria-expanded', 'false');
  };
  if (toggleBtn) {
    toggleBtn.addEventListener('click', function() {
      const isOpen = sidebar.classList.toggle('show');
      backdrop?.classList.toggle('show', isOpen);
      toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  }
  backdrop?.addEventListener('click', closeSidebar);
  document.querySelectorAll('#sidebar .nav-link').forEach(function(link) {
    link.addEventListener('click', closeSidebar);
  });
  document.querySelectorAll('form[data-disable-on-submit]').forEach(function(form) {
    form.addEventListener('submit', function() {
      const btn = form.querySelector('[type="submit"]');
      if (btn) btn.disabled = true;
    });
  });
});
</script>
<?php if(isset($extraJS)) echo $extraJS; ?>
</body>
</html>

