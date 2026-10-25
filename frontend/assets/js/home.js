document.addEventListener("DOMContentLoaded", () => {
  const toggle = document.getElementById("mobileToggle");
  const nav = document.getElementById("mainNav");
  const year = document.getElementById("year");

  toggle?.addEventListener("click", () => {
    const isOpen = nav?.classList.toggle("open") || false;
    toggle.setAttribute("aria-expanded", String(isOpen));
  });

  nav?.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => {
      nav.classList.remove("open");
      toggle?.setAttribute("aria-expanded", "false");
    });
  });

  if (year) {
    year.textContent = new Date().getFullYear();
  }
});
