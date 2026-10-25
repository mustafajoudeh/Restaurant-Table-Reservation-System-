/* Project paths are derived from the /frontend/ folder, so the project can stay under XAMPP htdocs. */
(() => {
  const decodedPath = decodeURIComponent(window.location.pathname);
  const marker = "/frontend/";
  const markerIndex = decodedPath.toLowerCase().indexOf(marker);
  const projectRoot = markerIndex >= 0 ? decodedPath.slice(0, markerIndex) : "";
  const encodedRoot = encodeURI(projectRoot).replace(/'/g, "%27");

  window.APP_CONFIG = {
    projectRoot: encodedRoot,
    apiBase: `${window.location.origin}${encodedRoot}/api/index.php`,
    otpUrl: `${window.location.origin}${encodedRoot}/api/guest-otp.php`,
    logoutUrl: `${window.location.origin}${encodedRoot}/api/logout.php`,
    currency: "JOD",
    reservationDurationMinutes: 60,
  };
})();
