(() => {
  const script = document.currentScript;
  const sessionKey = script?.dataset.sessionKey;
  const csrfToken = script?.dataset.csrfToken;
  if (!sessionKey || !csrfToken) return;

  const inactivityLimit = 10 * 60 * 1000;
  const activityStorageKey = `sixOriginsLastActivity:${sessionKey}`;
  let lastActivity = Date.now();
  let lastServerTouch = 0;
  let timeoutId;

  function redirectToLogin() {
    window.location.replace('logout.php?reason=timeout');
  }

  function readStoredActivity() {
    try {
      const storedActivity = Number(window.localStorage.getItem(activityStorageKey));
      if (Number.isFinite(storedActivity) && storedActivity <= Date.now()) {
        return storedActivity;
      }
    } catch (error) {
      console.warn('Six Origins could not synchronize session activity between tabs.', error);
    }
    return 0;
  }

  function scheduleTimeout() {
    window.clearTimeout(timeoutId);
    const remaining = inactivityLimit - (Date.now() - lastActivity);
    if (remaining <= 0) {
      redirectToLogin();
      return;
    }
    timeoutId = window.setTimeout(scheduleTimeout, remaining + 25);
  }

  function touchServer() {
    const now = Date.now();
    if (now - lastServerTouch < 5000) return;
    lastServerTouch = now;

    const body = new URLSearchParams({
      action: 'touch_activity',
      csrf_token: csrfToken
    });
    fetch('account_session_security.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      cache: 'no-store',
      keepalive: true,
      body
    }).then(response => {
      if (response.status === 401) redirectToLogin();
    }).catch(error => {
      console.error('Could not update session activity.', error);
    });
  }

  function recordActivity(timestamp = Date.now(), synchronizeTabs = true) {
    lastActivity = timestamp;
    if (synchronizeTabs) {
      try {
        window.localStorage.setItem(activityStorageKey, String(timestamp));
      } catch (error) {
        console.warn('Six Origins could not synchronize session activity between tabs.', error);
      }
    }
    scheduleTimeout();
    touchServer();
  }

  const storedActivity = readStoredActivity();
  if (storedActivity > 0) {
    lastActivity = storedActivity;
  } else {
    try {
      window.localStorage.setItem(activityStorageKey, String(lastActivity));
    } catch (error) {
      console.warn('Six Origins could not synchronize session activity between tabs.', error);
    }
  }
  scheduleTimeout();

  ['pointerdown', 'keydown', 'touchstart', 'scroll', 'input'].forEach(eventName => {
    document.addEventListener(eventName, () => recordActivity(), { passive: true });
  });

  window.addEventListener('storage', event => {
    if (event.key !== activityStorageKey) return;
    const activityTimestamp = Number(event.newValue);
    if (Number.isFinite(activityTimestamp) && activityTimestamp > lastActivity && activityTimestamp <= Date.now()) {
      recordActivity(activityTimestamp, false);
    }
  });
})();
