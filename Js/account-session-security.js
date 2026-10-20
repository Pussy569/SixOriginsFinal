(() => {
  const script = document.currentScript;
  if (!script) return;

  const csrfToken = script.dataset.csrfToken || '';
  const pendingLogin = script.dataset.pendingLogin === 'true';
  const endpoint = 'account_session_security.php';

  async function request(action, values = {}) {
    const body = new URLSearchParams({ action, csrf_token: csrfToken, ...values });
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      cache: 'no-store',
      body
    });
    const result = await response.json();
    return { response, result };
  }

  const style = document.createElement('style');
  style.textContent = `
    .account-security-overlay{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(28,20,18,.7)}
    .account-security-card{width:min(100%,460px);padding:28px;border-radius:18px;background:#fffaf5;color:#421b13;box-shadow:0 18px 60px rgba(0,0,0,.3);font:500 16px/1.55 Montserrat,Arial,sans-serif}
    .account-security-card h2{margin:0 0 12px;font-size:1.35rem}
    .account-security-card p{margin:0 0 20px;color:#664c47}
    .account-security-actions{display:flex;gap:12px;flex-wrap:wrap}
    .account-security-actions button{flex:1;min-width:140px;padding:12px 16px;border:0;border-radius:10px;font:700 15px Montserrat,Arial,sans-serif;cursor:pointer}
    .account-security-approve{background:#2d5a3d;color:#fff}
    .account-security-reject{background:#f0e6d8;color:#421b13}
    .account-security-actions button:disabled{opacity:.6;cursor:wait}
  `;
  document.head.append(style);

  function showOverlay(title, message, actions = []) {
    let overlay = document.querySelector('.account-security-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'account-security-overlay';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      document.body.append(overlay);
    }

    const card = document.createElement('section');
    card.className = 'account-security-card';
    const heading = document.createElement('h2');
    heading.textContent = title;
    const description = document.createElement('p');
    description.textContent = message;
    card.append(heading, description);

    if (actions.length > 0) {
      const actionContainer = document.createElement('div');
      actionContainer.className = 'account-security-actions';
      actions.forEach(({ label, className, handler }) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = label;
        button.addEventListener('click', async () => {
          actionContainer.querySelectorAll('button').forEach(item => { item.disabled = true; });
          try {
            await handler(button);
          } catch (error) {
            console.error('Could not process the sign-in approval.', error);
            actionContainer.querySelectorAll('button').forEach(item => { item.disabled = false; });
          }
        });
        actionContainer.append(button);
      });
      card.append(actionContainer);
    }

    overlay.replaceChildren(card);
    return overlay;
  }

  let activeRequestId = null;
  let activeModal = null;
  let activePollInProgress = false;

  async function pollActiveSession() {
    if (activePollInProgress || document.visibilityState === 'hidden') return;
    activePollInProgress = true;
    try {
      const { result } = await request('poll_active');
      if (result.status === 'revoked') {
        window.location.href = 'login.php?session=revoked';
        return;
      }
      if (result.status !== 'pending' || !result.request_id) {
        if (activeModal) {
          activeModal.remove();
          activeModal = null;
          activeRequestId = null;
        }
        return;
      }
      if (activeRequestId === result.request_id) return;

      activeRequestId = result.request_id;
      activeModal = showOverlay(
        'Is this you?',
        'A sign-in to your account was requested on another device. If you approve, this device will be signed out and the new sign-in will continue.',
        [
          {
            label: "Yes, it's me",
            className: 'account-security-approve',
            handler: async () => {
              const { result: answer } = await request('approve', { request_id: activeRequestId });
              if (answer.status === 'approved') {
                showOverlay('Sign-in approved', 'This device is being signed out for your security.');
                window.location.href = 'login.php?session=replaced';
              } else {
                activeModal?.remove();
                activeModal = null;
                activeRequestId = null;
              }
            }
          },
          {
            label: "No, it wasn't me",
            className: 'account-security-reject',
            handler: async () => {
              await request('reject', { request_id: activeRequestId });
              activeModal?.remove();
              activeModal = null;
              activeRequestId = null;
            }
          }
        ]
      );
    } catch (error) {
      console.error('Could not check account sign-in requests.', error);
    } finally {
      activePollInProgress = false;
    }
  }

  if (csrfToken) {
    pollActiveSession();
    window.setInterval(pollActiveSession, 10000);
  }

  if (!pendingLogin) return;

  showOverlay(
    'Waiting for approval',
    'Approve this sign-in from the device that is already signed in. For your security, this request expires after 60 seconds.'
  );

  let pendingPollInProgress = false;
  async function pollPendingLogin() {
    if (pendingPollInProgress) return;
    pendingPollInProgress = true;
    try {
      const { result } = await request('poll_pending');
      if (result.status === 'approved' && result.redirect) {
        window.location.href = result.redirect;
        return;
      }
      if (['rejected', 'expired', 'revoked', 'none'].includes(result.status)) {
        const message = result.status === 'rejected'
          ? 'The sign-in was rejected.'
          : 'The approval request expired. Please sign in again.';
        showOverlay('Sign-in not approved', message);
        window.setTimeout(() => { window.location.href = 'login.php?session=denied'; }, 2500);
      }
    } catch (error) {
      console.error('Could not check sign-in approval status.', error);
    } finally {
      pendingPollInProgress = false;
    }
  }

  pollPendingLogin();
  window.setInterval(pollPendingLogin, 3000);
})();
