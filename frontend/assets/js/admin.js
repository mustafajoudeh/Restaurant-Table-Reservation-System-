(() => {
  const $ = (id) => document.getElementById(id);

  const current =
    window.location.pathname.split("/").pop().toLowerCase() || "index.html";

  let allBookings = [];
  let allPayments = [];

  const navItems = [
    ["index.html", "⌂", "Dashboard"],
    ["bookings.html", "▤", "Bookings"],
    ["tables.html", "▦", "Tables"],
    ["payments.html", "↔", "Payments"],
  ];

  function renderSidebar() {
    const host = $("adminSidebar");
    if (!host) return;

    host.innerHTML = `
      <a class="admin-brand" href="index.html">
        <span class="brand-mark">S</span>
        <span>
          <span class="brand-name">Summit Terrace</span>
          <span class="brand-sub">Administration</span>
        </span>
      </a>

      <div>
        <div class="admin-label">Manage</div>
        <nav class="admin-nav" aria-label="Admin navigation">
          ${navItems
            .map(([file, icon, label]) => {
              const active =
                current === file ||
                (file === "bookings.html" &&
                  current === "booking-details.html") ||
                (file === "payments.html" &&
                  current === "payment-details.html");

              return `
                <a class="${active ? "active" : ""}" href="${file}">
                  <span class="icon" aria-hidden="true">${icon}</span>
                  ${label}
                </a>`;
            })
            .join("")}
        </nav>
      </div>

      <div class="admin-sidebar-bottom">
        <div>SUMMIT TERRACE</div>
        <div style="margin-top:6px">Management workspace</div>
        <a href="../index.html" style="display:inline-block;margin-top:13px">
          ← Public website
        </a>
      </div>`;
  }

  function showAlert(message, type = "error") {
    const element = $("adminAlert");
    if (!element) return;

    element.className = `admin-alert show ${type}`;
    element.textContent = message;
  }

  function clearAlert() {
    const element = $("adminAlert");

    if (element) {
      element.className = "admin-alert";
      element.textContent = "";
    }
  }

  const tableStatus = (table) =>
    ["active", "available", "open"].includes(
      String(table.status || "").toLowerCase(),
    );

  function statusPill(status) {
    const value = String(status || "Unknown");
    const normalized = value.toLowerCase();

    let klass = "";

    if (/cancel|failed|no-show|rejected/.test(normalized)) {
      klass = "cancelled";
    } else if (/pending/.test(normalized)) {
      klass = "pending";
    }

    return `
      <span class="status-pill ${klass}">
        ${Api.escape(value)}
      </span>`;
  }

  /* ----------------------------------------------------------
     BOOKINGS
     ---------------------------------------------------------- */

  function renderBookings() {
    const host = $("bookingsTable") || $("recentBookings");
    if (!host) return;

    if (!allBookings.length) {
      host.innerHTML = `
        <div class="admin-empty">
          No reservations yet. New bookings will appear here.
        </div>`;
      return;
    }

    const filtered =
      current === "bookings.html"
        ? filterBookings(allBookings)
        : allBookings.slice(0, 6);

    if (!filtered.length) {
      host.innerHTML = `
        <div class="admin-empty">
          No reservations match your search. Try changing the search or filters.
        </div>`;
      return;
    }

    const showActions = current === "bookings.html";

    host.innerHTML = `
      <table class="data-table">
        <thead>
          <tr>
            <th>Booking</th>
            <th>Table</th>
            <th>Reservation</th>
            <th>Guests</th>
            <th>Status</th>
            ${showActions ? "<th>Actions</th>" : ""}
          </tr>
        </thead>

        <tbody>
          ${filtered
            .map(
              (booking) => `
                <tr>
                  <td>
                    <strong>#${Api.escape(booking.id)}</strong>
                    <span class="subcell">
                      ${
                        booking.userId === null || booking.userId === ""
                          ? "Guest reservation"
                          : `Member account #${Api.escape(booking.userId)}`
                      }
                    </span>
                  </td>

                  <td>Table ${Api.escape(booking.tableId)}</td>

                  <td>
                    <strong>${Api.escape(Api.formatDate(booking.start))}</strong>
                    <span class="subcell">
                      to ${Api.escape(Api.formatDate(booking.end))}
                    </span>
                  </td>

                  <td>
                    ${Api.escape(booking.individuals)} adults
                    <span class="subcell">
                      ${Api.escape(booking.children)} children
                    </span>
                  </td>

                  <td>${statusPill(booking.status)}</td>

                  ${
                    showActions
                      ? `
                        <td>
                          <div class="admin-action-row">
                            <a
                              class="btn btn-outline btn-small"
                              href="booking-details.html?id=${encodeURIComponent(booking.id)}"
                            >
                              Details / edit
                            </a>

                            ${
                              !/cancelled|completed|no-show/i.test(
                                String(booking.status),
                              )
                                ? `
                                  <button
                                    class="btn btn-danger btn-small"
                                    type="button"
                                    data-cancel="${Api.escape(booking.id)}"
                                  >
                                    Cancel
                                  </button>`
                                : ""
                            }
                          </div>
                        </td>`
                      : ""
                  }
                </tr>`,
            )
            .join("")}
        </tbody>
      </table>`;
  }

  function filterBookings(rows) {
    const search = ($("bookingSearch")?.value || "").trim().toLowerCase();

    const selectedStatus = (
      $("bookingStatusFilter")?.value || ""
    ).toLowerCase();

    return rows.filter((booking) => {
      const haystack = [
        booking.id,
        booking.tableId,
        booking.userId,
        booking.status,
        booking.guestName,
        booking.guestEmail,
        booking.start,
      ]
        .join(" ")
        .toLowerCase();

      return (
        (!search || haystack.includes(search)) &&
        (!selectedStatus ||
          String(booking.status).toLowerCase() === selectedStatus)
      );
    });
  }

  async function loadBookings() {
    const host = $("bookingsTable") || $("recentBookings");
    if (!host) return [];

    host.innerHTML = `
      <div class="loading">Loading reservations…</div>`;

    try {
      const response = await Api.get("/api/bookings");

      allBookings = Api.getArray(response).map(Api.normalizeBooking);

      if ($("metricBookings")) {
        $("metricBookings").textContent = allBookings.length;
      }

      if ($("metricPending")) {
        $("metricPending").textContent = allBookings.filter((booking) =>
          /pending/i.test(String(booking.status)),
        ).length;
      }

      renderBookings();
      return allBookings;
    } catch (error) {
      console.error("Unable to load reservations.", {
        status: error?.status,
      });

      host.innerHTML = `
        <div class="admin-empty">
          We couldn’t load reservations right now. Please refresh the page or try again shortly.
        </div>`;

      showAlert(Api.userMessage(error, "admin"));

      if ($("metricBookings")) {
        $("metricBookings").textContent = "—";
      }

      if ($("metricPending")) {
        $("metricPending").textContent = "—";
      }

      return [];
    }
  }

  /* ----------------------------------------------------------
     TABLES
     ---------------------------------------------------------- */

  async function loadTables() {
    const host = $("tablesTable");

    if (!host && !$("metricTables") && !$("tableMetricTotal")) {
      return [];
    }

    if (host) {
      host.innerHTML = `
        <div class="loading">Loading tables…</div>`;
    }

    try {
      const response = await Api.get("/api/tables");

      const rows = Api.getArray(response)
        .map((row, index) => Api.normalizeTable(row, index))
        .filter((table) => table.id !== null);

      if ($("metricTables")) {
        $("metricTables").textContent = rows.length;
      }

      if ($("tableMetricTotal")) {
        $("tableMetricTotal").textContent = rows.length;
      }

      if ($("tableMetricActive")) {
        $("tableMetricActive").textContent = rows.filter(tableStatus).length;
      }

      if ($("tableMetricCapacity")) {
        $("tableMetricCapacity").textContent = rows.length
          ? (
              rows.reduce(
                (sum, table) => sum + (Number(table.capacity) || 0),
                0,
              ) / rows.length
            ).toFixed(1)
          : "—";
      }

      if (!rows.length) {
        if (host) {
          host.innerHTML = `
            <div class="admin-empty">
              No tables are available to display yet.
            </div>`;
        }

        return rows;
      }

      if (host) {
        host.innerHTML = `
          <table class="data-table">
            <thead>
              <tr>
                <th>Table</th>
                <th>Capacity</th>
                <th>Booking fee</th>
                <th>Deposit</th>
                <th>Status</th>
              </tr>
            </thead>

            <tbody>
              ${rows
                .map(
                  (table) => `
                    <tr>
                      <td>
                        <strong>Table ${Api.escape(table.number)}</strong>
                        <span class="subcell">
                          ID ${Api.escape(table.id)}
                        </span>
                      </td>

                      <td>
                        ${
                          table.capacity > 0
                            ? Api.escape(table.capacity)
                            : "Not provided"
                        }
                      </td>

                      <td>${Api.money(table.bookingPrice)}</td>
                      <td>${Api.money(table.deposit)}</td>
                      <td>${statusPill(table.status)}</td>
                    </tr>`,
                )
                .join("")}
            </tbody>
          </table>`;
      }

      return rows;
    } catch (error) {
      console.error("Unable to load tables.", {
        status: error?.status,
      });

      if (host) {
        host.innerHTML = `
          <div class="admin-empty">
            We couldn’t load the tables right now. Please refresh the page or try again shortly.
          </div>`;
      }

      showAlert(Api.userMessage(error, "admin"));

      if ($("metricTables")) {
        $("metricTables").textContent = "—";
      }

      return [];
    }
  }

  /* ----------------------------------------------------------
     PAYMENTS
     ---------------------------------------------------------- */

  function paymentValue(row, keys, fallback = "—") {
    return Api.pick(row, keys, fallback);
  }

  function paymentActionMarkup(row) {
    const kind = String(
      paymentValue(row, ["transactionType", "TransactionType"], ""),
    ).toLowerCase();

    const method = String(
      paymentValue(row, ["paymentMethod", "PaymentMethod"], ""),
    ).toLowerCase();

    const state = String(
      paymentValue(row, ["paymentStatus", "PaymentStatus"], ""),
    ).toLowerCase();

    const id = paymentValue(row, ["paymentId", "PaymentID"], null);

    if (state !== "pending") {
      return '<span class="subcell">No action needed</span>';
    }

    if (kind === "deposit" && method === "cash") {
      return `
        <button
          class="btn btn-small"
          type="button"
          data-payment-action="confirm-cash"
          data-payment-id="${Api.escape(id)}"
        >
          Confirm cash
        </button>`;
    }

    if (kind === "refund") {
      return `
        <button
          class="btn btn-small"
          type="button"
          data-payment-action="confirm-refund"
          data-payment-id="${Api.escape(id)}"
        >
          Confirm refund
        </button>`;
    }

    return '<span class="subcell">No action needed</span>';
  }

  function renderPayments() {
    const host = $("paymentsTable");
    if (!host) return;

    if (!allPayments.length) {
      host.innerHTML = `
        <div class="admin-empty">
          No payment records to display yet.
        </div>`;
      return;
    }

    host.innerHTML = `
      <table class="data-table">
        <thead>
          <tr>
            <th>Payment</th>
            <th>Booking</th>
            <th>Type</th>
            <th>Amount</th>
            <th>Method</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>

        <tbody>
          ${allPayments
            .map((row) => {
              const id = paymentValue(row, ["paymentId", "PaymentID"]);

              return `
                <tr>
                  <td>
                    <strong>
                      <a
                        class="text-link"
                        href="payment-details.html?id=${encodeURIComponent(id)}"
                      >
                        #${Api.escape(id)}
                      </a>
                    </strong>

                    <span class="subcell">
                      ${Api.escape(
                        paymentValue(
                          row,
                          ["transactionReference", "TransactionReference"],
                          "",
                        ),
                      )}
                    </span>
                  </td>

                  <td>
                    #${Api.escape(
                      paymentValue(row, ["bookingId", "BookingID"]),
                    )}
                  </td>

                  <td>
                    ${Api.escape(
                      paymentValue(row, ["transactionType", "TransactionType"]),
                    )}
                  </td>

                  <td>
                    ${Api.money(paymentValue(row, ["amount", "Amount"], NaN))}
                  </td>

                  <td>
                    ${Api.escape(
                      paymentValue(row, ["paymentMethod", "PaymentMethod"]),
                    )}
                  </td>

                  <td>
                    ${statusPill(
                      paymentValue(row, ["paymentStatus", "PaymentStatus"]),
                    )}
                  </td>

                  <td>
                    <div class="admin-action-row">
                      ${paymentActionMarkup(row)}
                    </div>
                  </td>
                </tr>`;
            })
            .join("")}
        </tbody>
      </table>`;
  }

  async function loadPayments() {
    const host = $("paymentsTable");

    if (!host && !$("metricPayments")) {
      return [];
    }

    if (host) {
      host.innerHTML = `
        <div class="loading">Loading payment details…</div>`;
    }

    try {
      const response = await Api.get("/api/payments");

      allPayments = Api.getArray(response);

      if ($("metricPayments")) {
        $("metricPayments").textContent = allPayments.length;
      }

      renderPayments();
      return allPayments;
    } catch (error) {
      console.error("Unable to load payments.", {
        status: error?.status,
      });

      if (host) {
        host.innerHTML = `
          <div class="admin-empty">
            We couldn’t load payments right now. Please refresh the page or try again shortly.
          </div>`;
      }

      showAlert(Api.userMessage(error, "admin"));

      if ($("metricPayments")) {
        $("metricPayments").textContent = "—";
      }

      return [];
    }
  }

  async function runPaymentAction(action, paymentId) {
    const id = Number(paymentId);

    if (!Number.isInteger(id) || id <= 0) {
      showAlert(
        "This payment could not be found. Refresh the page and try again.",
      );
      return;
    }

    const isRefund = action === "confirm-refund";

    const question = isRefund
      ? `Mark refund #${id} as completed? Confirm only after the refund has been processed.`
      : `Confirm cash payment #${id}? Confirm only after the cash has been received.`;

    if (!window.confirm(question)) return;

    const route = isRefund
      ? `/api/payments/${id}/confirm-refund`
      : `/api/payments/${id}/confirm-cash`;

    try {
      await Api.post(route, {});

      showAlert(
        isRefund ? "Refund marked as completed." : "Cash payment confirmed.",
        "info",
      );

      await loadPayments();

      if (current === "payment-details.html") {
        await loadPaymentDetails();
      }
    } catch (error) {
      showAlert(Api.userMessage(error, "admin"));
    }
  }

  /* ----------------------------------------------------------
     CANCELLING A RESERVATION
     ---------------------------------------------------------- */

  async function cancelBooking(id) {
    const confirmed = window.confirm(
      `Cancel reservation #${id}? Any applicable cancellation and refund rules will be applied.`,
    );

    if (!confirmed) return;

    try {
      await Api.post(`/api/bookings/${encodeURIComponent(id)}/cancel`, {});

      showAlert(`Reservation #${id} has been cancelled.`, "info");

      await loadBookings();
    } catch (error) {
      showAlert(Api.userMessage(error, "admin"));
    }
  }

  function toDateTimeLocal(value) {
    const input = String(value || "").replace(" ", "T");

    return input.length >= 16 ? input.slice(0, 16) : input;
  }

  function fromDateTimeLocal(value) {
    if (!value) return "";

    return value.length === 16
      ? `${value.replace("T", " ")}:00`
      : value.replace("T", " ");
  }

  /* ----------------------------------------------------------
     RESERVATION DETAILS
     ---------------------------------------------------------- */

  async function loadBookingDetails() {
    const id = Number(new URLSearchParams(window.location.search).get("id"));

    const host = $("bookingDetailState");
    const form = $("bookingDetailsForm");

    if (!Number.isInteger(id) || id <= 0) {
      const message =
        "This reservation could not be found. Return to Bookings and select it again.";

      if (host) host.textContent = message;
      if (form) form.hidden = true;

      showAlert(message);
      return;
    }

    if (host) {
      host.textContent = "Loading reservation details…";
    }

    if (form) {
      form.hidden = true;
    }

    try {
      const [bookingResponse, tablesResponse] = await Promise.all([
        Api.get(`/api/bookings/${id}`),
        Api.get("/api/tables"),
      ]);

      const booking = bookingResponse.data || {};

      const tables = Api.getArray(tablesResponse)
        .map((row, index) => Api.normalizeTable(row, index))
        .filter((table) => table.id !== null);

      const tableSelect = $("detailTableId");

      tableSelect.innerHTML = tables
        .map(
          (table) => `
            <option value="${Api.escape(table.id)}">
              Table ${Api.escape(table.number)}
              · ID ${Api.escape(table.id)}
              · capacity ${table.capacity || "not supplied"}
            </option>`,
        )
        .join("");

      $("detailBookingId").textContent = `#${Api.escape(
        Api.pick(booking, ["bookingId", "BookingID"], id),
      )}`;

      $("detailBookingStatus").innerHTML = statusPill(
        Api.pick(booking, ["status", "Status"], "Unknown"),
      );

      tableSelect.value = String(Api.pick(booking, ["tableId", "TableID"], ""));

      $("detailStart").value = toDateTimeLocal(
        Api.pick(booking, ["startDateTime", "StartDateTime"], ""),
      );

      $("detailEnd").value = toDateTimeLocal(
        Api.pick(booking, ["endDateTime", "EndDateTime"], ""),
      );

      $("detailIndividuals").value = Number(
        Api.pick(booking, ["numberOfIndividuals", "NumberOfIndividuals"], 1),
      );

      $("detailChildren").value = Number(
        Api.pick(booking, ["numberOfChildren", "NumberOfChildren"], 0),
      );

      const userId = Api.pick(booking, ["userId", "UserID"], null);

      $("detailUserId").textContent =
        userId === null
          ? "Guest reservation"
          : `Member account #${Api.escape(userId)}`;

      if (host) {
        host.textContent =
          "Reservation details loaded. Guest contact information may not be available for every booking.";
      }

      if (form) {
        form.hidden = false;
      }

      if ($("detailCancelButton")) {
        $("detailCancelButton").hidden = /cancelled|completed|no-show/i.test(
          String(Api.pick(booking, ["status", "Status"], "")),
        );
      }
    } catch (error) {
      console.error("Unable to load reservation details.", {
        status: error?.status,
      });

      if (host) {
        host.textContent =
          "We couldn’t load this reservation. Please return to Bookings and try again.";
      }

      showAlert(Api.userMessage(error, "admin"));
    }
  }

  async function saveBookingDetails(event) {
    event.preventDefault();

    const id = Number(new URLSearchParams(window.location.search).get("id"));

    const startValue = $("detailStart").value;
    const endValue = $("detailEnd").value;

    const payload = {
      tableId: Number($("detailTableId").value),
      startDateTime: fromDateTimeLocal(startValue),
      endDateTime: fromDateTimeLocal(endValue),
      numberOfIndividuals: Number($("detailIndividuals").value),
      numberOfChildren: Number($("detailChildren").value),
    };

    if (
      !startValue ||
      !endValue ||
      !Number.isInteger(payload.tableId) ||
      payload.tableId <= 0
    ) {
      showAlert("Choose a table, start time, and end time.");
      return;
    }

    if (
      !Number.isInteger(payload.numberOfIndividuals) ||
      payload.numberOfIndividuals < 1 ||
      !Number.isInteger(payload.numberOfChildren) ||
      payload.numberOfChildren < 0
    ) {
      showAlert("Enter a valid guest count.");
      return;
    }

    const button = $("saveBookingButton");

    button.disabled = true;
    button.textContent = "Saving…";

    try {
      await Api.put(`/api/bookings/${id}`, payload);

      showAlert("Reservation changes have been saved.", "info");

      await loadBookingDetails();
    } catch (error) {
      showAlert(Api.userMessage(error, "admin"));
    } finally {
      button.disabled = false;
      button.textContent = "Save changes";
    }
  }

  async function cancelBookingFromDetails() {
    const id = Number(new URLSearchParams(window.location.search).get("id"));

    if (!Number.isInteger(id) || id <= 0) {
      return;
    }

    const confirmed = window.confirm(
      `Cancel reservation #${id}? Any applicable cancellation and refund rules will be applied.`,
    );

    if (!confirmed) return;

    try {
      await Api.post(`/api/bookings/${id}/cancel`, {});

      showAlert("Reservation has been cancelled.", "info");

      await loadBookingDetails();
    } catch (error) {
      showAlert(Api.userMessage(error, "admin"));
    }
  }

  /* ----------------------------------------------------------
     PAYMENT DETAILS
     ---------------------------------------------------------- */

  async function loadPaymentDetails() {
    const id = Number(new URLSearchParams(window.location.search).get("id"));

    const host = $("paymentDetailState");

    if (!Number.isInteger(id) || id <= 0) {
      const message =
        "This payment could not be found. Return to Payments and select it again.";

      if (host) {
        host.textContent = message;
      }

      showAlert(message);
      return;
    }

    if (host) {
      host.textContent = "Loading payment details…";
    }

    try {
      const response = await Api.get(`/api/payments/${id}`);

      const payment = response.data || {};

      const val = (keys, fallback = "—") =>
        paymentValue(payment, keys, fallback);

      $("paymentDetailId").textContent =
        `#${Api.escape(val(["paymentId", "PaymentID"], id))}`;

      $("paymentDetailStatus").innerHTML = statusPill(
        val(["paymentStatus", "PaymentStatus"], "Unknown"),
      );

      $("paymentDetailBooking").textContent =
        `#${Api.escape(val(["bookingId", "BookingID"]))}`;

      $("paymentDetailType").textContent = Api.escape(
        val(["transactionType", "TransactionType"]),
      );

      $("paymentDetailAmount").textContent = Api.money(
        val(["amount", "Amount"], NaN),
      );

      $("paymentDetailMethod").textContent = Api.escape(
        val(["paymentMethod", "PaymentMethod"]),
      );

      $("paymentDetailReference").textContent = Api.escape(
        val(["transactionReference", "TransactionReference"]),
      );

      const pending =
        String(val(["paymentStatus", "PaymentStatus"], "")).toLowerCase() ===
        "pending";

      const kind = String(
        val(["transactionType", "TransactionType"], ""),
      ).toLowerCase();

      const method = String(
        val(["paymentMethod", "PaymentMethod"], ""),
      ).toLowerCase();

      $("paymentDetailConfirmCash").hidden = !(
        pending &&
        kind === "deposit" &&
        method === "cash"
      );

      $("paymentDetailConfirmRefund").hidden = !(pending && kind === "refund");

      if (host) {
        host.textContent =
          "Payment details are ready. Confirm cash or refund actions only after they have actually been completed.";
      }
    } catch (error) {
      console.error("Unable to load payment details.", {
        status: error?.status,
      });

      if (host) {
        host.textContent =
          "We couldn’t load this payment. Please return to Payments and try again.";
      }

      showAlert(Api.userMessage(error, "admin"));
    }
  }

  /* ----------------------------------------------------------
     PAGE REFRESH AND EVENTS
     ---------------------------------------------------------- */

  async function refresh() {
    clearAlert();

    if (current === "index.html") {
      await Promise.all([loadBookings(), loadTables(), loadPayments()]);
    } else if (current === "bookings.html") {
      await loadBookings();
    } else if (current === "tables.html") {
      await loadTables();
    } else if (current === "payments.html") {
      await loadPayments();
    } else if (current === "booking-details.html") {
      await loadBookingDetails();
    } else if (current === "payment-details.html") {
      await loadPaymentDetails();
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    renderSidebar();

    $("refreshAdmin")?.addEventListener("click", refresh);

    $("bookingSearch")?.addEventListener("input", renderBookings);

    $("bookingStatusFilter")?.addEventListener("change", renderBookings);

    $("bookingsTable")?.addEventListener("click", (event) => {
      const button = event.target.closest("[data-cancel]");

      if (button) {
        cancelBooking(button.getAttribute("data-cancel"));
      }
    });

    $("paymentsTable")?.addEventListener("click", (event) => {
      const button = event.target.closest("[data-payment-action]");

      if (button) {
        runPaymentAction(
          button.getAttribute("data-payment-action"),
          button.getAttribute("data-payment-id"),
        );
      }
    });

    $("bookingDetailsForm")?.addEventListener("submit", saveBookingDetails);

    $("detailCancelButton")?.addEventListener(
      "click",
      cancelBookingFromDetails,
    );

    $("paymentDetailConfirmCash")?.addEventListener("click", () => {
      runPaymentAction(
        "confirm-cash",
        new URLSearchParams(window.location.search).get("id"),
      );
    });

    $("paymentDetailConfirmRefund")?.addEventListener("click", () => {
      runPaymentAction(
        "confirm-refund",
        new URLSearchParams(window.location.search).get("id"),
      );
    });

    refresh();
  });
})();
