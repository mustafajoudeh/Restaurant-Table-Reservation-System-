(() => {
  const USER_KEY = "summitTerraceUser";

  function isSignedIn() {
    try {
      const user = JSON.parse(sessionStorage.getItem(USER_KEY) || "null");

      return Boolean(user?.userId);
    } catch {
      return false;
    }
  }

  function renderAuthControls() {
    const signedIn = isSignedIn();

    document
      .querySelectorAll(".nav-signin, .footer-auth-link")
      .forEach((link) => {
        if (signedIn) {
          link.textContent = "Logout";
          link.href = "#logout";
          link.dataset.authAction = "logout";
          link.setAttribute("aria-label", "Log out of your account");
        } else {
          link.textContent = "Sign in";
          link.href = "login.html";

          delete link.dataset.authAction;
          link.removeAttribute("aria-label");
        }
      });
  }

  document.addEventListener("DOMContentLoaded", () => {
    renderAuthControls();

    document.addEventListener("click", async (event) => {
      const logoutLink = event.target.closest('[data-auth-action="logout"]');

      if (!logoutLink) return;

      event.preventDefault();

      if (logoutLink.dataset.busy === "true") return;

      logoutLink.dataset.busy = "true";
      logoutLink.textContent = "Logging out…";

      try {
        const response = await fetch(window.APP_CONFIG.logoutUrl, {
          method: "POST",
          credentials: "include",
          headers: {
            Accept: "application/json",
          },
        });

        const result = await response.json();

        if (!response.ok || result.success !== true) {
          throw new Error("Logout failed");
        }

        // Clear the front-end login state.
        sessionStorage.removeItem(USER_KEY);

        // Return to the home page.
        window.location.href = "index.html";
      } catch (error) {
        console.error("Logout failed.");

        delete logoutLink.dataset.busy;
        logoutLink.textContent = "Logout";

        alert("We couldn’t sign you out right now. Please try again.");
      }
    });
  });
})();
