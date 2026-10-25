(() => {
  const apiBase = window.APP_CONFIG.apiBase;

  function requestContext(url) {
    const path = String(url || "").toLowerCase();

    if (path.includes("/users/login")) return "login";
    if (path.includes("/users/register")) return "register";
    if (path.includes("guest-otp")) return "otp";
    if (path.includes("/payments")) return "payment";
    if (path.includes("/bookings")) return "booking";
    if (path.includes("/tables")) return "tables";

    return "general";
  }

  function userMessage(error, context = "general") {
    const status = Number(error?.status || 0);

    // Use the original server message only to map known cases
    // to a suitable customer-facing message.
    const raw = String(
      error?.serverMessage || error?.body?.message || error?.message || "",
    );

    const message = raw.toLowerCase();

    if (
      context === "login" &&
      (status === 401 ||
        /invalid credentials|incorrect password|invalid email or password/.test(
          message,
        ))
    ) {
      return "The email address or password is incorrect. Please try again.";
    }

    if (context === "register" && status === 409) {
      return "An account with these details may already exist. Try signing in or use a different email address or username.";
    }

    if (
      /already booked|already reserved|booked during the selected time|time slot.*unavailable/.test(
        message,
      )
    ) {
      return "That table is no longer available at your selected time. Please choose another table or time.";
    }

    if (
      /exceeds table capacity|table.*capacity|capacity.*exceed/.test(message)
    ) {
      return "This table cannot accommodate your group. Please choose a larger table or reduce the number of guests.";
    }

    if (
      /verify your email|email.*not verified|verification.*required/.test(
        message,
      )
    ) {
      return "Please verify your email address before continuing.";
    }

    if (/incorrect verification code|incorrect code/.test(message)) {
      return "That code doesn't match. Check the email and try again.";
    }

    if (
      /code has expired|verification code has expired|no active verification code/.test(
        message,
      )
    ) {
      return "This verification code has expired. Please request a new one.";
    }

    if (
      /too many incorrect attempts|too many requests|wait 60 seconds/.test(
        message,
      ) ||
      status === 429
    ) {
      return "Too many attempts in a short time. Please wait a little and try again.";
    }

    if (
      /cancelled booking|cancelled reservation|cannot pay for a cancelled/.test(
        message,
      )
    ) {
      return "This reservation has been cancelled and can no longer be paid.";
    }

    if (
      /cannot pay for this booking|not authorized|unauthorized/.test(message)
    ) {
      if (context === "payment") {
        return "We couldn't verify access to this reservation. Please return to your booking and try again in the same browser.";
      }

      return "You don't have permission to complete this action.";
    }

    if (context === "admin" && (status === 401 || status === 403)) {
      return "Please sign in with an administrator account to access this area.";
    }

    if (status === 401) {
      if (context === "payment") {
        return "We couldn't verify this reservation. Please return to the booking page and try again.";
      }

      return "Your session may have expired. Please sign in again and try once more.";
    }

    if (status === 403) {
      return "You don't have permission to complete this action. Please check your account and try again.";
    }

    if (status === 404) {
      if (context === "tables") {
        return "We couldn't find the table information. Please refresh the page and try again.";
      }

      if (context === "payment") {
        return "We couldn't find this payment. Please refresh the page and try again.";
      }

      return "We couldn't find that reservation. Please return to the previous page and try again.";
    }

    if (/invalid email address/.test(message)) {
      return "Please enter a valid email address.";
    }

    if (/invalid guest contact|guest contact details/.test(message)) {
      return "Please check your name and contact details, then try again.";
    }

    if (
      /invalid booking details|invalid booking|date-time|time range/.test(
        message,
      )
    ) {
      return "Please check your reservation date and time, then try again.";
    }

    if (
      /selected table does not exist|table could not be found/.test(message)
    ) {
      return "We couldn't find that table. Please select another table and try again.";
    }

    if (/payment.*invalid|deposit is invalid/.test(message)) {
      return "We couldn't prepare the deposit for this table. Please contact the restaurant for help.";
    }

    if (status === 409) {
      if (context === "payment") {
        return "We couldn't complete this deposit step. Please refresh the page and try again.";
      }

      if (context === "booking") {
        return "We couldn't complete this reservation. Please check your choices or try another time.";
      }

      return "This action couldn't be completed. Please review the details and try again.";
    }

    if (status === 400 || status === 422) {
      return "Please check the information you've entered and try again.";
    }

    if (
      status >= 500 ||
      status === 0 ||
      /internal server error|database|pdo|sql|api error|endpoint|request failed|invalid json|missing required field|must be an integer|method not allowed|unsupported action/.test(
        message,
      )
    ) {
      if (context === "tables") {
        return "We couldn't load the tables right now. Please refresh the page or try again shortly.";
      }

      if (context === "booking") {
        return "We couldn't complete your reservation right now. Please try again shortly.";
      }

      if (context === "payment") {
        return "We couldn't complete this deposit step right now. Please try again shortly.";
      }

      return "Something went wrong. Please try again shortly.";
    }

    // Pass through only short, ordinary messages without technical wording.
    if (
      raw &&
      raw.length <= 160 &&
      !/\b(api|endpoint|session|database|pdo|sql|json|request body|http|stack trace|warning|fatal error|exception|field:|user id|booking id|payment id)\b/i.test(
        raw,
      )
    ) {
      return raw;
    }

    return "Something went wrong. Please try again shortly.";
  }

  async function request(url, options = {}) {
    let response;

    try {
      response = await fetch(url, {
        credentials: "include",
        ...options,
        headers: {
          Accept: "application/json",
          ...(options.body ? { "Content-Type": "application/json" } : {}),
          ...(options.headers || {}),
        },
      });
    } catch (cause) {
      const error = new Error(
        "We couldn't connect right now. Check your connection and try again.",
      );

      error.status = 0;
      error.cause = cause;

      throw error;
    }

    const raw = await response.text();
    let body;

    try {
      body = raw ? JSON.parse(raw) : {};
    } catch {
      body = {
        success: false,
        message: "The request could not be completed.",
      };
    }

    if (!response.ok || body.success === false) {
      const error = new Error(
        body.message || "The request could not be completed.",
      );

      error.status = response.status;
      error.body = body;
      error.serverMessage = String(body.message || "");
      error.message = userMessage(error, requestContext(url));

      throw error;
    }

    return body;
  }

  function endpoint(route) {
    return `${apiBase}${route.startsWith("/") ? route : `/${route}`}`;
  }

  function getArray(payload) {
    const candidates = [
      payload?.data,
      payload?.data?.items,
      payload?.data?.tables,
      payload?.data?.bookings,
      payload?.data?.payments,
      payload?.items,
      payload?.tables,
    ];

    return candidates.find(Array.isArray) || [];
  }

  function pick(object, keys, fallback = undefined) {
    if (!object || typeof object !== "object") {
      return fallback;
    }

    for (const key of keys) {
      if (object[key] !== undefined && object[key] !== null) {
        return object[key];
      }

      const found = Object.keys(object).find(
        (existingKey) => existingKey.toLowerCase() === key.toLowerCase(),
      );

      if (found && object[found] !== undefined && object[found] !== null) {
        return object[found];
      }
    }

    return fallback;
  }

  function normalizeTable(row) {
    const id = pick(row, ["tableId", "TableID", "id", "Id"], null);

    const tableNumber = pick(
      row,
      ["tableNumber", "TableNumber", "number", "Number"],
      null,
    );

    const capacityValue = pick(
      row,
      ["maxCapacity", "MaxCapacity", "capacity", "Capacity", "seatingCapacity"],
      null,
    );

    const priceValue = pick(
      row,
      ["bookingPrice", "BookingPrice", "bookingFee", "price", "Price"],
      null,
    );

    const depositValue = pick(
      row,
      ["depositAmount", "DepositAmount", "deposit", "Deposit"],
      null,
    );

    return {
      id: id === null ? null : Number(id),

      number:
        tableNumber === null ? `ID ${id ?? "unknown"}` : String(tableNumber),

      name:
        tableNumber === null
          ? `Table ID ${id ?? "unknown"}`
          : `Table ${tableNumber}`,

      capacity: capacityValue === null ? null : Number(capacityValue),

      bookingPrice: priceValue === null ? null : Number(priceValue),

      deposit: depositValue === null ? null : Number(depositValue),

      view: String(
        pick(
          row,
          ["viewType", "ViewType", "view", "View", "tableType", "TableType"],
          "",
        ),
      ),

      status: String(pick(row, ["status", "Status"], "Unknown")),

      raw: row,
    };
  }

  function normalizeBooking(row) {
    return {
      id: pick(row, ["bookingId", "BookingID", "id", "Id"], "—"),

      tableId: pick(row, ["tableId", "TableID"], "—"),

      userId: pick(row, ["userId", "UserID"], null),

      status: pick(row, ["status", "Status"], "Unknown"),

      individuals: pick(
        row,
        ["numberOfIndividuals", "NumberOfIndividuals"],
        "—",
      ),

      children: pick(row, ["numberOfChildren", "NumberOfChildren"], 0),

      start: pick(row, ["startDateTime", "StartDateTime"], ""),

      end: pick(row, ["endDateTime", "EndDateTime"], ""),

      guestName: pick(row, ["guestName", "GuestName"], ""),

      guestEmail: pick(row, ["guestEmail", "GuestEmail"], ""),

      raw: row,
    };
  }

  window.Api = {
    request,
    userMessage,

    get: (route) => request(endpoint(route)),

    post: (route, data) =>
      request(endpoint(route), {
        method: "POST",
        body: JSON.stringify(data),
      }),

    put: (route, data) =>
      request(endpoint(route), {
        method: "PUT",
        body: JSON.stringify(data),
      }),

    otp: (data) =>
      request(window.APP_CONFIG.otpUrl, {
        method: "POST",
        body: JSON.stringify(data),
      }),

    endpoint,
    getArray,
    pick,
    normalizeTable,
    normalizeBooking,

    money(value) {
      if (value === null || value === undefined || value === "") {
        return "—";
      }

      const amount = Number(value);

      if (!Number.isFinite(amount)) {
        return "—";
      }

      return new Intl.NumberFormat("en-JO", {
        style: "currency",
        currency: "JOD",
        minimumFractionDigits: 2,
      }).format(amount);
    },

    formatDate(value) {
      if (!value) {
        return "—";
      }

      const date = new Date(String(value).replace(" ", "T"));

      if (Number.isNaN(date.getTime())) {
        return String(value);
      }

      return date.toLocaleString("en-GB", {
        dateStyle: "medium",
        timeStyle: "short",
      });
    },

    escape(value) {
      return String(value ?? "").replace(
        /[&<>"']/g,
        (char) =>
          ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;",
          })[char],
      );
    },
  };
})();
