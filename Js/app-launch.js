(() => {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('./service-worker.js').catch((error) => {
        console.error('Six Origins app service worker could not be registered.', error);
      });
    }, { once: true });
  }

  const launchScreen = document.querySelector('.pwa-launch-screen');
  if (!launchScreen || !document.documentElement.classList.contains('pwa-launch')) return;

  const startedAt = performance.now();
  let dismissed = false;
  const dismissLaunchScreen = () => {
    if (dismissed) return;
    dismissed = true;
    const visibleFor = performance.now() - startedAt;
    window.setTimeout(() => {
      launchScreen.classList.add('is-leaving');
      window.setTimeout(() => {
        launchScreen.remove();
        document.documentElement.classList.remove('pwa-launch');
      }, 500);
    }, Math.max(0, 950 - visibleFor));
  };

  window.addEventListener('load', dismissLaunchScreen, { once: true });
  window.setTimeout(dismissLaunchScreen, 8000);
})();
