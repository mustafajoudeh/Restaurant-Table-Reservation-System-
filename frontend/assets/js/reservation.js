(() => {
  const $ = (id) => document.getElementById(id);

  let tables = [];
  let selectedTableId = null;
  let otpSent = false;
  let emailVerified = false;
  let pendingTimer = null;
  let resendAvailableAt = 0;
  let currentUser = null;
  let createdBookingId = null;
  let createdPaymentId = null;

  const friendlyError = (error, context) =>
    window.Api?.userMessage?.(error, context) ||
    "Something went wrong. Please try again shortly.";

  function setActiveStep(stepId) {
    ["stepChoose", "stepDetails", "stepVerification", "stepConfirm"].forEach(
      (id) => {
        const element = $(id);

        if (element && !element.hidden) {
          element.classList.toggle("active", id === stepId);
        }
      },
    );
  }

  function notice(message, type = "info") {
    const element = $("bookingNotice");
    if (!element) return;

    element.className = `notice show ${type}`;
    element.textContent = message;
  }

  function showTablesError(message) {
    const element = $("tablesError");
    if (!element) return;

    element.className = "notice show error";
    element.textContent = message;
  }

  function localSqlDateTime(date, time) {
    return `${date} ${time}:00`;
  }

  function addMinutes(date, time, minutes) {
    const start = new Date(`${date}T${time}:00`);
    start.setMinutes(start.getMinutes() + minutes);

    const pad = (number) => String(number).padStart(2, "0");

    return `${start.getFullYear()}-${pad(start.getMonth() + 1)}-${pad(start.getDate())} ${pad(start.getHours())}:${pad(start.getMinutes())}:00`;
  }

  function formatDateTime(date, time) {
    const value = new Date(`${date}T${time}:00`);

    return Number.isNaN(value.getTime())
      ? `${date} ${time}`
      : value.toLocaleString("en-GB", {
          weekday: "short",
          day: "numeric",
          month: "short",
          year: "numeric",
          hour: "numeric",
          minute: "2-digit",
        });
  }

  function setupUserMode() {
    currentUser = window.AppAuth?.currentUser?.() || null;

    const isAuthenticated = Boolean(currentUser?.userId);

    $("guestPanelTitle").textContent = isAuthenticated
      ? "Your booking details"
      : "Your details";

    $("guestIntro").textContent = isAuthenticated
      ? `Booking as ${currentUser.username || currentUser.email || "a member"}. Your account details will be used for this reservation.`
      : "Booking without an account? Enter your details and verify your email to continue.";

    $("verificationBadge").textContent = isAuthenticated
      ? "Signed in"
      : "Email verification";

    $("stepVerification").hidden = isAuthenticated;

    if ($("stepLineVerification")) {
      $("stepLineVerification").hidden = isAuthenticated;
    }

    $("continueButton").textContent = isAuthenticated
      ? "Continue to your details →"
      : "Continue as a guest →";

    [
      "guestNameField",
      "guestEmailField",
      "guestPhoneField",
      "otpCodeField",
    ].forEach((id) => {
      $(id).hidden = isAuthenticated;
    });

    $("sendOtpButton").hidden = isAuthenticated;
    $("verifyOtpButton").hidden = isAuthenticated;

    ["guestName", "guestEmail", "guestPhone", "otpCode"].forEach((id) => {
      const element = $(id);

      element.disabled = isAuthenticated;

      element.required =
        !isAuthenticated && ["guestName", "guestEmail"].includes(id);
    });

    $("createBookingButton").disabled = !isAuthenticated;
  }

  function updateResendCountdown() {
    if (pendingTimer) {
      clearInterval(pendingTimer);
      pendingTimer = null;
    }

    const update = () => {
      const seconds = Math.max(
        0,
        Math.ceil((resendAvailableAt - Date.now()) / 1000),
      );

      if (emailVerified) {
        $("sendOtpButton").disabled = true;
        $("sendOtpButton").textContent = "Email verified";
      } else if (seconds > 0) {
        $("sendOtpButton").disabled = true;
        $("sendOtpButton").textContent = `Resend code in ${seconds}s`;
      } else {
        $("sendOtpButton").disabled = false;
        $("sendOtpButton").textContent = otpSent
          ? "Resend verification code"
          : "Send verification code";

        if (pendingTimer) {
          clearInterval(pendingTimer);
          pendingTimer = null;
        }
      }
    };

    update();

    if (Date.now() < resendAvailableAt && !emailVerified) {
      pendingTimer = setInterval(update, 1000);
    }
  }

  function resetVerification() {
    otpSent = false;
    emailVerified = false;

    $("otpCode").value = "";
    $("otpCode").disabled = true;
    $("otpCode").readOnly = false;

    $("verifyOtpButton").disabled = true;
    $("verifyOtpButton").textContent = "Verify email";

    $("createBookingButton").disabled = !currentUser?.userId;

    resendAvailableAt = 0;
    updateResendCountdown();
  }

  function unlockGuestEmail() {
    $("guestEmail").readOnly = false;

    resetVerification();

    $("changeEmailButton").hidden = true;
    $("guestEmail").focus();

    setActiveStep("stepDetails");

    notice(
      "Enter the new email address, then verify it before confirming your reservation.",
      "info",
    );
  }

  function tableActive(table) {
    const status = String(table.status || "").toLowerCase();

    return (
      ["active", "available", "open"].includes(status) && table.id !== null
    );
  }

  function customerTableStatus(status) {
    const value = String(status || "")
      .trim()
      .toLowerCase();

    if (["active", "available", "open"].includes(value)) {
      return "Available";
    }

    if (["inactive", "unavailable", "closed", "disabled"].includes(value)) {
      return "Unavailable";
    }

    if (!value || value === "unknown") {
      return "Status unavailable";
    }

    return String(status);
  }

  function chooseTable(tableId) {
    const table = tables.find((item) => item.id === Number(tableId));

    if (!table || !tableActive(table)) return;

    selectedTableId = table.id;

    drawTables();
    renderSelectedTable();

    $("continueButton").disabled = false;

    renderSummary();
  }

  function drawTables() {
    const host = $("floorGrid");
    if (!host) return;

    if (!tables.length) {
      host.innerHTML =
        '<div class="empty-state">No tables are available to choose right now. Please try again shortly.</div>';

      $("tableCount").textContent = "No tables available";
      $("continueButton").disabled = true;

      return;
    }

    host.innerHTML = tables
      .map((table) => {
        const active = tableActive(table);
        const selected = selectedTableId === table.id;

        const capacityText =
          Number(table.capacity) > 0
            ? `${Api.escape(table.capacity)} seats`
            : "Capacity unavailable";

        const statusText = Api.escape(customerTableStatus(table.status));

        const feeText = Number.isFinite(table.bookingPrice)
          ? Api.money(table.bookingPrice)
          : "Ask restaurant";

        return `
          <button
            type="button"
            class="table-card${selected ? " selected" : ""}${active ? "" : " inactive"}"
            data-table-id="${Api.escape(table.id)}"
            ${active ? "" : "disabled"}
            aria-pressed="${selected ? "true" : "false"}"
            aria-label="Table ${Api.escape(table.number)}, ${capacityText}, ${statusText}"
          >
            <span class="table-card-top">
              <span class="table-card-number">${Api.escape(table.number)}</span>
              <span class="table-card-status">${statusText}</span>
            </span>
            <span class="table-card-meta">
              <span>${capacityText}</span>
            </span>
            <span class="table-card-price">
              <span>Reservation fee</span>
              <strong>${feeText}</strong>
            </span>
          </button>`;
      })
      .join("");

    host.querySelectorAll("[data-table-id]").forEach((button) => {
      button.addEventListener("click", () => {
        chooseTable(button.dataset.tableId);
      });
    });

    const availableCount = tables.filter(tableActive).length;

    $("tableCount").textContent =
      `${availableCount} available of ${tables.length}`;
  }

  function renderSelectedTable() {
    const table = tables.find((item) => item.id === selectedTableId);

    if (!table) return;

    $("tableRef").textContent = `TABLE ${table.number}`;
    $("tableName").textContent = `Table ${table.number}`;

    const details = [];

    if (Number(table.capacity) > 0) {
      details.push(`Seats up to ${table.capacity} people`);
    }

    if (table.view) {
      details.push(String(table.view));
    }

    $("tableDescription").textContent = details.length
      ? details.join(" · ")
      : "A comfortable place for your visit.";

    $("tableViewLabel").textContent = "";

    $("bookingPrice").textContent = Number.isFinite(table.bookingPrice)
      ? Api.money(table.bookingPrice)
      : "Ask restaurant";

    $("depositPrice").textContent = Number.isFinite(table.deposit)
      ? Api.money(table.deposit)
      : "Ask restaurant";

    $("tableStatus").textContent = customerTableStatus(table.status);

    $("totalPrice").textContent = Number.isFinite(table.bookingPrice)
      ? Api.money(table.bookingPrice)
      : "Ask restaurant";

    $("summaryTable").textContent = `Table ${table.number}`;

    $("continueButton").disabled = false;

    updateCapacityNote();
  }

  function updateCapacityNote() {
    const table = tables.find((item) => item.id === selectedTableId);

    const totalGuests = Number($("guests").value) + Number($("children").value);

    const overCapacity =
      table &&
      Number(table.capacity) > 0 &&
      totalGuests > Number(table.capacity);

    const note = $("availabilityNote");

    note.className = `notice ${overCapacity ? "error" : "info"}`;
    note.style.display = "block";

    note.textContent = overCapacity
      ? `This table seats up to ${table.capacity} people. Please reduce your group size or choose a larger table.`
      : "Choose a table to review its price and seating. We’ll check availability when you confirm your reservation.";

    $("continueButton").disabled =
      !table || overCapacity || !tableActive(table);
  }

  function renderSummary() {
    const date = $("date").value;
    const time = $("time").value;

    $("summaryTime").textContent = date
      ? formatDateTime(date, time)
      : "Choose a date";

    const adults = Number($("guests").value);
    const children = Number($("children").value);

    $("summaryGuests").textContent =
      `${adults} adult${adults === 1 ? "" : "s"} · ` +
      `${children} child${children === 1 ? "" : "ren"}`;

    updateCapacityNote();
  }

  async function loadTables() {
    $("tableCount").textContent = "Loading tables…";

    try {
      const response = await Api.get("/api/tables");

      tables = Api.getArray(response)
        .map((row, index) => Api.normalizeTable(row, index))
        .filter((table) => table.id !== null && Number.isInteger(table.id));

      drawTables();

      const available = tables.find(tableActive);

      if (available) {
        chooseTable(available.id);
      }

      if (!tables.length || !available) {
        showTablesError(
          "There are no tables available to book at the moment. Please check again later.",
        );
      }
    } catch (error) {
      console.error("Unable to load restaurant tables.", {
        status: error?.status,
      });

      $("floorGrid").innerHTML =
        '<div class="empty-state">We couldn’t load the tables right now. Please refresh the page or try again shortly.</div>';

      showTablesError(friendlyError(error, "tables"));

      $("tableCount").textContent = "Temporarily unavailable";
      $("continueButton").disabled = true;
    }
  }

  async function sendOtp() {
    const email = $("guestEmail").value.trim().toLowerCase();
    const name = $("guestName").value.trim();

    if (!name) {
      notice("Please enter your full name.", "error");
      $("guestName").focus();
      return;
    }

    if (!email || !$("guestEmail").checkValidity()) {
      notice("Please enter a valid email address.", "error");
      $("guestEmail").focus();
      return;
    }

    $("sendOtpButton").disabled = true;
    $("sendOtpButton").textContent = "Sending code…";

    try {
      await Api.otp({
        action: "send",
        email,
      });

      otpSent = true;
      emailVerified = false;

      $("guestEmail").readOnly = true;
      $("changeEmailButton").hidden = false;

      $("otpCode").value = "";
      $("otpCode").disabled = false;

      $("verifyOtpButton").disabled = false;
      $("verifyOtpButton").textContent = "Verify email";

      $("createBookingButton").disabled = true;

      resendAvailableAt = Date.now() + 60000;

      updateResendCountdown();
      setActiveStep("stepVerification");

      notice(
        "We’ve sent a verification code to your email. Check your inbox and spam folder.",
        "success",
      );

      $("otpCode").focus();
    } catch (error) {
      console.error("Could not send guest verification code.", {
        status: error?.status,
      });

      resendAvailableAt =
        Date.now() + ([429, 500].includes(error.status) ? 60000 : 0);

      updateResendCountdown();

      $("guestEmail").readOnly = false;

      notice(friendlyError(error, "otp"), "error");
    }
  }

  async function verifyOtp() {
    const email = $("guestEmail").value.trim().toLowerCase();
    const code = $("otpCode").value.trim();

    if (!otpSent) {
      return notice("Please request a verification code first.", "error");
    }

    if (!/^\d{6}$/.test(code)) {
      notice("Enter the 6-digit code we sent to your email.", "error");

      $("otpCode").focus();
      return;
    }

    $("verifyOtpButton").disabled = true;
    $("verifyOtpButton").textContent = "Verifying…";

    try {
      await Api.otp({
        action: "verify",
        email,
        code,
      });

      emailVerified = true;

      $("guestEmail").readOnly = true;
      $("changeEmailButton").hidden = false;

      $("otpCode").readOnly = true;

      $("sendOtpButton").disabled = true;
      $("sendOtpButton").textContent = "Email verified";

      $("createBookingButton").disabled = false;

      setActiveStep("stepVerification");

      notice(
        "Your email is verified. You can now confirm your reservation.",
        "success",
      );

      $("createBookingButton").focus();
    } catch (error) {
      console.error("Guest email verification failed.", {
        status: error?.status,
      });

      $("verifyOtpButton").disabled = false;
      $("verifyOtpButton").textContent = "Verify email";

      notice(friendlyError(error, "otp"), "error");
    }
  }

  async function createBooking(event) {
    event.preventDefault();

    if (!selectedTableId) {
      return notice("Please choose a table before continuing.", "error");
    }

    const isAuthenticated = Boolean(currentUser?.userId);

    if (!isAuthenticated && !emailVerified) {
      return notice(
        "Verify your email address before confirming your reservation.",
        "error",
      );
    }

    const date = $("date").value;
    const time = $("time").value;

    if (!date) {
      notice("Please choose a reservation date.", "error");
      $("date").focus();
      return;
    }

    const start = new Date(`${date}T${time}:00`);

    if (Number.isNaN(start.getTime()) || start.getTime() <= Date.now()) {
      notice("Please choose a future date and time.", "error");
      $("date").focus();
      return;
    }

    const table = tables.find((item) => item.id === selectedTableId);

    if (!table || !tableActive(table)) {
      return notice(
        "This table is no longer available. Please choose another table.",
        "error",
      );
    }

    const totalGuests = Number($("guests").value) + Number($("children").value);

    if (Number(table.capacity) > 0 && totalGuests > Number(table.capacity)) {
      return notice(
        `This table seats up to ${table.capacity} people. Please choose a larger table or reduce your group size.`,
        "error",
      );
    }

    const payload = {
      tableId: table.id,

      startDateTime: localSqlDateTime(date, time),

      endDateTime: addMinutes(
        date,
        time,
        window.APP_CONFIG.reservationDurationMinutes,
      ),

      numberOfIndividuals: Number($("guests").value),

      numberOfChildren: Number($("children").value),
    };

    if (!isAuthenticated) {
      payload.guestName = $("guestName").value.trim();
      payload.guestEmail = $("guestEmail").value.trim().toLowerCase();
      payload.guestPhone = $("guestPhone").value.trim();
    }

    const submitButton = $("createBookingButton");

    submitButton.disabled = true;
    submitButton.textContent = "Confirming reservation…";

    try {
      const result = await Api.post("/api/bookings", payload);

      const data = result.data || {};

      createdBookingId = data.bookingId ?? null;

      $("guestPanel").hidden = true;
      $("successPanel").hidden = false;

      setActiveStep("stepConfirm");

      const bookingStatus = String(data.status || "Pending").toLowerCase();

      const statusLabel =
        bookingStatus === "pending"
          ? "Awaiting confirmation"
          : bookingStatus.charAt(0).toUpperCase() + bookingStatus.slice(1);

      $("successDetails").innerHTML = `
        <div class="detail-price">
          <span>Booking reference</span>
          <strong>#${Api.escape(data.bookingId ?? "—")}</strong>
        </div>
        <div class="detail-price">
          <span>Table</span>
          <strong>${Api.escape(table.number)}</strong>
        </div>
        <div class="detail-price">
          <span>Status</span>
          <strong>${Api.escape(statusLabel)}</strong>
        </div>
        <div class="detail-price">
          <span>Date &amp; time</span>
          <strong>${Api.escape(formatDateTime(date, time))}</strong>
        </div>
        <div class="notice show success">
          Your reservation request has been received. Please keep your booking reference for your records.
        </div>`;

      $("paymentPanel").hidden = !createdBookingId;

      $("paymentNotice").className = "notice";
      $("paymentNotice").textContent = "";

      $("onlineSimulationControls").hidden = true;

      createdPaymentId = null;

      $("paymentMethod").disabled = false;
      $("createPaymentButton").disabled = false;
      $("createPaymentButton").textContent = "Continue to deposit";

      $("successPanel").scrollIntoView({
        behavior: "smooth",
        block: "start",
      });
    } catch (error) {
      console.error("Reservation could not be created.", {
        status: error?.status,
      });

      submitButton.disabled = false;
      submitButton.textContent = "Confirm booking";

      notice(friendlyError(error, "booking"), "error");
    }
  }

  async function createDepositPayment() {
    if (!createdBookingId) {
      $("paymentNotice").className = "notice show error";

      $("paymentNotice").textContent =
        "Please create your reservation before continuing to the deposit.";

      return;
    }

    const button = $("createPaymentButton");
    const output = $("paymentNotice");
    const method = $("paymentMethod").value;

    button.disabled = true;

    output.className = "notice show info";
    output.textContent = "Preparing your deposit…";

    try {
      const result = await Api.post("/api/payments", {
        bookingId: Number(createdBookingId),
        paymentMethod: method,
      });

      const payment = result.data || {};

      const paymentId = Api.pick(payment, ["paymentId", "PaymentID"], null);

      createdPaymentId = paymentId !== null ? Number(paymentId) : null;

      if (
        method === "Online" &&
        (!Number.isInteger(createdPaymentId) || createdPaymentId <= 0)
      ) {
        output.className = "notice show error";

        output.textContent =
          "Your deposit request was saved, but we couldn’t start the online demo. Please contact the restaurant for help.";

        button.textContent = "Deposit request saved";
        button.disabled = true;

        $("paymentMethod").disabled = true;
        $("onlineSimulationControls").hidden = true;

        return;
      }

      if (method === "Cash") {
        output.className = "notice show success";

        output.textContent =
          "Your deposit option has been saved. The restaurant team will confirm receipt when your deposit is paid in person.";

        button.textContent = "Deposit option saved";

        $("paymentMethod").disabled = true;
        button.disabled = true;

        $("onlineSimulationControls").hidden = true;
      } else {
        output.className = "notice show success";

        output.textContent =
          "Your online demo is ready. No money has been charged. Choose a demo outcome below to try the payment flow.";

        button.textContent = "Online demo ready";

        $("paymentMethod").disabled = true;
        button.disabled = true;

        $("onlineSimulationControls").hidden = false;

        $("simulateOnlineButton").disabled = false;
        $("simulateOnlineButton").textContent = "Run demo payment";
      }
    } catch (error) {
      console.error("Could not prepare reservation deposit.", {
        status: error?.status,
      });

      output.className = "notice show error";
      output.textContent = friendlyError(error, "payment");

      button.disabled = false;
    }
  }

  async function simulateOnlinePayment() {
    const output = $("paymentNotice");
    const button = $("simulateOnlineButton");

    if (!createdPaymentId) {
      output.className = "notice show error";

      output.textContent =
        "We couldn’t start the demo payment for this reservation. Please refresh and try again.";

      return;
    }

    button.disabled = true;

    output.className = "notice show info";
    output.textContent = "Completing the demo payment…";

    try {
      const result = await Api.post(
        `/api/payments/${createdPaymentId}/simulate-online`,
        {
          result: $("onlineResult").value,
        },
      );

      const payment = result.data || {};

      const status = String(
        Api.pick(
          payment,
          ["paymentStatus", "PaymentStatus"],
          $("onlineResult").value === "success" ? "Completed" : "Failed",
        ),
      ).toLowerCase();

      const succeeded = status === "completed";

      output.className = `notice show ${succeeded ? "success" : "error"}`;

      output.textContent = succeeded
        ? "Your demo payment is complete. No money has been charged."
        : "The demo payment was not completed. No money has been charged.";

      button.textContent = "Demo finished";
    } catch (error) {
      console.error("Demo payment could not be completed.", {
        status: error?.status,
      });

      output.className = "notice show error";
      output.textContent = friendlyError(error, "payment");

      button.disabled = false;
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    setupUserMode();

    const dateInput = $("date");
    const today = new Date();

    const pad = (number) => String(number).padStart(2, "0");

    dateInput.min = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;

    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);

    dateInput.value = `${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}`;

    ["date", "time", "guests", "children"].forEach((id) => {
      $(id).addEventListener("change", renderSummary);
    });

    $("continueButton").addEventListener("click", () => {
      if (!selectedTableId) return;

      const table = tables.find((item) => item.id === selectedTableId);

      if (!table || !tableActive(table)) {
        showTablesError(
          "This table is no longer available. Please choose another table.",
        );

        return;
      }

      const totalGuests =
        Number($("guests").value) + Number($("children").value);

      if (Number(table.capacity) > 0 && totalGuests > Number(table.capacity)) {
        updateCapacityNote();
        return;
      }

      $("guestPanel").hidden = false;

      setActiveStep("stepDetails");

      $("guestPanel").scrollIntoView({
        behavior: "smooth",
        block: "start",
      });
    });

    $("sendOtpButton").addEventListener("click", sendOtp);
    $("verifyOtpButton").addEventListener("click", verifyOtp);

    $("guestForm").addEventListener("submit", createBooking);

    $("guestEmail").addEventListener("input", () => {
      if (emailVerified || otpSent) {
        resetVerification();
      }
    });

    $("changeEmailButton").addEventListener("click", unlockGuestEmail);

    $("createPaymentButton").addEventListener("click", createDepositPayment);

    $("simulateOnlineButton").addEventListener("click", simulateOnlinePayment);

    $("paymentMethod").addEventListener("change", () => {
      if (!createdPaymentId) {
        $("paymentNotice").className = "notice";
        $("paymentNotice").textContent = "";

        $("onlineSimulationControls").hidden = true;

        $("createPaymentButton").disabled = false;
        $("createPaymentButton").textContent = "Continue to deposit";
      }
    });

    const mobileToggle = $("mobileToggle");
    const mainNav = $("mainNav");

    mobileToggle?.addEventListener("click", () => {
      const open = mainNav?.classList.toggle("open") || false;

      mobileToggle.setAttribute("aria-expanded", String(open));
    });

    mainNav?.querySelectorAll("a").forEach((link) => {
      link.addEventListener("click", () => {
        mainNav.classList.remove("open");

        mobileToggle?.setAttribute("aria-expanded", "false");
      });
    });

    $("year").textContent = new Date().getFullYear();

    renderSummary();
    loadTables();
  });
})();
