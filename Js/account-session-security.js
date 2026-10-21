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
    .account-security-email{background:#C6453E;color:#fff}
    .account-security-actions button:disabled{opacity:.6;cursor:wait}
    .account-security-code{width:100%;margin:0 0 16px;padding:13px 14px;border:1px solid #d8c9b8;border-radius:10px;font:600 20px Montserrat,Arial,sans-serif;letter-spacing:.25em;text-align:center}
    .account-security-status{min-height:24px;font-size:14px}
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

  function showEmailCodeForm() {
    const overlay = showOverlay(
      'Check your email',
      'Enter the six-digit code we sent to the email address on your account. The code expires in 10 minutes.'
    );
    const card = overlay.querySelector('.account-security-card');
    const input = document.createElement('input');
    input.className = 'account-security-code';
    input.type = 'text';
    input.inputMode = 'numeric';
    input.autocomplete = 'one-time-code';
    input.maxLength = 6;
    input.pattern = '[0-9]{6}';
    input.setAttribute('aria-label', 'Six-digit email code');
    input.placeholder = '000000';

    const status = document.createElement('p');
    status.className = 'account-security-status';
    status.setAttribute('aria-live', 'polite');

    const actions = document.createElement('div');
    actions.className = 'account-security-actions';
    const resend = document.createElement('button');
    resend.type = 'button';
    resend.className = 'account-security-reject';
    resend.textContent = 'Resend code';
    resend.addEventListener('click', async () => {
      resend.disabled = true;
      status.textContent = 'Sending another code…';
      try {
        const { response, result } = await request('send_email_code');
        status.textContent = response.ok && result.status === 'sent'
          ? 'A new code has been sent.'
          : result.message || result.error || 'Could not resend the code.';
      } catch (error) {
        console.error('Could not resend the email sign-in code.', error);
        status.textContent = 'Could not resend the code. Check your connection and try again.';
      } finally {
        window.setTimeout(() => { resend.disabled = false; }, 30000);
      }
    });
    const verify = document.createElement('button');
    verify.type = 'button';
    verify.className = 'account-security-email';
    verify.textContent = 'Verify code';
    verify.addEventListener('click', async () => {
      verify.disabled = true;
      status.textContent = 'Verifying code…';
      try {
        const { response, result } = await request('verify_email_code', { code: input.value.trim() });
        if (result.status === 'approved') {
          status.textContent = 'Code accepted. Signing you in…';
          await pollPendingLogin();
        } else if (['expired', 'locked', 'revoked'].includes(result.status)) {
          showOverlay('Sign-in not completed', result.message || 'Please sign in again.', [
            { label: 'Back to login', className: 'account-security-reject', handler: async () => { window.location.href = 'login.php?session=denied'; } }
          ]);
        } else {
          status.textContent = result.message || (response.ok ? 'Unable to verify the code.' : 'Unable to verify the code. Please try again.');
          input.value = '';
          input.focus();
          verify.disabled = false;
        }
      } catch (error) {
        console.error('Could not verify the email sign-in code.', error);
        status.textContent = 'Could not verify the code. Check your connection and try again.';
        verify.disabled = false;
      }
    });
    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'account-security-reject';
    back.textContent = 'Back to login';
    back.addEventListener('click', () => { window.location.href = 'login.php?session=denied'; });
    actions.append(resend, verify, back);
    card.append(input, status, actions);
    input.focus();
  }

  function requestEmailCode() {
    showOverlay(
      'Sending a code',
      'We are sending a confirmation code to the email address on your account.'
    );
    request('send_email_code').then(({ response, result }) => {
      if (response.ok && result.status === 'sent') {
        showEmailCodeForm();
        return;
      }
      const message = result.message || result.error || 'We could not send the code. Please try again.';
      showOverlay('Email code unavailable', message, [
        { label: 'Try again', className: 'account-security-email', handler: requestEmailCode },
        { label: 'Back to login', className: 'account-security-reject', handler: async () => { window.location.href = 'login.php?session=denied'; } }
      ]);
    }).catch(error => {
      console.error('Could not send the email sign-in code.', error);
      showOverlay('Email code unavailable', 'Could not send the code. Check your connection and try again.', [
        { label: 'Try again', className: 'account-security-email', handler: requestEmailCode },
        { label: 'Back to login', className: 'account-security-reject', handler: async () => { window.location.href = 'login.php?session=denied'; } }
      ]);
    });
  }

  showOverlay(
    'Waiting for approval',
    'Approve this sign-in from the device that is already signed in, or confirm your identity by email.',
    [
      { label: 'Use email code instead', className: 'account-security-email', handler: requestEmailCode }
    ]
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
