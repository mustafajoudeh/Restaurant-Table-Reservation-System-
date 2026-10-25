(() => {
  const USER_KEY = "summitTerraceUser";

  function currentUser() {
    try {
      const raw = sessionStorage.getItem(USER_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  function saveUser(user) {
    // The stored user is for interface display only.
    // The PHP session on the server remains authoritative.
    sessionStorage.setItem(
      USER_KEY,
      JSON.stringify({
        userId: user?.userId ?? null,
        role: user?.role ?? "Customer",
        username: user?.username ?? "",
        email: user?.email ?? "",
      }),
    );
  }

  function clearUser() {
    sessionStorage.removeItem(USER_KEY);
  }

  window.AppAuth = {
    currentUser,
    saveUser,
    clearUser,
  };

  document.addEventListener("DOMContentLoaded", () => {
    const loginForm = document.getElementById("loginForm");
    const registerForm = document.getElementById("registerForm");
    const notice = document.getElementById("authNotice");

    function showNotice(message, type = "info") {
      if (!notice) return;

      notice.className = `notice show ${type}`;
      notice.textContent = message;
    }

    function setBusy(form, busy, busyText = "Please wait…") {
      const button = form?.querySelector('button[type="submit"]');

      if (!button) return;

      if (busy) {
        button.dataset.originalText ??= button.textContent.trim();
        button.disabled = true;
        button.textContent = busyText;
        return;
      }

      button.disabled = false;

      if (button.dataset.originalText) {
        button.textContent = button.dataset.originalText;
        delete button.dataset.originalText;
      }
    }

    function friendlyError(error, context) {
      return (
        window.Api?.userMessage?.(error, context) ||
        "Something went wrong. Please try again shortly."
      );
    }

    const params = new URLSearchParams(window.location.search);

    if (params.get("registered") === "1") {
      showNotice(
        "Your account has been created. Sign in to start making reservations.",
        "success",
      );
    }

    if (loginForm) {
      loginForm.addEventListener("submit", async (event) => {
        event.preventDefault();

        const emailInput = document.getElementById("email");
        const passwordInput = document.getElementById("password");

        const email = emailInput?.value.trim() || "";
        const password = passwordInput?.value || "";

        if (!email || !password) {
          showNotice(
            "Enter your email address and password to continue.",
            "error",
          );

          (!email ? emailInput : passwordInput)?.focus();
          return;
        }

        if (!emailInput?.checkValidity()) {
          showNotice("Enter a valid email address.", "error");
          emailInput?.focus();
          return;
        }

        setBusy(loginForm, true, "Signing in…");

        try {
          const result = await Api.post("/api/users/login", {
            email,
            password,
          });

          const user = result.data || {};

          if (!user.userId || !user.role) {
            console.error("Sign-in response was incomplete.");
            throw new Error("SIGN_IN_RESPONSE_INCOMPLETE");
          }

          saveUser(user);

          showNotice("Welcome back. Taking you to your account…", "success");

          window.location.href =
            String(user.role).toLowerCase() === "admin"
              ? "admin/index.html"
              : "reservation.html";
        } catch (error) {
          console.error("Sign-in failed.", {
            status: error?.status,
          });

          const message =
            error?.message === "SIGN_IN_RESPONSE_INCOMPLETE"
              ? "We couldn’t sign you in right now. Please try again."
              : friendlyError(error, "login");

          showNotice(message, "error");
          setBusy(loginForm, false);
        }
      });
    }

    if (registerForm) {
      registerForm.addEventListener("submit", async (event) => {
        event.preventDefault();

        const usernameInput = document.getElementById("username");
        const emailInput = document.getElementById("email");
        const passwordInput = document.getElementById("password");
        const confirmInput = document.getElementById("confirmPassword");

        const username = usernameInput?.value.trim() || "";
        const email = emailInput?.value.trim() || "";
        const password = passwordInput?.value || "";
        const confirmPassword = confirmInput?.value || "";

        const phoneNumber =
          document.getElementById("phoneNumber")?.value.trim() || "";

        if (!username || !email || !password) {
          showNotice(
            "Complete your username, email address, and password.",
            "error",
          );

          (!username
            ? usernameInput
            : !email
              ? emailInput
              : passwordInput
          )?.focus();

          return;
        }

        if (!emailInput?.checkValidity()) {
          showNotice("Enter a valid email address.", "error");
          emailInput?.focus();
          return;
        }

        if (password.length < 8) {
          showNotice("Choose a password with at least 8 characters.", "error");

          passwordInput?.focus();
          return;
        }

        if (password !== confirmPassword) {
          showNotice(
            "Your passwords don’t match. Please check them and try again.",
            "error",
          );

          confirmInput?.focus();
          return;
        }

        setBusy(registerForm, true, "Creating your account…");

        try {
          await Api.post("/api/users/register", {
            username,
            email,
            password,
            phoneNumber: phoneNumber || null,
          });

          window.location.href = "login.html?registered=1";
        } catch (error) {
          console.error("Account registration failed.", {
            status: error?.status,
          });

          showNotice(friendlyError(error, "register"), "error");
          setBusy(registerForm, false);
        }
      });
    }
  });
})();
